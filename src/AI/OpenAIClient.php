<?php
/**
 * OpenAI API client wrapper.
 *
 * @package AgentWP
 */

namespace AgentWP\AI;

use AgentWP\AI\Functions\FunctionSchema;
use AgentWP\Billing\UsageTracker;
use AgentWP\Contracts\HttpClientInterface;
use AgentWP\Contracts\OpenAIClientInterface;
use AgentWP\Contracts\RetryPolicyInterface;
use AgentWP\Contracts\SleeperInterface;
use AgentWP\DTO\HttpResponse;
use AgentWP\Infrastructure\RealSleeper;
use AgentWP\Retry\ExponentialBackoffPolicy;
use AgentWP\Retry\RetryExecutor;

class OpenAIClient implements OpenAIClientInterface {
	const API_BASE = 'https://api.openai.com/v1';

	/**
	 * Maximum content length for stream responses (1MB).
	 * Prevents memory exhaustion from malicious or malfunctioning streams.
	 */
	const MAX_STREAM_CONTENT_LENGTH = 1048576;

	/**
	 * Maximum number of tool calls in a stream response.
	 */
	const MAX_STREAM_TOOL_CALLS = 50;

	/**
	 * Maximum number of raw chunks to store.
	 */
	const MAX_STREAM_RAW_CHUNKS = 100;

	/**
	 * Maximum length for tool call arguments (100KB).
	 */
	const MAX_TOOL_ARGUMENTS_LENGTH = 102400;

	private HttpClientInterface $http_client;
	private string $api_key;
	private string $model;
	private int $timeout;
	private bool $stream;
	/** @var callable|null */
	private $on_stream;
	private TokenCounter $token_counter;
	private RetryExecutor $retry_executor;
	private string $base_url;
	private string $intent_type;

	/**
	 * @param HttpClientInterface       $http_client HTTP client for making requests.
	 * @param string                    $api_key OpenAI API key.
	 * @param string                    $model Model name.
	 * @param array                     $options Optional overrides.
	 * @param RetryPolicyInterface|null $retry_policy Optional custom retry policy.
	 * @param SleeperInterface|null     $sleeper Optional custom sleeper for testing.
	 */
	public function __construct(
		HttpClientInterface $http_client,
		$api_key,
		$model = Model::GPT_4O_MINI,
		array $options = array(),
		?RetryPolicyInterface $retry_policy = null,
		?SleeperInterface $sleeper = null
	) {
		$this->http_client   = $http_client;
		$this->api_key       = is_string( $api_key ) ? $api_key : '';
		$this->model         = Model::normalize( $model );
		// Enforce timeout bounds: minimum 1 second, maximum 300 seconds.
		$this->timeout       = isset( $options['timeout'] ) ? min( max( 1, (int) $options['timeout'] ), 300 ) : 60;
		$this->stream        = ! empty( $options['stream'] );
		$this->on_stream     = isset( $options['on_stream'] ) && is_callable( $options['on_stream'] ) ? $options['on_stream'] : null;
		$this->token_counter = isset( $options['token_counter'] ) && $options['token_counter'] instanceof TokenCounter
			? $options['token_counter']
			: new TokenCounter();
		$this->base_url      = isset( $options['base_url'] ) && is_string( $options['base_url'] )
			? rtrim( $options['base_url'], '/' )
			: self::API_BASE;
		$this->intent_type   = isset( $options['intent_type'] ) ? sanitize_text_field( $options['intent_type'] ) : '';

		// Build retry executor with injected or default dependencies.
		$max_retries = isset( $options['max_retries'] ) ? (int) $options['max_retries'] : null;
		$this->retry_executor = $this->buildRetryExecutor( $retry_policy, $sleeper, $max_retries );
	}

	/**
	 * Build RetryExecutor with provided or default dependencies.
	 *
	 * @param RetryPolicyInterface|null $retry_policy Custom retry policy.
	 * @param SleeperInterface|null     $sleeper Custom sleeper.
	 * @param int|null                  $max_retries Max retries override.
	 * @return RetryExecutor
	 */
	private function buildRetryExecutor(
		?RetryPolicyInterface $retry_policy,
		?SleeperInterface $sleeper,
		?int $max_retries
	): RetryExecutor {
		// Use provided policy or create default OpenAI policy.
		if ( null === $retry_policy ) {
			// Create policy with max_retries override if provided.
			if ( null !== $max_retries ) {
				$retry_policy = new ExponentialBackoffPolicy(
					maxRetries: $max_retries,
					baseDelayMs: 1000,
					maxDelayMs: 30000,
					jitterFactor: 0.25,
					retryableStatusCodes: array( 429, 500, 502, 503, 504, 520, 521, 522, 524 )
				);
			} else {
				$retry_policy = ExponentialBackoffPolicy::forOpenAI();
			}
		}

		// Use provided sleeper or create real one.
		if ( null === $sleeper ) {
			$sleeper = new RealSleeper();
		}

		return new RetryExecutor( $retry_policy, $sleeper );
	}

	/**
	 * Send a chat completion request.
	 *
	 * @param array $messages Chat messages.
	 * @param array $functions Tool definitions or FunctionSchema instances.
	 * @return Response
	 */
	public function chat( array $messages, array $functions ): Response {
		if ( '' === $this->api_key ) {
			return Response::error( 'Missing OpenAI API key.', 401 );
		}

		$tools = $this->normalize_tools( $functions );

		$payload = array(
			'model'    => $this->model,
			'messages' => array_values( $messages ),
		);

		if ( ! empty( $tools ) ) {
			$payload['tools']       = $tools;
			$payload['tool_choice'] = 'auto';
		}

		if ( $this->stream ) {
			$payload['stream']         = true;
			$payload['stream_options'] = array( 'include_usage' => true );
		}

		$input_tokens = $this->token_counter->count_request_tokens( $messages, $tools, $this->model );

		$result = $this->request_with_retry( $payload );
		if ( ! $result['success'] ) {
			return Response::error(
				$result['error'],
				$result['status'],
				array(
					'retries'     => $result['retries'],
					'error_code'  => $result['error_code'],
					'error_type'  => $result['error_type'],
					'retry_after' => $result['retry_after'],
				)
			);
		}

		if ( $this->stream ) {
			$parsed = $this->parse_stream_response( $result['body'] );
		} else {
			$parsed = $this->parse_response_body( $result['body'] );
		}

		if ( ! $parsed['success'] ) {
			return Response::error(
				$parsed['error'],
				$result['status'],
				array(
					'retries'     => $result['retries'],
					'error_code'  => $result['error_code'],
					'error_type'  => $result['error_type'],
					'retry_after' => $result['retry_after'],
				)
			);
		}

		$usage         = $parsed['usage'];
		$usage_fallback = null;

		if ( empty( $usage ) ) {
			$usage_fallback = $this->estimate_usage( $input_tokens, $parsed );
			$usage          = $usage_fallback;
		}

		$meta = array(
			'model'         => $parsed['model'] ? $parsed['model'] : $this->model,
			'input_tokens'  => isset( $usage['prompt_tokens'] ) ? (int) $usage['prompt_tokens'] : $input_tokens,
			'output_tokens' => isset( $usage['completion_tokens'] ) ? (int) $usage['completion_tokens'] : 0,
			'total_tokens'  => isset( $usage['total_tokens'] ) ? (int) $usage['total_tokens'] : 0,
			'usage'         => $usage,
			'usage_source'  => $usage_fallback ? 'tiktoken' : 'openai',
			'retries'       => $result['retries'],
			'stream'        => $this->stream,
		);

		if ( class_exists( 'AgentWP\\Billing\\UsageTracker' ) ) {
			UsageTracker::log_usage(
				$meta['model'],
				$meta['input_tokens'],
				$meta['output_tokens'],
				$this->intent_type
			);
		}

		return Response::success(
			array(
				'content'    => $parsed['content'],
				'tool_calls' => $parsed['tool_calls'],
				'raw'        => $parsed['raw'],
			),
			$meta
		);
	}

	/**
	 * Validate an OpenAI API key.
	 *
	 * @param string $key API key.
	 * @return bool
	 */
	public function validateKey( string $key ): bool {
		if ( '' === $key ) {
			return false;
		}

		$options = array(
			'timeout'     => 3,
			'redirection' => 0,
			'headers'     => array(
				'Authorization' => 'Bearer ' . $key,
			),
		);

		$response = $this->http_client->get( $this->base_url . '/models', $options );

		return $response->success && 200 === $response->statusCode;
	}

	/**
	 * Execute request with centralized retry logic via RetryExecutor.
	 *
	 * @param array $payload Request payload.
	 * @return array
	 */
	private function request_with_retry( array $payload ) {
		$retries     = 0;
		$lastResult  = null;

		// Track retries via onRetry callback.
		$this->retry_executor->onRetry(
			function ( $attempt, $delayMs, $result ) use ( &$retries ) {
				$retries = $attempt + 1;
			}
		);

		// Use executeWithCheck to get final HttpResponse regardless of success/failure.
		$response = $this->retry_executor->executeWithCheck(
			fn() => $this->send_request_raw( $payload ),
			fn( HttpResponse $r ) => $r->success
		);

		// Parse the final HttpResponse into expected result format.
		$result = $this->parse_http_response( $response );
		$result['retries'] = $retries;

		return $result;
	}

	/**
	 * Send raw HTTP request and return HttpResponse directly.
	 * Used by RetryExecutor for retry handling.
	 *
	 * @param array $payload Request payload.
	 * @return HttpResponse
	 */
	private function send_request_raw( array $payload ): HttpResponse {
		$body = wp_json_encode( $payload );

		if ( false === $body ) {
			return HttpResponse::error(
				'Failed to encode request payload as JSON',
				'json_encode_error',
				0
			);
		}

		$options = array(
			'timeout'     => $this->timeout,
			'redirection' => 0,
			'sslverify'   => true,
			'headers'     => array(
				'Authorization' => 'Bearer ' . $this->api_key,
				'Content-Type'  => 'application/json',
			),
			'body'        => $body,
		);

		return $this->http_client->post( $this->base_url . '/chat/completions', $options );
	}

	/**
	 * Parse HttpResponse into the internal result format.
	 *
	 * @param HttpResponse $response HTTP response.
	 * @return array
	 */
	private function parse_http_response( HttpResponse $response ): array {
		// Handle network/connection errors (status 0).
		if ( ! $response->success && 0 === $response->statusCode ) {
			return array(
				'success'     => false,
				'status'      => 0,
				'body'        => '',
				'headers'     => array(),
				'error'       => $response->error ?? 'Request failed',
				'error_code'  => $response->errorCode ?? '',
				'error_type'  => '',
				'retryable'   => $response->isRetryable(),
				'retry_after' => 0,
			);
		}

		$status       = $response->statusCode;
		$body         = $response->body;
		$headers      = $this->normalize_response_headers( $response->headers );
		$error        = '';
		$error_code   = '';
		$error_type   = '';
		$header_retry = $response->getRetryAfter() ?? 0;

		if ( $status < 200 || $status >= 300 ) {
			$error   = 'OpenAI API request failed.';
			// Limit JSON decode depth to prevent deeply nested attack payloads.
			$decoded = json_decode( $body, true, 32 );
			// Check for JSON parsing errors to handle malformed responses (e.g., HTML error pages).
			if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) && isset( $decoded['error'] ) && is_array( $decoded['error'] ) ) {
				if ( isset( $decoded['error']['message'] ) ) {
					// Sanitize error message to prevent API key exposure.
					$error = $this->sanitize_error_message( $decoded['error']['message'] );
				}
				$error_code = isset( $decoded['error']['code'] ) ? (string) $decoded['error']['code'] : '';
				$error_type = isset( $decoded['error']['type'] ) ? (string) $decoded['error']['type'] : '';
			}
		}

		return array(
			'success'     => $status >= 200 && $status < 300,
			'status'      => $status,
			'body'        => $body,
			'headers'     => $headers,
			'error'       => $error,
			'error_code'  => $error_code,
			'error_type'  => $error_type,
			'retryable'   => $response->isRetryable(),
			'retry_after' => $header_retry,
		);
	}

		/**
		 * Normalize WordPress response headers to a plain array.
		 *
		 * @param mixed $headers Header data from wp_remote_retrieve_headers().
		 * @return array<string, mixed>
		 */
		private function normalize_response_headers( $headers ): array {
			if ( $headers instanceof \Requests_Utility_CaseInsensitiveDictionary ) {
				$headers = $headers->getAll();
			}

			if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
				$headers = $headers->getAll();
			}

			if ( ! is_array( $headers ) ) {
				return array();
			}

			$normalized = array();
			foreach ( $headers as $key => $value ) {
				$header_key = is_string( $key ) ? strtolower( $key ) : (string) $key;
				$normalized[ $header_key ] = $value;
			}

			return $normalized;
		}

		/**
		 * @param array $functions Function definitions.
		 * @return array
		 */
	private function normalize_tools( array $functions ) {
		$tools = array();

		foreach ( $functions as $function ) {
			if ( $function instanceof FunctionSchema ) {
				$tools[] = $function->to_tool_definition();
				continue;
			}

			if ( is_object( $function ) && method_exists( $function, 'to_tool_definition' ) ) {
				$tools[] = $function->to_tool_definition();
				continue;
			}

			if ( ! is_array( $function ) ) {
				continue;
			}

			if ( isset( $function['type'] ) && 'function' === $function['type'] ) {
				$tool = $function;
				if ( isset( $tool['function'] ) && is_array( $tool['function'] ) ) {
					$tool['function']['strict'] = true;
				}
				$tools[] = $tool;
				continue;
			}

			if ( isset( $function['name'] ) ) {
				$tool = array(
					'type'     => 'function',
					'function' => $function,
				);
				$tool['function']['strict'] = true;
				$tools[]                   = $tool;
			}
		}

		return $tools;
	}

	/**
	 * Sanitize error message to prevent API key exposure.
	 *
	 * @param string $message Raw error message.
	 * @return string Sanitized message.
	 */
	private function sanitize_error_message( $message ) {
		if ( ! is_string( $message ) ) {
			return 'Unknown error';
		}

		// Remove potential API key patterns (sk-..., sk-proj-...).
		$sanitized = preg_replace( '/\bsk-[a-zA-Z0-9_-]{20,}\b/', '[REDACTED]', $message );

		// Remove Bearer token references.
		$sanitized = preg_replace( '/Bearer\s+[a-zA-Z0-9_-]+/i', 'Bearer [REDACTED]', $sanitized );

		return $sanitized;
	}

	/**
	 * @param string $body Response body.
	 * @return array
	 */
	private function parse_response_body( $body ) {
		$payload = json_decode( $body, true, 64 );
		if ( ! is_array( $payload ) ) {
			return array(
				'success'    => false,
				'error'      => 'Invalid response from OpenAI.',
				'content'    => '',
				'tool_calls' => array(),
				'usage'      => array(),
				'raw'        => array(),
				'model'      => '',
			);
		}

		$message = array();
		if ( isset( $payload['choices'] ) && is_array( $payload['choices'] )
			&& isset( $payload['choices'][0] ) && is_array( $payload['choices'][0] )
			&& isset( $payload['choices'][0]['message'] ) && is_array( $payload['choices'][0]['message'] ) ) {
			$message = $payload['choices'][0]['message'];
		}
		$content    = isset( $message['content'] ) ? $message['content'] : '';
		$tool_calls = isset( $message['tool_calls'] ) && is_array( $message['tool_calls'] ) ? $message['tool_calls'] : array();

		if ( empty( $tool_calls ) && isset( $message['function_call'] ) && is_array( $message['function_call'] ) ) {
			$tool_calls = array(
				array(
					'id'       => 'legacy',
					'type'     => 'function',
					'function' => $message['function_call'],
				),
			);
		}

		return array(
			'success'    => true,
			'error'      => '',
			'content'    => is_string( $content ) ? $content : '',
			'tool_calls' => $tool_calls,
			'usage'      => isset( $payload['usage'] ) && is_array( $payload['usage'] ) ? $payload['usage'] : array(),
			'raw'        => $payload,
			'model'      => isset( $payload['model'] ) ? $payload['model'] : '',
		);
	}

	/**
	 * @param string $body Streaming response body.
	 * @return array
	 */
	private function parse_stream_response( $body ) {
		$lines      = preg_split( "/\r\n|\n|\r/", $body );
		$content    = '';
		$tool_calls = array();
		$usage      = array();
		$raw        = array();
		$model      = '';

		// Track limits to prevent memory exhaustion.
		$content_length    = 0;
		$content_truncated = false;
		$tools_truncated   = false;

		if ( ! is_array( $lines ) ) {
			$lines = array();
		}

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line || 'data: [DONE]' === $line ) {
				continue;
			}

			if ( 0 !== strpos( $line, 'data:' ) ) {
				continue;
			}

			$payload = trim( substr( $line, 5 ) );
			$chunk   = json_decode( $payload, true, 32 );

			if ( ! is_array( $chunk ) ) {
				continue;
			}

			// Limit raw chunks to prevent unbounded memory growth.
			if ( count( $raw ) < self::MAX_STREAM_RAW_CHUNKS ) {
				$raw[] = $chunk;
			}
			if ( is_callable( $this->on_stream ) ) {
				call_user_func( $this->on_stream, $chunk );
			}

			if ( isset( $chunk['model'] ) ) {
				$model = $chunk['model'];
			}

			if ( isset( $chunk['usage'] ) && is_array( $chunk['usage'] ) ) {
				$usage = $chunk['usage'];
			}

			$choice = ( isset( $chunk['choices'] ) && is_array( $chunk['choices'] ) && isset( $chunk['choices'][0] ) )
				? $chunk['choices'][0]
				: array();
			$delta  = isset( $choice['delta'] ) && is_array( $choice['delta'] ) ? $choice['delta'] : array();

			// Accumulate content with length limit to prevent memory exhaustion.
			if ( isset( $delta['content'] ) && ! $content_truncated ) {
				$delta_content = $delta['content'];
				$delta_length  = strlen( $delta_content );

				if ( $content_length + $delta_length > self::MAX_STREAM_CONTENT_LENGTH ) {
					// Truncate to stay within limit.
					$remaining         = self::MAX_STREAM_CONTENT_LENGTH - $content_length;
					$content          .= substr( $delta_content, 0, $remaining );
					$content_length    = self::MAX_STREAM_CONTENT_LENGTH;
					$content_truncated = true;
				} else {
					$content        .= $delta_content;
					$content_length += $delta_length;
				}
			}

			// Accumulate tool calls with count limit.
			if ( isset( $delta['tool_calls'] ) && is_array( $delta['tool_calls'] ) && ! $tools_truncated ) {
				$tool_calls = $this->merge_tool_call_deltas( $tool_calls, $delta['tool_calls'] );
				if ( count( $tool_calls ) > self::MAX_STREAM_TOOL_CALLS ) {
					// Truncate to limit.
					$tool_calls      = array_slice( $tool_calls, 0, self::MAX_STREAM_TOOL_CALLS, true );
					$tools_truncated = true;
				}
			}

			if ( isset( $delta['function_call'] ) && is_array( $delta['function_call'] ) && ! $tools_truncated ) {
				$tool_calls = $this->merge_tool_call_deltas(
					$tool_calls,
					array(
						array(
							'index'    => 0,
							'type'     => 'function',
							'function' => $delta['function_call'],
						),
					)
				);
				if ( count( $tool_calls ) > self::MAX_STREAM_TOOL_CALLS ) {
					$tool_calls      = array_slice( $tool_calls, 0, self::MAX_STREAM_TOOL_CALLS, true );
					$tools_truncated = true;
				}
			}
		}

		return array(
			'success'    => true,
			'error'      => '',
			'content'    => $content,
			'tool_calls' => array_values( $tool_calls ),
			'usage'      => $usage,
			'raw'        => $raw,
			'model'      => $model,
		);
	}

	/**
	 * @param array $tool_calls Existing tool calls.
	 * @param array $deltas Incoming deltas.
	 * @return array
	 */
	private function merge_tool_call_deltas( array $tool_calls, array $deltas ) {
		foreach ( $deltas as $delta ) {
			$index = isset( $delta['index'] ) ? (int) $delta['index'] : 0;

			if ( ! isset( $tool_calls[ $index ] ) ) {
				$tool_calls[ $index ] = array(
					'id'       => isset( $delta['id'] ) ? $delta['id'] : '',
					'type'     => isset( $delta['type'] ) ? $delta['type'] : 'function',
					'function' => array(
						'name'      => '',
						'arguments' => '',
					),
				);
			}

			if ( isset( $delta['id'] ) ) {
				$tool_calls[ $index ]['id'] = $delta['id'];
			}

			if ( isset( $delta['type'] ) ) {
				$tool_calls[ $index ]['type'] = $delta['type'];
			}

			if ( isset( $delta['function'] ) && is_array( $delta['function'] ) ) {
				if ( isset( $delta['function']['name'] ) ) {
					$tool_calls[ $index ]['function']['name'] = $delta['function']['name'];
				}

				// Accumulate arguments with length limit to prevent memory exhaustion.
				if ( isset( $delta['function']['arguments'] ) ) {
					$current_length = strlen( $tool_calls[ $index ]['function']['arguments'] );
					if ( $current_length < self::MAX_TOOL_ARGUMENTS_LENGTH ) {
						$delta_args = $delta['function']['arguments'];
						$remaining  = self::MAX_TOOL_ARGUMENTS_LENGTH - $current_length;
						if ( strlen( $delta_args ) > $remaining ) {
							$delta_args = substr( $delta_args, 0, $remaining );
						}
						$tool_calls[ $index ]['function']['arguments'] .= $delta_args;
					}
				}
			}
		}

		return $tool_calls;
	}

	/**
	 * @param int   $input_tokens Estimated input tokens.
	 * @param array $parsed Parsed response data.
	 * @return array
	 */
	private function estimate_usage( $input_tokens, array $parsed ) {
		$output_tokens = 0;

		if ( '' !== $parsed['content'] ) {
			$output_tokens += $this->token_counter->count_text_tokens( $parsed['content'], $this->model );
		}

		if ( ! empty( $parsed['tool_calls'] ) ) {
			$payload = wp_json_encode( $parsed['tool_calls'] );
			if ( false !== $payload ) {
				$output_tokens += $this->token_counter->count_text_tokens( $payload, $this->model );
			}
		}

		return array(
			'prompt_tokens'     => (int) $input_tokens,
			'completion_tokens' => (int) $output_tokens,
			'total_tokens'      => (int) ( $input_tokens + $output_tokens ),
		);
	}
}
