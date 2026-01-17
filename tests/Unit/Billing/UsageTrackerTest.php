<?php
/**
 * UsageTracker unit tests.
 *
 * Validates that usage tracking remains accurate after the OpenAI refactor,
 * including streaming + fallback estimation.
 *
 * @package AgentWP
 */

namespace AgentWP\Tests\Unit\Billing;

use AgentWP\AI\Model;
use AgentWP\AI\OpenAIClient;
use AgentWP\AI\TokenCounter;
use AgentWP\Billing\UsageTracker;
use AgentWP\DTO\HttpResponse;
use AgentWP\Tests\Fakes\FakeHttpClient;
use AgentWP\Tests\Fakes\FakeSleeper;
use AgentWP\Tests\TestCase;

class UsageTrackerTest extends TestCase {

	private FakeHttpClient $http;
	private FakeSleeper $sleeper;
	private array $logged_usage = array();

	public function setUp(): void {
		parent::setUp();
		$this->http         = new FakeHttpClient();
		$this->sleeper      = new FakeSleeper();
		$this->logged_usage = array();
	}

	public function tearDown(): void {
		$this->http->reset();
		$this->sleeper->reset();
		$this->logged_usage = array();
		parent::tearDown();
	}

	private function fixture( $name ) {
		return file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/openai/' . $name );
	}

	/**
	 * Create a TokenCounter stub with predictable values.
	 */
	private function stub_token_counter( int $request_tokens = 10, int $text_tokens = 5 ) {
		$request = $request_tokens;
		$text    = $text_tokens;
		return new class( $request, $text ) extends TokenCounter {
			private int $request;
			private int $text;

			public function __construct( int $request, int $text ) {
				$this->request = $request;
				$this->text    = $text;
			}

			public function count_request_tokens( array $messages = array(), array $tools = array(), $model = '' ) {
				return $this->request;
			}

			public function count_text_tokens( $text = '', $model = '' ) {
				return $this->text;
			}
		};
	}

	/**
	 * Create OpenAI client with test configuration.
	 */
	private function create_client( array $options = array() ): OpenAIClient {
		$defaults = array(
			'max_retries'   => 0,
			'token_counter' => $this->stub_token_counter(),
		);
		return new OpenAIClient(
			$this->http,
			'test-key',
			'gpt-4o-mini',
			array_merge( $defaults, $options ),
			null,
			$this->sleeper
		);
	}

	// ========================================
	// Usage Metadata Accuracy Tests (Non-Streaming)
	// ========================================

	public function test_non_stream_response_returns_openai_usage_metadata(): void {
		$fixture = $this->fixture( 'chat-success.json' );
		$this->http->queueSuccess( $fixture, 200 );

		$client   = $this->create_client();
		$response = $client->chat(
			array( array( 'role' => 'user', 'content' => 'test' ) ),
			array()
		);

		$this->assertTrue( $response->is_success() );
		$meta = $response->get_meta();

		// Verify usage from OpenAI response.
		$this->assertSame( 5, $meta['input_tokens'] );
		$this->assertSame( 7, $meta['output_tokens'] );
		$this->assertSame( 12, $meta['total_tokens'] );
		$this->assertSame( 'openai', $meta['usage_source'] );
		$this->assertSame( 'gpt-4o-mini', $meta['model'] );
	}

	public function test_non_stream_response_uses_fallback_estimation_when_usage_missing(): void {
		$fixture = $this->fixture( 'chat-success-no-usage.json' );
		$this->http->queueSuccess( $fixture, 200 );

		$stub_counter = $this->stub_token_counter( 15, 8 );
		$client       = $this->create_client( array( 'token_counter' => $stub_counter ) );
		$response     = $client->chat(
			array( array( 'role' => 'user', 'content' => 'test' ) ),
			array()
		);

		$this->assertTrue( $response->is_success() );
		$meta = $response->get_meta();

		// Verify fallback estimation.
		$this->assertSame( 'tiktoken', $meta['usage_source'] );
		$this->assertSame( 15, $meta['input_tokens'] );
		$this->assertSame( 8, $meta['output_tokens'] );
		$this->assertSame( 23, $meta['total_tokens'] );
	}

	// ========================================
	// Usage Metadata Accuracy Tests (Streaming)
	// ========================================

	public function test_stream_response_extracts_usage_from_final_chunk(): void {
		$fixture = $this->fixture( 'chat-stream.txt' );
		$this->http->queueSuccess( $fixture, 200 );

		$client   = $this->create_client( array( 'stream' => true ) );
		$response = $client->chat(
			array( array( 'role' => 'user', 'content' => 'test' ) ),
			array()
		);

		$this->assertTrue( $response->is_success() );
		$meta = $response->get_meta();

		// Verify usage from streaming response (from usage chunk).
		$this->assertSame( 2, $meta['input_tokens'] );
		$this->assertSame( 3, $meta['output_tokens'] );
		$this->assertSame( 5, $meta['total_tokens'] );
		$this->assertSame( 'openai', $meta['usage_source'] );
	}

	public function test_stream_response_uses_fallback_when_no_usage_chunk(): void {
		$stream = implode(
			"\n",
			array(
				'data: {"id":"test","model":"gpt-4o-mini","choices":[{"delta":{"content":"Hello"}}]}',
				'data: {"choices":[{"delta":{"content":" World"}}]}',
				'data: [DONE]',
			)
		);
		$this->http->queueSuccess( $stream, 200 );

		$stub_counter = $this->stub_token_counter( 20, 4 );
		$client       = $this->create_client(
			array(
				'stream'        => true,
				'token_counter' => $stub_counter,
			)
		);
		$response     = $client->chat(
			array( array( 'role' => 'user', 'content' => 'test' ) ),
			array()
		);

		$this->assertTrue( $response->is_success() );
		$meta = $response->get_meta();

		// Verify fallback estimation for stream without usage.
		$this->assertSame( 'tiktoken', $meta['usage_source'] );
		$this->assertSame( 20, $meta['input_tokens'] );
		$this->assertSame( 4, $meta['output_tokens'] );
		$this->assertSame( 24, $meta['total_tokens'] );
	}

	public function test_stream_response_with_include_usage_flag_in_request(): void {
		$fixture = $this->fixture( 'chat-stream.txt' );
		$this->http->queueSuccess( $fixture, 200 );

		$client = $this->create_client( array( 'stream' => true ) );
		$client->chat(
			array( array( 'role' => 'user', 'content' => 'test' ) ),
			array()
		);

		$request = $this->http->getLastRequest();
		$body    = json_decode( $request['options']['body'], true );

		// Verify stream_options includes usage flag.
		$this->assertTrue( $body['stream'] );
		$this->assertArrayHasKey( 'stream_options', $body );
		$this->assertTrue( $body['stream_options']['include_usage'] );
	}

	// ========================================
	// Tool Call Usage Estimation Tests
	// ========================================

	public function test_fallback_estimation_includes_tool_call_tokens(): void {
		$fixture = $this->fixture( 'chat-success-no-usage-tools.json' );
		$this->http->queueSuccess( $fixture, 200 );

		$stub_counter = $this->stub_token_counter( 10, 7 );
		$client       = $this->create_client( array( 'token_counter' => $stub_counter ) );
		$response     = $client->chat(
			array( array( 'role' => 'user', 'content' => 'test' ) ),
			array()
		);

		$this->assertTrue( $response->is_success() );
		$meta = $response->get_meta();

		$this->assertSame( 'tiktoken', $meta['usage_source'] );
		// Tool calls should contribute to output tokens.
		$this->assertGreaterThanOrEqual( 7, $meta['output_tokens'] );
		$this->assertNotEmpty( $response->get_data()['tool_calls'] );
	}

	public function test_stream_tool_calls_usage_fallback(): void {
		$stream = implode(
			"\n",
			array(
				'data: {"choices":[{"delta":{"tool_calls":[{"index":0,"id":"call_1","type":"function","function":{"name":"search"}}]}}]}',
				'data: {"choices":[{"delta":{"tool_calls":[{"index":0,"function":{"arguments":"{\"q\":\"test\"}"}}]}}]}',
				'data: [DONE]',
			)
		);
		$this->http->queueSuccess( $stream, 200 );

		$stub_counter = $this->stub_token_counter( 5, 3 );
		$client       = $this->create_client(
			array(
				'stream'        => true,
				'token_counter' => $stub_counter,
			)
		);
		$response     = $client->chat(
			array( array( 'role' => 'user', 'content' => 'test' ) ),
			array()
		);

		$this->assertTrue( $response->is_success() );
		$meta = $response->get_meta();

		// No usage chunk in stream, so should use fallback.
		$this->assertSame( 'tiktoken', $meta['usage_source'] );
		$this->assertNotEmpty( $response->get_data()['tool_calls'] );
	}

	// ========================================
	// Model Tracking Tests
	// ========================================

	public function test_model_from_response_takes_precedence(): void {
		$response_body = json_encode(
			array(
				'id'      => 'test',
				'model'   => 'gpt-4o-2024-08-06', // Different from configured model.
				'choices' => array(
					array(
						'message' => array(
							'role'    => 'assistant',
							'content' => 'Test response.',
						),
					),
				),
				'usage'   => array(
					'prompt_tokens'     => 10,
					'completion_tokens' => 5,
					'total_tokens'      => 15,
				),
			)
		);
		$this->http->queueSuccess( $response_body, 200 );

		// Create client with gpt-4o-mini but response returns gpt-4o variant.
		$client   = $this->create_client();
		$response = $client->chat(
			array( array( 'role' => 'user', 'content' => 'test' ) ),
			array()
		);

		$meta = $response->get_meta();

		// Model from response should be used.
		$this->assertSame( 'gpt-4o-2024-08-06', $meta['model'] );
	}

	public function test_model_from_stream_response(): void {
		$stream = implode(
			"\n",
			array(
				'data: {"model":"gpt-4o","choices":[{"delta":{"content":"Hello"}}]}',
				'data: {"usage":{"prompt_tokens":5,"completion_tokens":2,"total_tokens":7}}',
				'data: [DONE]',
			)
		);
		$this->http->queueSuccess( $stream, 200 );

		$client   = $this->create_client( array( 'stream' => true ) );
		$response = $client->chat(
			array( array( 'role' => 'user', 'content' => 'test' ) ),
			array()
		);

		$meta = $response->get_meta();
		$this->assertSame( 'gpt-4o', $meta['model'] );
	}

	public function test_configured_model_used_when_response_omits_it(): void {
		$response_body = json_encode(
			array(
				'id'      => 'test',
				// No 'model' field.
				'choices' => array(
					array(
						'message' => array(
							'role'    => 'assistant',
							'content' => 'Test.',
						),
					),
				),
				'usage'   => array(
					'prompt_tokens'     => 5,
					'completion_tokens' => 2,
					'total_tokens'      => 7,
				),
			)
		);
		$this->http->queueSuccess( $response_body, 200 );

		$client   = $this->create_client();
		$response = $client->chat(
			array( array( 'role' => 'user', 'content' => 'test' ) ),
			array()
		);

		$meta = $response->get_meta();

		// Should fall back to configured model.
		$this->assertSame( 'gpt-4o-mini', $meta['model'] );
	}

	// ========================================
	// Intent Type Tracking Tests
	// ========================================

	public function test_intent_type_passed_to_client_is_available(): void {
		$fixture = $this->fixture( 'chat-success.json' );
		$this->http->queueSuccess( $fixture, 200 );

		$client = new OpenAIClient(
			$this->http,
			'test-key',
			'gpt-4o-mini',
			array(
				'max_retries'   => 0,
				'token_counter' => $this->stub_token_counter(),
				'intent_type'   => 'ORDER_SEARCH',
			),
			null,
			$this->sleeper
		);

		$response = $client->chat(
			array( array( 'role' => 'user', 'content' => 'test' ) ),
			array()
		);

		$this->assertTrue( $response->is_success() );
		// Intent type is used internally for UsageTracker logging.
		// Verify the response itself is successful and contains expected data.
		$this->assertNotEmpty( $response->get_meta()['model'] );
	}

	// ========================================
	// Retry with Usage Tracking Tests
	// ========================================

	public function test_usage_metadata_correct_after_retry(): void {
		$error_fixture = json_encode( array( 'error' => array( 'message' => 'Rate limited' ) ) );
		$ok_fixture    = $this->fixture( 'chat-success.json' );

		$this->http->queueResponse(
			new HttpResponse( success: false, statusCode: 429, body: $error_fixture )
		);
		$this->http->queueSuccess( $ok_fixture, 200 );

		$client = new OpenAIClient(
			$this->http,
			'test-key',
			'gpt-4o-mini',
			array(
				'max_retries'   => 1,
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
		$meta = $response->get_meta();

		// Usage should be from the successful response, not retry tracking.
		$this->assertSame( 5, $meta['input_tokens'] );
		$this->assertSame( 7, $meta['output_tokens'] );
		$this->assertSame( 12, $meta['total_tokens'] );
		$this->assertSame( 'openai', $meta['usage_source'] );
		$this->assertSame( 1, $meta['retries'] );
	}

	// ========================================
	// Edge Cases
	// ========================================

	public function test_zero_token_usage_is_valid(): void {
		$response_body = json_encode(
			array(
				'id'      => 'test',
				'model'   => 'gpt-4o-mini',
				'choices' => array(
					array(
						'message' => array(
							'role'    => 'assistant',
							'content' => '',
						),
					),
				),
				'usage'   => array(
					'prompt_tokens'     => 3,
					'completion_tokens' => 0,
					'total_tokens'      => 3,
				),
			)
		);
		$this->http->queueSuccess( $response_body, 200 );

		$client   = $this->create_client();
		$response = $client->chat(
			array( array( 'role' => 'user', 'content' => 'test' ) ),
			array()
		);

		$meta = $response->get_meta();

		$this->assertSame( 3, $meta['input_tokens'] );
		$this->assertSame( 0, $meta['output_tokens'] );
		$this->assertSame( 3, $meta['total_tokens'] );
	}

	public function test_large_token_counts_handled_correctly(): void {
		$response_body = json_encode(
			array(
				'id'      => 'test',
				'model'   => 'gpt-4o-mini',
				'choices' => array(
					array(
						'message' => array(
							'role'    => 'assistant',
							'content' => 'Large response.',
						),
					),
				),
				'usage'   => array(
					'prompt_tokens'     => 100000,
					'completion_tokens' => 50000,
					'total_tokens'      => 150000,
				),
			)
		);
		$this->http->queueSuccess( $response_body, 200 );

		$client   = $this->create_client();
		$response = $client->chat(
			array( array( 'role' => 'user', 'content' => 'test' ) ),
			array()
		);

		$meta = $response->get_meta();

		$this->assertSame( 100000, $meta['input_tokens'] );
		$this->assertSame( 50000, $meta['output_tokens'] );
		$this->assertSame( 150000, $meta['total_tokens'] );
	}

	public function test_partial_usage_object_handled(): void {
		$response_body = json_encode(
			array(
				'id'      => 'test',
				'model'   => 'gpt-4o-mini',
				'choices' => array(
					array(
						'message' => array(
							'role'    => 'assistant',
							'content' => 'Test.',
						),
					),
				),
				'usage'   => array(
					'prompt_tokens'     => 10,
					// Missing completion_tokens and total_tokens.
				),
			)
		);
		$this->http->queueSuccess( $response_body, 200 );

		$client   = $this->create_client();
		$response = $client->chat(
			array( array( 'role' => 'user', 'content' => 'test' ) ),
			array()
		);

		$meta = $response->get_meta();

		$this->assertSame( 10, $meta['input_tokens'] );
		$this->assertSame( 0, $meta['output_tokens'] );
		$this->assertSame( 0, $meta['total_tokens'] );
		$this->assertSame( 'openai', $meta['usage_source'] );
	}

	// ========================================
	// UsageTracker Static Method Tests
	// ========================================

	public function test_model_normalization(): void {
		// Model::normalize returns exact match for supported models.
		$this->assertSame( Model::GPT_4O_MINI, Model::normalize( 'gpt-4o-mini' ) );
		$this->assertSame( Model::GPT_4O, Model::normalize( 'gpt-4o' ) );
		// Unsupported models fall back to default (gpt-4o-mini).
		$this->assertSame( Model::GPT_4O_MINI, Model::normalize( 'GPT-4O-MINI' ) );
		$this->assertSame( Model::GPT_4O_MINI, Model::normalize( 'invalid-model' ) );
	}

	public function test_stream_metadata_includes_stream_flag(): void {
		$fixture = $this->fixture( 'chat-stream.txt' );
		$this->http->queueSuccess( $fixture, 200 );

		$client   = $this->create_client( array( 'stream' => true ) );
		$response = $client->chat(
			array( array( 'role' => 'user', 'content' => 'test' ) ),
			array()
		);

		$meta = $response->get_meta();
		$this->assertTrue( $meta['stream'] );
	}

	public function test_non_stream_metadata_includes_stream_flag(): void {
		$fixture = $this->fixture( 'chat-success.json' );
		$this->http->queueSuccess( $fixture, 200 );

		$client   = $this->create_client();
		$response = $client->chat(
			array( array( 'role' => 'user', 'content' => 'test' ) ),
			array()
		);

		$meta = $response->get_meta();
		$this->assertFalse( $meta['stream'] );
	}

	// ========================================
	// Regression Tests
	// ========================================

	/**
	 * Regression test: Verify streaming responses with usage chunk
	 * correctly extract and use OpenAI usage data instead of fallback.
	 */
	public function test_regression_stream_usage_extraction(): void {
		// This is the exact format OpenAI sends in streaming responses.
		$stream = implode(
			"\n",
			array(
				'data: {"id":"chatcmpl-abc","object":"chat.completion.chunk","model":"gpt-4o-mini","choices":[{"index":0,"delta":{"role":"assistant","content":""},"finish_reason":null}]}',
				'data: {"id":"chatcmpl-abc","choices":[{"index":0,"delta":{"content":"Hello"},"finish_reason":null}]}',
				'data: {"id":"chatcmpl-abc","choices":[{"index":0,"delta":{},"finish_reason":"stop"}]}',
				'data: {"id":"chatcmpl-abc","usage":{"prompt_tokens":42,"completion_tokens":7,"total_tokens":49}}',
				'data: [DONE]',
			)
		);
		$this->http->queueSuccess( $stream, 200 );

		$client   = $this->create_client( array( 'stream' => true ) );
		$response = $client->chat(
			array( array( 'role' => 'system', 'content' => 'You are helpful.' ), array( 'role' => 'user', 'content' => 'Say hello' ) ),
			array()
		);

		$meta = $response->get_meta();

		// Must use OpenAI usage, not fallback estimation.
		$this->assertSame( 'openai', $meta['usage_source'], 'Stream should extract usage from usage chunk' );
		$this->assertSame( 42, $meta['input_tokens'] );
		$this->assertSame( 7, $meta['output_tokens'] );
		$this->assertSame( 49, $meta['total_tokens'] );
	}

	/**
	 * Regression test: Ensure combined content + tool_calls usage estimation
	 * accounts for both when OpenAI doesn't return usage.
	 */
	public function test_regression_combined_content_and_tool_calls_estimation(): void {
		$response_body = json_encode(
			array(
				'id'      => 'test',
				'model'   => 'gpt-4o-mini',
				'choices' => array(
					array(
						'message' => array(
							'role'       => 'assistant',
							'content'    => 'Let me search for that order.',
							'tool_calls' => array(
								array(
									'id'       => 'call_123',
									'type'     => 'function',
									'function' => array(
										'name'      => 'search_orders',
										'arguments' => '{"query":"order 456"}',
									),
								),
							),
						),
					),
				),
				// No usage object.
			)
		);
		$this->http->queueSuccess( $response_body, 200 );

		// Custom counter that returns different values for content vs tool calls.
		$counter = new class() extends TokenCounter {
			private int $call_count = 0;

			public function count_request_tokens( array $messages = array(), array $tools = array(), $model = '' ) {
				return 25;
			}

			public function count_text_tokens( $text = '', $model = '' ) {
				++$this->call_count;
				// First call is for content, second for tool_calls JSON.
				return $this->call_count === 1 ? 8 : 12;
			}
		};

		$client   = $this->create_client( array( 'token_counter' => $counter ) );
		$response = $client->chat(
			array( array( 'role' => 'user', 'content' => 'Find order 456' ) ),
			array()
		);

		$meta = $response->get_meta();

		$this->assertSame( 'tiktoken', $meta['usage_source'] );
		$this->assertSame( 25, $meta['input_tokens'] );
		// 8 (content) + 12 (tool_calls) = 20.
		$this->assertSame( 20, $meta['output_tokens'] );
		$this->assertSame( 45, $meta['total_tokens'] );
	}
}
