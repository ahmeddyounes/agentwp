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

	private function create_client( array $options = array() ): OpenAIClient {
		$defaults = array(
			'max_retries'   => 0,
			'token_counter' => $this->stub_token_counter(),
		);
		return new OpenAIClient(
			$this->http,
			'test-key',
			'gpt-4o-mini',
			array_merge( $defaults, $options )
		);
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

	// ========================================
	// Request Building Tests
	// ========================================

	public function test_request_building_includes_correct_headers(): void {
		$fixture = $this->fixture( 'chat-success.json' );
		$this->http->queueSuccess( $fixture, 200 );

		$client = $this->create_client();
		$client->chat(
			array( array( 'role' => 'user', 'content' => 'test' ) ),
			array()
		);

		$request = $this->http->getLastRequest();
		$this->assertNotNull( $request );
		$this->assertSame( 'Bearer test-key', $request['options']['headers']['Authorization'] );
		$this->assertSame( 'application/json', $request['options']['headers']['Content-Type'] );
	}

	public function test_request_building_includes_model_in_payload(): void {
		$fixture = $this->fixture( 'chat-success.json' );
		$this->http->queueSuccess( $fixture, 200 );

		$client = $this->create_client();
		$client->chat(
			array( array( 'role' => 'user', 'content' => 'test' ) ),
			array()
		);

		$request = $this->http->getLastRequest();
		$body    = json_decode( $request['options']['body'], true );

		$this->assertSame( 'gpt-4o-mini', $body['model'] );
		$this->assertCount( 1, $body['messages'] );
		$this->assertSame( 'user', $body['messages'][0]['role'] );
		$this->assertSame( 'test', $body['messages'][0]['content'] );
	}

	public function test_request_building_includes_tools_when_provided(): void {
		$fixture = $this->fixture( 'chat-success.json' );
		$this->http->queueSuccess( $fixture, 200 );

		$client = $this->create_client();
		$client->chat(
			array( array( 'role' => 'user', 'content' => 'test' ) ),
			array(
				array(
					'name'        => 'search',
					'description' => 'Search',
					'parameters'  => array( 'type' => 'object', 'properties' => array() ),
				),
			)
		);

		$request = $this->http->getLastRequest();
		$body    = json_decode( $request['options']['body'], true );

		$this->assertArrayHasKey( 'tools', $body );
		$this->assertSame( 'auto', $body['tool_choice'] );
		$this->assertCount( 1, $body['tools'] );
	}

	public function test_request_building_omits_tools_when_empty(): void {
		$fixture = $this->fixture( 'chat-success.json' );
		$this->http->queueSuccess( $fixture, 200 );

		$client = $this->create_client();
		$client->chat(
			array( array( 'role' => 'user', 'content' => 'test' ) ),
			array()
		);

		$request = $this->http->getLastRequest();
		$body    = json_decode( $request['options']['body'], true );

		$this->assertArrayNotHasKey( 'tools', $body );
		$this->assertArrayNotHasKey( 'tool_choice', $body );
	}

	public function test_request_building_enables_streaming_when_configured(): void {
		$fixture = $this->fixture( 'chat-stream.txt' );
		$this->http->queueSuccess( $fixture, 200 );

		$client = $this->create_client( array( 'stream' => true ) );
		$client->chat(
			array( array( 'role' => 'user', 'content' => 'test' ) ),
			array()
		);

		$request = $this->http->getLastRequest();
		$body    = json_decode( $request['options']['body'], true );

		$this->assertTrue( $body['stream'] );
		$this->assertArrayHasKey( 'stream_options', $body );
		$this->assertTrue( $body['stream_options']['include_usage'] );
	}

	public function test_request_building_respects_timeout_option(): void {
		$fixture = $this->fixture( 'chat-success.json' );
		$this->http->queueSuccess( $fixture, 200 );

		$client = $this->create_client( array( 'timeout' => 60 ) );
		$client->chat(
			array( array( 'role' => 'user', 'content' => 'test' ) ),
			array()
		);

		$request = $this->http->getLastRequest();
		$this->assertSame( 60, $request['options']['timeout'] );
	}

	public function test_request_building_respects_base_url_option(): void {
		$fixture = $this->fixture( 'chat-success.json' );
		$this->http->queueSuccess( $fixture, 200 );

		$client = $this->create_client( array( 'base_url' => 'https://custom.api.example.com/v1' ) );
		$client->chat(
			array( array( 'role' => 'user', 'content' => 'test' ) ),
			array()
		);

		$request = $this->http->getLastRequest();
		$this->assertStringContainsString( 'https://custom.api.example.com/v1', $request['url'] );
	}

	public function test_request_building_sends_messages_as_indexed_array(): void {
		$fixture = $this->fixture( 'chat-success.json' );
		$this->http->queueSuccess( $fixture, 200 );

		// Use associative keys that should be reindexed.
		$messages = array(
			'first'  => array( 'role' => 'system', 'content' => 'You are a test.' ),
			'second' => array( 'role' => 'user', 'content' => 'Hello' ),
		);

		$client = $this->create_client();
		$client->chat( $messages, array() );

		$request = $this->http->getLastRequest();
		$body    = json_decode( $request['options']['body'], true );

		// Verify messages are re-indexed (no associative keys).
		$this->assertSame( array( 0, 1 ), array_keys( $body['messages'] ) );
	}

	// ========================================
	// Tool Normalization Tests
	// ========================================

	public function test_normalize_tools_adds_strict_mode_to_array_tools(): void {
		$fixture = $this->fixture( 'chat-success.json' );
		$this->http->queueSuccess( $fixture, 200 );

		$client = $this->create_client();
		$client->chat(
			array( array( 'role' => 'user', 'content' => 'test' ) ),
			array(
				array(
					'type'     => 'function',
					'function' => array(
						'name'       => 'search',
						'parameters' => array(),
					),
				),
			)
		);

		$request = $this->http->getLastRequest();
		$body    = json_decode( $request['options']['body'], true );

		$this->assertTrue( $body['tools'][0]['function']['strict'] );
	}

	public function test_normalize_tools_wraps_name_only_format(): void {
		$fixture = $this->fixture( 'chat-success.json' );
		$this->http->queueSuccess( $fixture, 200 );

		$client = $this->create_client();
		$client->chat(
			array( array( 'role' => 'user', 'content' => 'test' ) ),
			array(
				array(
					'name'       => 'my_tool',
					'parameters' => array(),
				),
			)
		);

		$request = $this->http->getLastRequest();
		$body    = json_decode( $request['options']['body'], true );

		$this->assertSame( 'function', $body['tools'][0]['type'] );
		$this->assertSame( 'my_tool', $body['tools'][0]['function']['name'] );
		$this->assertTrue( $body['tools'][0]['function']['strict'] );
	}

	public function test_normalize_tools_handles_mixed_formats(): void {
		$fixture = $this->fixture( 'chat-success.json' );
		$this->http->queueSuccess( $fixture, 200 );

		$mock_schema = new class() implements FunctionSchema {
			public function get_name() {
				return 'schema_tool';
			}
			public function get_description() {
				return 'Schema tool';
			}
			public function get_parameters() {
				return array();
			}
			public function to_tool_definition() {
				return array(
					'type'     => 'function',
					'function' => array(
						'name'       => 'schema_tool',
						'parameters' => array(),
					),
				);
			}
		};

		$client = $this->create_client();
		$client->chat(
			array( array( 'role' => 'user', 'content' => 'test' ) ),
			array(
				$mock_schema, // FunctionSchema.
				array( 'name' => 'simple_tool', 'parameters' => array() ), // Name-only.
				null, // Should be skipped.
				'invalid', // Should be skipped.
				123, // Should be skipped.
			)
		);

		$request = $this->http->getLastRequest();
		$body    = json_decode( $request['options']['body'], true );

		// Only valid tools should be included.
		$this->assertCount( 2, $body['tools'] );
	}

	public function test_normalize_tools_skips_empty_array_entries(): void {
		$fixture = $this->fixture( 'chat-success.json' );
		$this->http->queueSuccess( $fixture, 200 );

		$client = $this->create_client();
		$client->chat(
			array( array( 'role' => 'user', 'content' => 'test' ) ),
			array(
				array(), // Empty array should be skipped.
				array( 'name' => 'valid_tool', 'parameters' => array() ),
			)
		);

		$request = $this->http->getLastRequest();
		$body    = json_decode( $request['options']['body'], true );

		$this->assertCount( 1, $body['tools'] );
		$this->assertSame( 'valid_tool', $body['tools'][0]['function']['name'] );
	}

	// ========================================
	// Response Parsing Tests
	// ========================================

	public function test_parse_response_extracts_content_correctly(): void {
		$fixture = $this->fixture( 'chat-success.json' );
		$this->http->queueSuccess( $fixture, 200 );

		$client   = $this->create_client();
		$response = $client->chat(
			array( array( 'role' => 'user', 'content' => 'test' ) ),
			array()
		);

		$this->assertTrue( $response->is_success() );
		$this->assertSame( 'Hello from AgentWP.', $response->get_data()['content'] );
	}

	public function test_parse_response_extracts_tool_calls_correctly(): void {
		$fixture = $this->fixture( 'chat-success.json' );
		$this->http->queueSuccess( $fixture, 200 );

		$client   = $this->create_client();
		$response = $client->chat(
			array( array( 'role' => 'user', 'content' => 'test' ) ),
			array()
		);

		$tool_calls = $response->get_data()['tool_calls'];
		$this->assertCount( 1, $tool_calls );
		$this->assertSame( 'call_1', $tool_calls[0]['id'] );
		$this->assertSame( 'function', $tool_calls[0]['type'] );
		$this->assertSame( 'search_orders', $tool_calls[0]['function']['name'] );
	}

	public function test_parse_response_extracts_usage_correctly(): void {
		$fixture = $this->fixture( 'chat-success.json' );
		$this->http->queueSuccess( $fixture, 200 );

		$client   = $this->create_client();
		$response = $client->chat(
			array( array( 'role' => 'user', 'content' => 'test' ) ),
			array()
		);

		$meta = $response->get_meta();
		$this->assertSame( 5, $meta['input_tokens'] );
		$this->assertSame( 7, $meta['output_tokens'] );
		$this->assertSame( 12, $meta['total_tokens'] );
		$this->assertSame( 'openai', $meta['usage_source'] );
	}

	public function test_parse_response_handles_empty_choices(): void {
		$response_body = json_encode(
			array(
				'id'      => 'test',
				'model'   => 'gpt-4o-mini',
				'choices' => array(),
			)
		);
		$this->http->queueSuccess( $response_body, 200 );

		$client   = $this->create_client();
		$response = $client->chat(
			array( array( 'role' => 'user', 'content' => 'test' ) ),
			array()
		);

		$this->assertTrue( $response->is_success() );
		$this->assertSame( '', $response->get_data()['content'] );
		$this->assertEmpty( $response->get_data()['tool_calls'] );
	}

	public function test_parse_response_handles_null_content(): void {
		$response_body = json_encode(
			array(
				'id'      => 'test',
				'model'   => 'gpt-4o-mini',
				'choices' => array(
					array(
						'message' => array(
							'role'    => 'assistant',
							'content' => null, // Null content (common with tool calls).
						),
					),
				),
			)
		);
		$this->http->queueSuccess( $response_body, 200 );

		$client   = $this->create_client();
		$response = $client->chat(
			array( array( 'role' => 'user', 'content' => 'test' ) ),
			array()
		);

		$this->assertTrue( $response->is_success() );
		$this->assertSame( '', $response->get_data()['content'] );
	}

	public function test_parse_response_handles_deeply_nested_json(): void {
		// Create a response with moderately deep nesting (but within limits).
		$nested_content = str_repeat( '{"a":', 30 ) . '"value"' . str_repeat( '}', 30 );
		$response_body  = json_encode(
			array(
				'id'      => 'test',
				'model'   => 'gpt-4o-mini',
				'choices' => array(
					array(
						'message' => array(
							'role'    => 'assistant',
							'content' => $nested_content,
						),
					),
				),
			)
		);
		$this->http->queueSuccess( $response_body, 200 );

		$client   = $this->create_client();
		$response = $client->chat(
			array( array( 'role' => 'user', 'content' => 'test' ) ),
			array()
		);

		$this->assertTrue( $response->is_success() );
		$this->assertSame( $nested_content, $response->get_data()['content'] );
	}

	public function test_parse_stream_response_accumulates_content(): void {
		$stream = implode(
			"\n",
			array(
				'data: {"choices":[{"delta":{"content":"Hello "}}]}',
				'data: {"choices":[{"delta":{"content":"World"}}]}',
				'data: [DONE]',
			)
		);
		$this->http->queueSuccess( $stream, 200 );

		$client   = $this->create_client( array( 'stream' => true ) );
		$response = $client->chat(
			array( array( 'role' => 'user', 'content' => 'test' ) ),
			array()
		);

		$this->assertTrue( $response->is_success() );
		$this->assertSame( 'Hello World', $response->get_data()['content'] );
	}

	public function test_parse_stream_response_merges_tool_call_deltas(): void {
		$stream = implode(
			"\n",
			array(
				'data: {"choices":[{"delta":{"tool_calls":[{"index":0,"id":"call_1","type":"function","function":{"name":"search"}}]}}]}',
				'data: {"choices":[{"delta":{"tool_calls":[{"index":0,"function":{"arguments":"{\"q\":"}}]}}]}',
				'data: {"choices":[{"delta":{"tool_calls":[{"index":0,"function":{"arguments":"\"test\"}"}}]}}]}',
				'data: [DONE]',
			)
		);
		$this->http->queueSuccess( $stream, 200 );

		$client   = $this->create_client( array( 'stream' => true ) );
		$response = $client->chat(
			array( array( 'role' => 'user', 'content' => 'test' ) ),
			array()
		);

		$tool_calls = $response->get_data()['tool_calls'];
		$this->assertCount( 1, $tool_calls );
		$this->assertSame( 'call_1', $tool_calls[0]['id'] );
		$this->assertSame( 'search', $tool_calls[0]['function']['name'] );
		$this->assertSame( '{"q":"test"}', $tool_calls[0]['function']['arguments'] );
	}

	public function test_parse_stream_response_handles_empty_lines(): void {
		$stream = implode(
			"\n",
			array(
				'',
				'data: {"choices":[{"delta":{"content":"Test"}}]}',
				'',
				'',
				'data: [DONE]',
				'',
			)
		);
		$this->http->queueSuccess( $stream, 200 );

		$client   = $this->create_client( array( 'stream' => true ) );
		$response = $client->chat(
			array( array( 'role' => 'user', 'content' => 'test' ) ),
			array()
		);

		$this->assertTrue( $response->is_success() );
		$this->assertSame( 'Test', $response->get_data()['content'] );
	}

	public function test_parse_stream_response_ignores_non_data_lines(): void {
		$stream = implode(
			"\n",
			array(
				': comment line',
				'event: message',
				'data: {"choices":[{"delta":{"content":"Test"}}]}',
				'retry: 1000',
				'data: [DONE]',
			)
		);
		$this->http->queueSuccess( $stream, 200 );

		$client   = $this->create_client( array( 'stream' => true ) );
		$response = $client->chat(
			array( array( 'role' => 'user', 'content' => 'test' ) ),
			array()
		);

		$this->assertTrue( $response->is_success() );
		$this->assertSame( 'Test', $response->get_data()['content'] );
	}

	public function test_parse_stream_response_calls_on_stream_callback(): void {
		$stream = implode(
			"\n",
			array(
				'data: {"choices":[{"delta":{"content":"A"}}]}',
				'data: {"choices":[{"delta":{"content":"B"}}]}',
				'data: [DONE]',
			)
		);
		$this->http->queueSuccess( $stream, 200 );

		$chunks  = array();
		$client  = $this->create_client(
			array(
				'stream'    => true,
				'on_stream' => function ( $chunk ) use ( &$chunks ) {
					$chunks[] = $chunk;
				},
			)
		);
		$response = $client->chat(
			array( array( 'role' => 'user', 'content' => 'test' ) ),
			array()
		);

		$this->assertTrue( $response->is_success() );
		$this->assertCount( 2, $chunks );
	}

	// ========================================
	// Error Categorization Tests
	// ========================================

	public function test_error_categorization_extracts_error_message(): void {
		$error_response = json_encode(
			array(
				'error' => array(
					'message' => 'Invalid API key provided.',
					'type'    => 'invalid_request_error',
					'code'    => 'invalid_api_key',
				),
			)
		);
		$this->http->queueResponse(
			new HttpResponse(
				success: false,
				statusCode: 401,
				body: $error_response
			)
		);

		$client   = $this->create_client();
		$response = $client->chat(
			array( array( 'role' => 'user', 'content' => 'test' ) ),
			array()
		);

		$this->assertFalse( $response->is_success() );
		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'Invalid API key provided.', $response->get_message() );
		$this->assertSame( 'invalid_api_key', $response->get_meta()['error_code'] );
		$this->assertSame( 'invalid_request_error', $response->get_meta()['error_type'] );
	}

	public function test_error_categorization_handles_rate_limit_error(): void {
		$error_response = json_encode(
			array(
				'error' => array(
					'message' => 'Rate limit exceeded.',
					'type'    => 'rate_limit_error',
					'code'    => 'rate_limit',
				),
			)
		);
		$this->http->queueResponse(
			new HttpResponse(
				success: false,
				statusCode: 429,
				body: $error_response
			)
		);

		$client   = $this->create_client();
		$response = $client->chat(
			array( array( 'role' => 'user', 'content' => 'test' ) ),
			array()
		);

		$this->assertFalse( $response->is_success() );
		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 'rate_limit_error', $response->get_meta()['error_type'] );
	}

	public function test_error_categorization_handles_server_error(): void {
		$error_response = json_encode(
			array(
				'error' => array(
					'message' => 'Internal server error.',
					'type'    => 'server_error',
					'code'    => 'internal_error',
				),
			)
		);
		$this->http->queueResponse(
			new HttpResponse(
				success: false,
				statusCode: 500,
				body: $error_response
			)
		);

		$client   = $this->create_client();
		$response = $client->chat(
			array( array( 'role' => 'user', 'content' => 'test' ) ),
			array()
		);

		$this->assertFalse( $response->is_success() );
		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'server_error', $response->get_meta()['error_type'] );
	}

	public function test_error_categorization_handles_model_overload(): void {
		$error_response = json_encode(
			array(
				'error' => array(
					'message' => 'The model is currently overloaded.',
					'type'    => 'server_error',
					'code'    => 'model_overloaded',
				),
			)
		);
		$this->http->queueResponse(
			new HttpResponse(
				success: false,
				statusCode: 503,
				body: $error_response
			)
		);

		$client   = $this->create_client();
		$response = $client->chat(
			array( array( 'role' => 'user', 'content' => 'test' ) ),
			array()
		);

		$this->assertFalse( $response->is_success() );
		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( 'model_overloaded', $response->get_meta()['error_code'] );
	}

	public function test_error_categorization_handles_context_length_exceeded(): void {
		$error_response = json_encode(
			array(
				'error' => array(
					'message' => 'This model\'s maximum context length is 8192 tokens.',
					'type'    => 'invalid_request_error',
					'code'    => 'context_length_exceeded',
				),
			)
		);
		$this->http->queueResponse(
			new HttpResponse(
				success: false,
				statusCode: 400,
				body: $error_response
			)
		);

		$client   = $this->create_client();
		$response = $client->chat(
			array( array( 'role' => 'user', 'content' => 'test' ) ),
			array()
		);

		$this->assertFalse( $response->is_success() );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'context_length_exceeded', $response->get_meta()['error_code'] );
	}

	public function test_error_categorization_handles_malformed_error_response(): void {
		// Non-JSON error response (e.g., HTML error page).
		$this->http->queueResponse(
			new HttpResponse(
				success: false,
				statusCode: 502,
				body: '<html><body>Bad Gateway</body></html>'
			)
		);

		$client   = $this->create_client();
		$response = $client->chat(
			array( array( 'role' => 'user', 'content' => 'test' ) ),
			array()
		);

		$this->assertFalse( $response->is_success() );
		$this->assertSame( 502, $response->get_status() );
		$this->assertSame( 'OpenAI API request failed.', $response->get_message() );
	}

	public function test_error_categorization_sanitizes_api_key_in_message(): void {
		$error_response = json_encode(
			array(
				'error' => array(
					'message' => 'Invalid API key: sk-proj-1234567890abcdefghijklmnop',
					'type'    => 'invalid_request_error',
					'code'    => 'invalid_api_key',
				),
			)
		);
		$this->http->queueResponse(
			new HttpResponse(
				success: false,
				statusCode: 401,
				body: $error_response
			)
		);

		$client   = $this->create_client();
		$response = $client->chat(
			array( array( 'role' => 'user', 'content' => 'test' ) ),
			array()
		);

		$this->assertFalse( $response->is_success() );
		$this->assertStringContainsString( '[REDACTED]', $response->get_message() );
		$this->assertStringNotContainsString( 'sk-proj-', $response->get_message() );
	}

	public function test_error_categorization_sanitizes_bearer_token_in_message(): void {
		$error_response = json_encode(
			array(
				'error' => array(
					'message' => 'Authorization failed for Bearer abc123xyz',
					'type'    => 'invalid_request_error',
					'code'    => 'invalid_auth',
				),
			)
		);
		$this->http->queueResponse(
			new HttpResponse(
				success: false,
				statusCode: 401,
				body: $error_response
			)
		);

		$client   = $this->create_client();
		$response = $client->chat(
			array( array( 'role' => 'user', 'content' => 'test' ) ),
			array()
		);

		$this->assertFalse( $response->is_success() );
		$this->assertStringContainsString( 'Bearer [REDACTED]', $response->get_message() );
	}

	public function test_error_categorization_handles_network_error(): void {
		$this->http->queueError( 'cURL error 28: Operation timed out', 'http_request_failed', 0 );

		$client   = $this->create_client();
		$response = $client->chat(
			array( array( 'role' => 'user', 'content' => 'test' ) ),
			array()
		);

		$this->assertFalse( $response->is_success() );
		$this->assertSame( 0, $response->get_status() );
		$this->assertStringContainsString( 'timed out', $response->get_message() );
	}

	public function test_error_categorization_handles_empty_error_body(): void {
		$this->http->queueResponse(
			new HttpResponse(
				success: false,
				statusCode: 500,
				body: ''
			)
		);

		$client   = $this->create_client();
		$response = $client->chat(
			array( array( 'role' => 'user', 'content' => 'test' ) ),
			array()
		);

		$this->assertFalse( $response->is_success() );
		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'OpenAI API request failed.', $response->get_message() );
	}

	public function test_error_response_includes_retry_after_header(): void {
		$error_response = json_encode(
			array(
				'error' => array(
					'message' => 'Rate limit exceeded.',
					'type'    => 'rate_limit_error',
					'code'    => 'rate_limit',
				),
			)
		);
		$this->http->queueResponse(
			new HttpResponse(
				success: false,
				statusCode: 429,
				body: $error_response,
				headers: array( 'retry-after' => '30' )
			)
		);

		$client   = $this->create_client();
		$response = $client->chat(
			array( array( 'role' => 'user', 'content' => 'test' ) ),
			array()
		);

		$this->assertFalse( $response->is_success() );
		$this->assertSame( 30, $response->get_meta()['retry_after'] );
	}

	// ========================================
	// Retry Behavior Tests (Extended)
	// ========================================

	public function test_retry_does_not_occur_on_401_unauthorized(): void {
		$error_response = json_encode(
			array(
				'error' => array(
					'message' => 'Unauthorized.',
					'type'    => 'invalid_request_error',
					'code'    => 'unauthorized',
				),
			)
		);
		$this->http->queueResponse(
			new HttpResponse(
				success: false,
				statusCode: 401,
				body: $error_response
			)
		);

		$client = new OpenAIClient(
			$this->http,
			'test-key',
			'gpt-4o-mini',
			array(
				'max_retries'   => 3,
				'token_counter' => $this->stub_token_counter(),
			),
			null,
			$this->sleeper
		);

		$response = $client->chat(
			array( array( 'role' => 'user', 'content' => 'test' ) ),
			array()
		);

		$this->assertFalse( $response->is_success() );
		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 1, $this->http->getRequestCount() );
		$this->assertSame( 0, $this->sleeper->getSleepCount() );
	}

	public function test_retry_does_not_occur_on_404_not_found(): void {
		$error_response = json_encode(
			array(
				'error' => array(
					'message' => 'Model not found.',
					'type'    => 'invalid_request_error',
					'code'    => 'model_not_found',
				),
			)
		);
		$this->http->queueResponse(
			new HttpResponse(
				success: false,
				statusCode: 404,
				body: $error_response
			)
		);

		$client = new OpenAIClient(
			$this->http,
			'test-key',
			'gpt-4o-mini',
			array(
				'max_retries'   => 3,
				'token_counter' => $this->stub_token_counter(),
			),
			null,
			$this->sleeper
		);

		$response = $client->chat(
			array( array( 'role' => 'user', 'content' => 'test' ) ),
			array()
		);

		$this->assertFalse( $response->is_success() );
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 1, $this->http->getRequestCount() );
	}

	public function test_retry_occurs_on_502_bad_gateway(): void {
		$error_response = json_encode( array( 'error' => array( 'message' => 'Bad Gateway' ) ) );
		$ok_fixture     = $this->fixture( 'chat-success.json' );

		$this->http->queueResponse(
			new HttpResponse( success: false, statusCode: 502, body: $error_response )
		);
		$this->http->queueSuccess( $ok_fixture, 200 );

		$client = new OpenAIClient(
			$this->http,
			'test-key',
			'gpt-4o-mini',
			array(
				'max_retries'   => 3,
				'token_counter' => $this->stub_token_counter(),
			),
			null,
			$this->sleeper
		);

		$response = $client->chat(
			array( array( 'role' => 'user', 'content' => 'test' ) ),
			array()
		);

		$this->assertTrue( $response->is_success() );
		$this->assertSame( 1, $response->get_meta()['retries'] );
		$this->assertSame( 2, $this->http->getRequestCount() );
	}

	public function test_retry_occurs_on_504_gateway_timeout(): void {
		$error_response = json_encode( array( 'error' => array( 'message' => 'Gateway Timeout' ) ) );
		$ok_fixture     = $this->fixture( 'chat-success.json' );

		$this->http->queueResponse(
			new HttpResponse( success: false, statusCode: 504, body: $error_response )
		);
		$this->http->queueSuccess( $ok_fixture, 200 );

		$client = new OpenAIClient(
			$this->http,
			'test-key',
			'gpt-4o-mini',
			array(
				'max_retries'   => 3,
				'token_counter' => $this->stub_token_counter(),
			),
			null,
			$this->sleeper
		);

		$response = $client->chat(
			array( array( 'role' => 'user', 'content' => 'test' ) ),
			array()
		);

		$this->assertTrue( $response->is_success() );
		$this->assertSame( 1, $response->get_meta()['retries'] );
	}

	public function test_retry_respects_retry_after_header(): void {
		$error_fixture = $this->fixture( 'chat-error.json' );
		$ok_fixture    = $this->fixture( 'chat-success.json' );

		$this->http->queueResponse(
			new HttpResponse(
				success: false,
				statusCode: 429,
				body: $error_fixture,
				headers: array( 'retry-after' => '5' )
			)
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
			'test-key',
			'gpt-4o-mini',
			array( 'token_counter' => $this->stub_token_counter() ),
			$policy,
			$this->sleeper
		);

		$response = $client->chat(
			array( array( 'role' => 'user', 'content' => 'test' ) ),
			array()
		);

		$this->assertTrue( $response->is_success() );
		// Should wait 5000ms (5 seconds from Retry-After).
		$this->assertSame( 5000, $this->sleeper->getLastSleep() );
	}

	public function test_retry_count_is_tracked_correctly(): void {
		$error_fixture = $this->fixture( 'chat-error.json' );
		$ok_fixture    = $this->fixture( 'chat-success.json' );

		// Queue 3 errors then success.
		for ( $i = 0; $i < 3; $i++ ) {
			$this->http->queueResponse(
				new HttpResponse( success: false, statusCode: 500, body: $error_fixture )
			);
		}
		$this->http->queueSuccess( $ok_fixture, 200 );

		$policy = new ExponentialBackoffPolicy(
			maxRetries: 5,
			baseDelayMs: 100,
			maxDelayMs: 1000,
			jitterFactor: 0.0
		);

		$client = new OpenAIClient(
			$this->http,
			'test-key',
			'gpt-4o-mini',
			array( 'token_counter' => $this->stub_token_counter() ),
			$policy,
			$this->sleeper
		);

		$response = $client->chat(
			array( array( 'role' => 'user', 'content' => 'test' ) ),
			array()
		);

		$this->assertTrue( $response->is_success() );
		$this->assertSame( 3, $response->get_meta()['retries'] );
		$this->assertSame( 4, $this->http->getRequestCount() );
		$this->assertSame( 3, $this->sleeper->getSleepCount() );
	}

	public function test_zero_retries_disables_retry_completely(): void {
		$error_fixture = $this->fixture( 'chat-error.json' );

		$this->http->queueResponse(
			new HttpResponse( success: false, statusCode: 500, body: $error_fixture )
		);

		$client = new OpenAIClient(
			$this->http,
			'test-key',
			'gpt-4o-mini',
			array(
				'max_retries'   => 0,
				'token_counter' => $this->stub_token_counter(),
			),
			null,
			$this->sleeper
		);

		$response = $client->chat(
			array( array( 'role' => 'user', 'content' => 'test' ) ),
			array()
		);

		$this->assertFalse( $response->is_success() );
		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 1, $this->http->getRequestCount() );
		$this->assertSame( 0, $this->sleeper->getSleepCount() );
	}

	// ========================================
	// Key Validation Tests (Extended)
	// ========================================

	public function test_validate_key_uses_correct_endpoint(): void {
		$this->http->queueSuccess( '{"data":[]}', 200 );

		$client = $this->create_client( array( 'base_url' => 'https://api.openai.com/v1' ) );
		$client->validateKey( 'test-key' );

		$request = $this->http->getLastRequest();
		$this->assertSame( 'GET', $request['method'] );
		$this->assertSame( 'https://api.openai.com/v1/models', $request['url'] );
	}

	public function test_validate_key_sends_correct_authorization(): void {
		$this->http->queueSuccess( '{"data":[]}', 200 );

		$client = $this->create_client();
		$client->validateKey( 'my-api-key' );

		$request = $this->http->getLastRequest();
		$this->assertSame( 'Bearer my-api-key', $request['options']['headers']['Authorization'] );
	}

	public function test_validate_key_with_empty_string(): void {
		$client = $this->create_client();

		// Empty string should return false without making HTTP request.
		$this->assertFalse( $client->validateKey( '' ) );
		$this->assertSame( 0, $this->http->getRequestCount() );
	}
}
