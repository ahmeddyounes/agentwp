<?php
/**
 * OpenAI client unit tests.
 */

namespace AgentWP\Tests\Unit\AI;

use AgentWP\AI\OpenAIClient;
use AgentWP\AI\Functions\FunctionSchema;
use AgentWP\AI\TokenCounter;
use AgentWP\DTO\HttpResponse;
use AgentWP\Retry\ExponentialBackoffPolicy;
use AgentWP\Tests\Fakes\FakeHttpClient;
use AgentWP\Tests\Fakes\FakeSleeper;
use AgentWP\Tests\TestCase;

class OpenAIClientTest extends TestCase {

	private FakeHttpClient $http;
	private FakeSleeper $sleeper;

	public function setUp(): void {
		parent::setUp();
		$this->http    = new FakeHttpClient();
		$this->sleeper = new FakeSleeper();
	}

	public function tearDown(): void {
		$this->http->reset();
		$this->sleeper->reset();
		parent::tearDown();
	}

	private function fixture( $name ) {
		return file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/openai/' . $name );
	}

	private function stub_token_counter() {
		return new class() extends TokenCounter {
			public function count_request_tokens( array $messages = array(), array $tools = array(), $model = '' ) {
				return 4;
			}
			public function count_text_tokens( $text = '', $model = '' ) {
				return 2;
			}
		};
	}

	public function test_chat_missing_key_returns_error(): void {
		$client   = new OpenAIClient( $this->http, '' );
		$response = $client->chat( array(), array() );

		$this->assertFalse( $response->is_success() );
		$this->assertSame( 401, $response->get_status() );
	}

	public function test_chat_success_with_tool_normalization(): void {
		$fixture = $this->fixture( 'chat-success.json' );

		$this->http->queueSuccess( $fixture, 200 );

		$client = new OpenAIClient(
			$this->http,
			'key',
			'gpt-4o-mini',
			array(
				'max_retries'   => 0,
				'token_counter' => $this->stub_token_counter(),
			)
		);

		$functions = array(
			new class() implements FunctionSchema {
				public function get_name() {
					return 'tool_one';
				}
				public function get_description() {
					return 'Tool one';
				}
				public function get_parameters() {
					return array();
				}
				public function to_tool_definition() {
					return array(
						'type'     => 'function',
						'function' => array(
							'name'        => 'tool_one',
							'description' => 'Tool one',
							'parameters'  => array(),
						),
					);
				}
			},
			new class() {
				public function to_tool_definition() {
					return array(
						'type'     => 'function',
						'function' => array(
							'name'        => 'tool_two',
							'description' => 'Tool two',
							'parameters'  => array(),
						),
					);
				}
			},
			array(
				'type'     => 'function',
				'function' => array(
					'name'       => 'tool_three',
					'parameters' => array(),
				),
			),
			array(
				'name'       => 'tool_four',
				'parameters' => array(),
			),
			'invalid',
		);

		$response = $client->chat(
			array(
				array( 'role' => 'user', 'content' => 'Hi' ),
			),
			$functions
		);

		$this->assertTrue( $response->is_success() );
		$data = $response->get_data();
		$this->assertSame( 'Hello from AgentWP.', $data['content'] );
		$this->assertNotEmpty( $data['tool_calls'] );
		$this->assertSame( 'openai', $response->get_meta()['usage_source'] );

		// Verify request was made to correct URL.
		$lastRequest = $this->http->getLastRequest();
		$this->assertNotNull( $lastRequest );
		$this->assertSame( 'POST', $lastRequest['method'] );
		$this->assertStringContainsString( '/chat/completions', $lastRequest['url'] );
	}

	public function test_chat_estimates_usage_when_missing(): void {
		$fixture = $this->fixture( 'chat-success-no-usage.json' );

		$this->http->queueSuccess( $fixture, 200 );

		$client = new OpenAIClient(
			$this->http,
			'key',
			'gpt-4o-mini',
			array(
				'max_retries'   => 0,
				'token_counter' => new class() extends TokenCounter {
					public function count_request_tokens( array $messages = array(), array $tools = array(), $model = '' ) {
						return 10;
					}
					public function count_text_tokens( $text = '', $model = '' ) {
						return 5;
					}
				},
			)
		);

		$response = $client->chat(
			array(
				array( 'role' => 'user', 'content' => 'Hi' ),
			),
			array()
		);

		$this->assertTrue( $response->is_success() );
		$meta = $response->get_meta();
		$this->assertSame( 'tiktoken', $meta['usage_source'] );
		$this->assertSame( 15, $meta['total_tokens'] );
	}

	public function test_chat_estimates_usage_for_tool_calls(): void {
		$fixture = $this->fixture( 'chat-success-no-usage-tools.json' );

		$this->http->queueSuccess( $fixture, 200 );

		$client = new OpenAIClient(
			$this->http,
			'key',
			'gpt-4o-mini',
			array(
				'max_retries'   => 0,
				'token_counter' => $this->stub_token_counter(),
			)
		);

		$response = $client->chat(
			array(
				array( 'role' => 'user', 'content' => 'Hi' ),
			),
			array()
		);

		$this->assertTrue( $response->is_success() );
		$this->assertSame( 'tiktoken', $response->get_meta()['usage_source'] );
		$this->assertNotEmpty( $response->get_data()['tool_calls'] );
	}

	public function test_chat_parses_legacy_function_calls(): void {
		$fixture = $this->fixture( 'chat-legacy.json' );

		$this->http->queueSuccess( $fixture, 200 );

		$client   = new OpenAIClient(
			$this->http,
			'key',
			'gpt-4o-mini',
			array(
				'max_retries'   => 0,
				'token_counter' => $this->stub_token_counter(),
			)
		);
		$response = $client->chat( array( array( 'role' => 'user', 'content' => 'Hi' ) ), array() );

		$this->assertTrue( $response->is_success() );
		$this->assertSame( 'legacy', $response->get_data()['tool_calls'][0]['id'] );
	}

	public function test_chat_handles_invalid_json_response(): void {
		$this->http->queueSuccess( 'not-json', 200 );

		$client   = new OpenAIClient(
			$this->http,
			'key',
			'gpt-4o-mini',
			array(
				'max_retries'   => 0,
				'token_counter' => $this->stub_token_counter(),
			)
		);
		$response = $client->chat( array( array( 'role' => 'user', 'content' => 'Hi' ) ), array() );

		$this->assertFalse( $response->is_success() );
		$this->assertSame( 'Invalid response from OpenAI.', $response->get_message() );
	}

	public function test_chat_streams_response(): void {
		$fixture = $this->fixture( 'chat-stream.txt' );
		$chunks  = array();

		$this->http->queueSuccess( $fixture, 200 );

		$client = new OpenAIClient(
			$this->http,
			'key',
			'gpt-4o-mini',
			array(
				'stream'        => true,
				'on_stream'     => function ( $chunk ) use ( &$chunks ) {
					$chunks[] = $chunk;
				},
				'max_retries'   => 0,
				'token_counter' => $this->stub_token_counter(),
			)
		);

		$response = $client->chat(
			array(
				array( 'role' => 'user', 'content' => 'Hi' ),
			),
			array()
		);

		$this->assertTrue( $response->is_success() );
		$this->assertSame( 'Hello world', $response->get_data()['content'] );
		$this->assertNotEmpty( $response->get_data()['tool_calls'] );
		$this->assertNotEmpty( $chunks );
	}

	public function test_parse_stream_response_handles_legacy_function_call(): void {
		$client = new OpenAIClient(
			$this->http,
			'key',
			'gpt-4o-mini',
			array(
				'token_counter' => $this->stub_token_counter(),
			)
		);

		$reflection = new \ReflectionMethod( OpenAIClient::class, 'parse_stream_response' );
		$reflection->setAccessible( true );

		$body = 'data: {"choices":[{"delta":{"function_call":{"name":"draft_email","arguments":"{}"}}}]}';
		$parsed = $reflection->invoke( $client, $body );

		$this->assertTrue( $parsed['success'] );
		$this->assertNotEmpty( $parsed['tool_calls'] );
	}

	public function test_chat_retries_on_retryable_status(): void {
		$error_fixture = $this->fixture( 'chat-error.json' );
		$ok_fixture    = $this->fixture( 'chat-success.json' );

		// Queue a 500 error followed by a success.
		$this->http->queueResponse(
			new HttpResponse( success: false, statusCode: 500, body: $error_fixture )
		);
		$this->http->queueSuccess( $ok_fixture, 200 );

		$client   = new OpenAIClient(
			$this->http,
			'key',
			'gpt-4o-mini',
			array(
				'max_retries'   => 1,
				'token_counter' => $this->stub_token_counter(),
			),
			null,
			$this->sleeper
		);
		$response = $client->chat( array( array( 'role' => 'user', 'content' => 'Hi' ) ), array() );

		$this->assertTrue( $response->is_success() );
		$this->assertSame( 1, $response->get_meta()['retries'] );
		$this->assertSame( 2, $this->http->getRequestCount() );
	}

	public function test_chat_handles_network_error(): void {
		$this->http->queueError( 'Operation timed out.', 'http_request_failed', 0 );

		$client   = new OpenAIClient(
			$this->http,
			'key',
			'gpt-4o-mini',
			array(
				'max_retries'   => 0,
				'token_counter' => $this->stub_token_counter(),
			)
		);
		$response = $client->chat( array( array( 'role' => 'user', 'content' => 'Hi' ) ), array() );

		$this->assertFalse( $response->is_success() );
		$this->assertSame( 0, $response->get_status() );
	}

	public function test_validate_key_success(): void {
		$this->http->queueSuccess( '{}', 200 );

		$client = new OpenAIClient(
			$this->http,
			'key',
			'gpt-4o-mini',
			array( 'base_url' => 'https://example.com' )
		);

		$this->assertTrue( $client->validateKey( 'key' ) );

		// Verify request was made to /models endpoint.
		$lastRequest = $this->http->getLastRequest();
		$this->assertNotNull( $lastRequest );
		$this->assertSame( 'GET', $lastRequest['method'] );
		$this->assertStringContainsString( '/models', $lastRequest['url'] );
	}

	public function test_validate_key_returns_false_for_empty_key(): void {
		$client = new OpenAIClient(
			$this->http,
			'key',
			'gpt-4o-mini',
			array( 'base_url' => 'https://example.com' )
		);

		$this->assertFalse( $client->validateKey( '' ) );
	}

	public function test_validate_key_handles_errors(): void {
		$this->http->queueError( 'fail', 'http_request_failed', 0 );

		$client = new OpenAIClient(
			$this->http,
			'key',
			'gpt-4o-mini',
			array( 'base_url' => 'https://example.com' )
		);

		$this->assertFalse( $client->validateKey( 'key' ) );
	}

	public function test_validate_key_handles_non_200_status(): void {
		$this->http->queueSuccess( '{"error": "unauthorized"}', 401 );

		$client = new OpenAIClient(
			$this->http,
			'key',
			'gpt-4o-mini',
			array( 'base_url' => 'https://example.com' )
		);

		$this->assertFalse( $client->validateKey( 'invalid_key' ) );
	}

	public function test_chat_uses_centralized_retry_executor(): void {
		$error_fixture = $this->fixture( 'chat-error.json' );
		$ok_fixture    = $this->fixture( 'chat-success.json' );

		// Queue 429 rate limit followed by success.
		$this->http->queueResponse(
			new HttpResponse(
				success: false,
				statusCode: 429,
				body: $error_fixture,
				headers: array( 'retry-after' => '2' )
			)
		);
		$this->http->queueSuccess( $ok_fixture, 200 );

		$client = new OpenAIClient(
			$this->http,
			'key',
			'gpt-4o-mini',
			array(
				'max_retries'   => 3,
				'token_counter' => $this->stub_token_counter(),
			),
			null, // Use default retry policy.
			$this->sleeper // Inject fake sleeper for testing.
		);

		$response = $client->chat(
			array( array( 'role' => 'user', 'content' => 'Hi' ) ),
			array()
		);

		$this->assertTrue( $response->is_success() );
		$this->assertSame( 1, $response->get_meta()['retries'] );
		$this->assertSame( 2, $this->http->getRequestCount() );

		// Verify sleep was called with retry-after value (2 seconds = 2000ms).
		$this->assertSame( 1, $this->sleeper->getSleepCount() );
		$this->assertSame( 2000, $this->sleeper->getLastSleep() );
	}

	public function test_chat_retries_on_429_with_exponential_backoff(): void {
		$error_fixture = $this->fixture( 'chat-error.json' );
		$ok_fixture    = $this->fixture( 'chat-success.json' );

		// Queue multiple 429 errors followed by success.
		$this->http->queueResponse(
			new HttpResponse( success: false, statusCode: 429, body: $error_fixture )
		);
		$this->http->queueResponse(
			new HttpResponse( success: false, statusCode: 429, body: $error_fixture )
		);
		$this->http->queueSuccess( $ok_fixture, 200 );

		$policy = new ExponentialBackoffPolicy(
			maxRetries: 3,
			baseDelayMs: 1000,
			maxDelayMs: 30000,
			jitterFactor: 0.0 // No jitter for predictable testing.
		);

		$client = new OpenAIClient(
			$this->http,
			'key',
			'gpt-4o-mini',
			array( 'token_counter' => $this->stub_token_counter() ),
			$policy,
			$this->sleeper
		);

		$response = $client->chat(
			array( array( 'role' => 'user', 'content' => 'Hi' ) ),
			array()
		);

		$this->assertTrue( $response->is_success() );
		$this->assertSame( 2, $response->get_meta()['retries'] );
		$this->assertSame( 3, $this->http->getRequestCount() );

		// Verify exponential backoff: 1000ms, 2000ms.
		$sleepLog = $this->sleeper->getSleepLog();
		$this->assertCount( 2, $sleepLog );
		$this->assertSame( 1000, $sleepLog[0] );
		$this->assertSame( 2000, $sleepLog[1] );
	}

	public function test_chat_does_not_retry_on_400_bad_request(): void {
		$this->http->queueResponse(
			new HttpResponse(
				success: false,
				statusCode: 400,
				body: '{"error": {"message": "Bad request", "type": "invalid_request_error"}}'
			)
		);

		$client = new OpenAIClient(
			$this->http,
			'key',
			'gpt-4o-mini',
			array(
				'max_retries'   => 3,
				'token_counter' => $this->stub_token_counter(),
			),
			null,
			$this->sleeper
		);

		$response = $client->chat(
			array( array( 'role' => 'user', 'content' => 'Hi' ) ),
			array()
		);

		$this->assertFalse( $response->is_success() );
		$this->assertSame( 400, $response->get_status() );
		// Only 1 attempt, no retries for 400 errors.
		$this->assertSame( 1, $this->http->getRequestCount() );
		$this->assertSame( 0, $this->sleeper->getSleepCount() );
	}

	public function test_chat_retries_on_network_timeout(): void {
		$ok_fixture = $this->fixture( 'chat-success.json' );

		// Queue network error followed by success.
		$this->http->queueError( 'Connection timed out', 'http_request_failed', 0 );
		$this->http->queueSuccess( $ok_fixture, 200 );

		$client = new OpenAIClient(
			$this->http,
			'key',
			'gpt-4o-mini',
			array(
				'max_retries'   => 3,
				'token_counter' => $this->stub_token_counter(),
			),
			null,
			$this->sleeper
		);

		$response = $client->chat(
			array( array( 'role' => 'user', 'content' => 'Hi' ) ),
			array()
		);

		$this->assertTrue( $response->is_success() );
		$this->assertSame( 1, $response->get_meta()['retries'] );
	}

	public function test_chat_accepts_custom_retry_policy(): void {
		$ok_fixture = $this->fixture( 'chat-success.json' );

		$this->http->queueResponse(
			new HttpResponse( success: false, statusCode: 503, body: '{"error": "unavailable"}' )
		);
		$this->http->queueSuccess( $ok_fixture, 200 );

		// Create custom conservative policy.
		$policy = ExponentialBackoffPolicy::conservative();

		$client = new OpenAIClient(
			$this->http,
			'key',
			'gpt-4o-mini',
			array( 'token_counter' => $this->stub_token_counter() ),
			$policy,
			$this->sleeper
		);

		$response = $client->chat(
			array( array( 'role' => 'user', 'content' => 'Hi' ) ),
			array()
		);

		$this->assertTrue( $response->is_success() );
		$this->assertSame( 1, $response->get_meta()['retries'] );
	}

	public function test_chat_exhausts_retries_and_returns_last_error(): void {
		$error_fixture = $this->fixture( 'chat-error.json' );

		// Queue only 429 errors (more than max_retries).
		$this->http->queueResponse(
			new HttpResponse( success: false, statusCode: 429, body: $error_fixture )
		);
		$this->http->queueResponse(
			new HttpResponse( success: false, statusCode: 429, body: $error_fixture )
		);

		$client = new OpenAIClient(
			$this->http,
			'key',
			'gpt-4o-mini',
			array(
				'max_retries'   => 1, // Only 1 retry allowed.
				'token_counter' => $this->stub_token_counter(),
			),
			null,
			$this->sleeper
		);

		$response = $client->chat(
			array( array( 'role' => 'user', 'content' => 'Hi' ) ),
			array()
		);

		$this->assertFalse( $response->is_success() );
		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 1, $response->get_meta()['retries'] );
		// 2 attempts total: initial + 1 retry.
		$this->assertSame( 2, $this->http->getRequestCount() );
	}
}
