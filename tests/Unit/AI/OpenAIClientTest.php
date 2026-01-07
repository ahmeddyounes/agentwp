<?php
/**
 * OpenAI client unit tests.
 */

namespace AgentWP\Tests\Unit\AI;

use AgentWP\AI\OpenAIClient;
use AgentWP\AI\Functions\FunctionSchema;
use AgentWP\AI\TokenCounter;
use AgentWP\Tests\TestCase;
use WP_Error;
use WP_Mock;

class OpenAIClientTest extends TestCase {
	private function fixture( $name ) {
		return file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/openai/' . $name );
	}

	private function mock_response( $body, $status = 200, array $headers = array() ) {
		return array(
			'response' => array( 'code' => $status ),
			'body'     => $body,
			'headers'  => $headers,
		);
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
		$client   = new OpenAIClient( '' );
		$response = $client->chat( array(), array() );

		$this->assertFalse( $response->is_success() );
		$this->assertSame( 401, $response->get_status() );
	}

	public function test_chat_success_with_tool_normalization(): void {
		$fixture = $this->fixture( 'chat-success.json' );

		WP_Mock::userFunction(
			'wp_remote_post',
			array(
				'times'  => 1,
				'return' => $this->mock_response( $fixture, 200 ),
			)
		);

		$client = new OpenAIClient(
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
	}

	public function test_chat_estimates_usage_when_missing(): void {
		$fixture = $this->fixture( 'chat-success-no-usage.json' );

		WP_Mock::userFunction(
			'wp_remote_post',
			array(
				'times'  => 1,
				'return' => $this->mock_response( $fixture, 200 ),
			)
		);

		$client = new OpenAIClient(
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

		WP_Mock::userFunction(
			'wp_remote_post',
			array(
				'times'  => 1,
				'return' => $this->mock_response( $fixture, 200 ),
			)
		);

		$client = new OpenAIClient(
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

		WP_Mock::userFunction(
			'wp_remote_post',
			array(
				'times'  => 1,
				'return' => $this->mock_response( $fixture, 200 ),
			)
		);

		$client   = new OpenAIClient(
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
		WP_Mock::userFunction(
			'wp_remote_post',
			array(
				'times'  => 1,
				'return' => $this->mock_response( 'not-json', 200 ),
			)
		);

		$client   = new OpenAIClient(
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

		WP_Mock::userFunction(
			'wp_remote_post',
			array(
				'times'  => 1,
				'return' => $this->mock_response( $fixture, 200 ),
			)
		);

		$client = new OpenAIClient(
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

		WP_Mock::userFunction(
			'wp_remote_post',
			array(
				'times'           => 2,
				'return_in_order' => array(
					$this->mock_response( $error_fixture, 500 ),
					$this->mock_response( $ok_fixture, 200 ),
				),
			)
		);

		$client   = new OpenAIClient(
			'key',
			'gpt-4o-mini',
			array(
				'max_retries'   => 1,
				'token_counter' => $this->stub_token_counter(),
			)
		);
		$response = $client->chat( array( array( 'role' => 'user', 'content' => 'Hi' ) ), array() );

		$this->assertTrue( $response->is_success() );
		$this->assertSame( 1, $response->get_meta()['retries'] );
	}

	public function test_chat_handles_wp_error(): void {
		WP_Mock::userFunction(
			'wp_remote_post',
			array(
				'times'  => 1,
				'return' => new WP_Error( 'http_request_failed', 'Operation timed out.' ),
			)
		);

		$client   = new OpenAIClient(
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
		$client = new OpenAIClient( 'key', 'gpt-4o-mini', array( 'base_url' => 'https://example.com' ) );

		WP_Mock::userFunction(
			'wp_remote_get',
			array(
				'times'  => 1,
				'return' => $this->mock_response( '{}', 200 ),
			)
		);

		$this->assertTrue( $client->validateKey( 'key' ) );
		$this->assertFalse( $client->validateKey( '' ) );
	}

	public function test_validate_key_handles_errors(): void {
		$client = new OpenAIClient( 'key', 'gpt-4o-mini', array( 'base_url' => 'https://example.com' ) );

		WP_Mock::userFunction(
			'wp_remote_get',
			array(
				'times'  => 1,
				'return' => new WP_Error( 'http_request_failed', 'fail' ),
			)
		);

		$this->assertFalse( $client->validateKey( 'key' ) );
	}
}
