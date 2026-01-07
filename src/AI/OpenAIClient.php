<?php
/**
 * OpenAI API client wrapper.
 *
 * @package AgentWP
 */

namespace AgentWP\AI;

use AgentWP\AI\Functions\FunctionSchema;
use AgentWP\Billing\UsageTracker;

class OpenAIClient {
	const API_BASE = 'https://api.openai.com/v1';

	/**
	 * @var string
	 */
	private $api_key;

	/**
	 * @var string
	 */
	private $model;

	/**
	 * @var int
	 */
	private $timeout;

	/**
	 * @var bool
	 */
	private $stream;

	/**
	 * @var callable|null
	 */
	private $on_stream;

	/**
	 * @var TokenCounter
	 */
	private $token_counter;

	/**
	 * @var int
	 */
	private $max_retries;

	/**
	 * @var int
	 */
	private $initial_delay;

	/**
	 * @var int
	 */
	private $max_delay;

	/**
	 * @var string
	 */
	private $base_url;

	/**
	 * @var string
	 */
	private $intent_type;

	/**
	 * @param string $api_key OpenAI API key.
	 * @param string $model Model name.
	 * @param array  $options Optional overrides.
	 */
	public function __construct( $api_key, $model = Model::GPT_4O_MINI, array $options = array() ) {
		$this->api_key       = is_string( $api_key ) ? $api_key : '';
		$this->model         = Model::normalize( $model );
		$this->timeout       = isset( $options['timeout'] ) ? (int) $options['timeout'] : 60;
		$this->stream        = ! empty( $options['stream'] );
		$this->on_stream     = isset( $options['on_stream'] ) && is_callable( $options['on_stream'] ) ? $options['on_stream'] : null;
		$this->token_counter = isset( $options['token_counter'] ) && $options['token_counter'] instanceof TokenCounter
			? $options['token_counter']
			: new TokenCounter();
		$this->max_retries   = isset( $options['max_retries'] ) ? (int) $options['max_retries'] : 10;
		$this->initial_delay = isset( $options['initial_delay'] ) ? (int) $options['initial_delay'] : 1;
		$this->max_delay     = isset( $options['max_delay'] ) ? (int) $options['max_delay'] : 60;
		$this->base_url      = isset( $options['base_url'] ) && is_string( $options['base_url'] )
			? rtrim( $options['base_url'], '/' )
			: self::API_BASE;
		$this->intent_type   = isset( $options['intent_type'] ) ? sanitize_text_field( $options['intent_type'] ) : '';
	}

	/**
	 * Send a chat completion request.
	 *
	 * @param array $messages Chat messages.
	 * @param array $functions Tool definitions or FunctionSchema instances.
	 * @return Response
	 */
	public function chat( array $messages, array $functions ) {
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
					'retries' => $result['retries'],
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
					'retries' => $result['retries'],
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
	public function validateKey( $key ) {
		$key = is_string( $key ) ? $key : '';

		if ( '' === $key ) {
			return false;
		}

		$response = wp_remote_get(
			$this->base_url . '/models',
			array(
				'timeout'     => 3,
				'redirection' => 0,
				'headers'     => array(
					'Authorization' => 'Bearer ' . $key,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		return 200 === (int) wp_remote_retrieve_response_code( $response );
	}

	/**
	 * @param array $payload Request payload.
	 * @return array
	 */
	private function request_with_retry( array $payload ) {
		$attempt = 0;
		$delay   = $this->initial_delay;
		$errors  = '';

		do {
			$result = $this->send_request( $payload );
			$retry  = $result['retryable'];

			if ( $result['success'] || ! $retry || $attempt >= $this->max_retries ) {
				$errors = $result['error'];
				break;
			}

			$retry_after = $result['retry_after'];
			$this->sleep_with_backoff( $delay, $retry_after );
			$delay = min( $delay * 2, $this->max_delay );
			$attempt++;
		} while ( true );

		$result['retries'] = $attempt;
		$result['error']   = $errors;

		return $result;
	}

	/**
	 * @param array $payload Request payload.
	 * @return array
	 */
	private function send_request( array $payload ) {
		$args = array(
			'timeout'     => $this->timeout,
			'redirection' => 0,
			'headers'     => array(
				'Authorization' => 'Bearer ' . $this->api_key,
				'Content-Type'  => 'application/json',
			),
			'body'        => wp_json_encode( $payload ),
		);

		$response = wp_remote_post( $this->base_url . '/chat/completions', $args );
		if ( is_wp_error( $response ) ) {
			return array(
				'success'     => false,
				'status'      => 0,
				'body'        => '',
				'headers'     => array(),
				'error'       => $response->get_error_message(),
				'retryable'   => $this->is_retryable_error( $response ),
				'retry_after' => 0,
			);
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$body    = wp_remote_retrieve_body( $response );
		$headers = wp_remote_retrieve_headers( $response );
		$error   = '';
		$header_retry = 0;

		if ( $headers && isset( $headers['retry-after'] ) ) {
			$header_retry = (int) $headers['retry-after'];
		}

		if ( $status < 200 || $status >= 300 ) {
			$error = 'OpenAI API request failed.';
			$decoded = json_decode( $body, true );
			if ( is_array( $decoded ) && isset( $decoded['error']['message'] ) ) {
				$error = $decoded['error']['message'];
			}
		}

		return array(
			'success'     => $status >= 200 && $status < 300,
			'status'      => $status,
			'body'        => is_string( $body ) ? $body : '',
			'headers'     => is_array( $headers ) ? $headers : array(),
			'error'       => $error,
			'retryable'   => $this->is_retryable_status( $status ),
			'retry_after' => $header_retry,
		);
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
	 * @param int $status HTTP status.
	 * @return bool
	 */
	private function is_retryable_status( $status ) {
		return 429 === $status || ( $status >= 500 && $status < 600 );
	}

	/**
	 * @param \WP_Error $error Error instance.
	 * @return bool
	 */
	private function is_retryable_error( $error ) {
		$code    = $error->get_error_code();
		$message = strtolower( $error->get_error_message() );

		if ( false !== strpos( $message, 'timed out' ) || false !== strpos( $message, 'timeout' ) ) {
			return true;
		}

		return in_array( $code, array( 'http_request_failed', 'connection_failed' ), true );
	}

	/**
	 * @param int $delay Base delay.
	 * @param int $retry_after Retry-After header.
	 * @return void
	 */
	private function sleep_with_backoff( $delay, $retry_after ) {
		$base = (int) $delay;

		if ( $retry_after > $base ) {
			$base = (int) $retry_after;
		}

		$base   = min( $base, $this->max_delay );
		$jitter = mt_rand( 0, 1000 ) / 1000;
		$sleep  = $base + $jitter;

		usleep( (int) round( $sleep * 1000000 ) );
	}

	/**
	 * @param string $body Response body.
	 * @return array
	 */
	private function parse_response_body( $body ) {
		$payload = json_decode( $body, true );
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

		$message    = isset( $payload['choices'][0]['message'] ) && is_array( $payload['choices'][0]['message'] )
			? $payload['choices'][0]['message']
			: array();
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
			$chunk   = json_decode( $payload, true );

			if ( ! is_array( $chunk ) ) {
				continue;
			}

			$raw[] = $chunk;
			if ( is_callable( $this->on_stream ) ) {
				call_user_func( $this->on_stream, $chunk );
			}

			if ( isset( $chunk['model'] ) ) {
				$model = $chunk['model'];
			}

			if ( isset( $chunk['usage'] ) && is_array( $chunk['usage'] ) ) {
				$usage = $chunk['usage'];
			}

			$choice = isset( $chunk['choices'][0] ) ? $chunk['choices'][0] : array();
			$delta  = isset( $choice['delta'] ) && is_array( $choice['delta'] ) ? $choice['delta'] : array();

			if ( isset( $delta['content'] ) ) {
				$content .= $delta['content'];
			}

			if ( isset( $delta['tool_calls'] ) && is_array( $delta['tool_calls'] ) ) {
				$tool_calls = $this->merge_tool_call_deltas( $tool_calls, $delta['tool_calls'] );
			}

			if ( isset( $delta['function_call'] ) && is_array( $delta['function_call'] ) ) {
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

				if ( isset( $delta['function']['arguments'] ) ) {
					$tool_calls[ $index ]['function']['arguments'] .= $delta['function']['arguments'];
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
