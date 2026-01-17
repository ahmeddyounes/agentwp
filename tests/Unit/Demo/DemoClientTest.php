<?php
/**
 * DemoClient unit tests.
 */

namespace AgentWP\Tests\Unit\Demo;

use AgentWP\Demo\DemoClient;
use AgentWP\Tests\TestCase;
use WP_Mock;

class DemoClientTest extends TestCase {
	public function setUp(): void {
		parent::setUp();

		WP_Mock::userFunction( 'sanitize_text_field', array(
			'return' => function ( $str ) {
				return $str;
			},
		) );

		WP_Mock::userFunction( 'wp_json_encode', array(
			'return' => function ( $data ) {
				return json_encode( $data );
			},
		) );
	}

	public function test_chat_returns_success_response(): void {
		$client = new DemoClient();
		$messages = array(
			array( 'role' => 'user', 'content' => 'Hello' ),
		);

		$response = $client->chat( $messages, array() );

		$this->assertTrue( $response->is_success() );
		$this->assertSame( 200, $response->get_status() );
	}

	public function test_chat_returns_demo_content(): void {
		$client = new DemoClient();
		$messages = array(
			array( 'role' => 'user', 'content' => 'Hello' ),
		);

		$response = $client->chat( $messages, array() );
		$data = $response->get_data();

		$this->assertArrayHasKey( 'content', $data );
		$this->assertStringContainsString( 'demo', strtolower( $data['content'] ) );
	}

	public function test_chat_includes_demo_mode_meta(): void {
		$client = new DemoClient();
		$messages = array(
			array( 'role' => 'user', 'content' => 'Hello' ),
		);

		$response = $client->chat( $messages, array() );
		$meta = $response->get_meta();

		$this->assertArrayHasKey( 'demo_mode', $meta );
		$this->assertTrue( $meta['demo_mode'] );
		$this->assertSame( 'demo', $meta['usage_source'] );
		$this->assertSame( 'demo-stub-v1', $meta['model'] );
	}

	public function test_chat_returns_empty_tool_calls(): void {
		$client = new DemoClient();
		$messages = array(
			array( 'role' => 'user', 'content' => 'Hello' ),
		);

		$response = $client->chat( $messages, array() );
		$data = $response->get_data();

		$this->assertArrayHasKey( 'tool_calls', $data );
		$this->assertEmpty( $data['tool_calls'] );
	}

	public function test_validate_key_always_returns_true(): void {
		$client = new DemoClient();

		$this->assertTrue( $client->validateKey( '' ) );
		$this->assertTrue( $client->validateKey( 'any-key' ) );
		$this->assertTrue( $client->validateKey( 'sk-test-key' ) );
	}

	public function test_chat_provides_context_aware_responses(): void {
		$client = new DemoClient();

		// Test product keyword.
		$messages = array(
			array( 'role' => 'user', 'content' => 'Tell me about the product' ),
		);
		$response = $client->chat( $messages, array() );
		$data = $response->get_data();
		$this->assertStringContainsString( 'Demo', $data['content'] );
		$this->assertStringContainsString( 'product', strtolower( $data['content'] ) );

		// Test order keyword.
		$messages = array(
			array( 'role' => 'user', 'content' => 'What is the order status?' ),
		);
		$response = $client->chat( $messages, array() );
		$data = $response->get_data();
		$this->assertStringContainsString( 'Demo', $data['content'] );
		$this->assertStringContainsString( 'order', strtolower( $data['content'] ) );
	}

	public function test_chat_includes_token_estimates(): void {
		$client = new DemoClient();
		$messages = array(
			array( 'role' => 'user', 'content' => 'Hello' ),
		);

		$response = $client->chat( $messages, array() );
		$meta = $response->get_meta();

		$this->assertArrayHasKey( 'input_tokens', $meta );
		$this->assertArrayHasKey( 'output_tokens', $meta );
		$this->assertArrayHasKey( 'total_tokens', $meta );
		$this->assertIsInt( $meta['input_tokens'] );
		$this->assertIsInt( $meta['output_tokens'] );
		$this->assertIsInt( $meta['total_tokens'] );
	}
}
