<?php
/**
 * Unit tests for WooCommerceLogger class.
 */

namespace AgentWP\Tests\Unit\Infrastructure;

use AgentWP\Contracts\LoggerInterface;
use AgentWP\Infrastructure\WooCommerceLogger;
use AgentWP\Tests\TestCase;

class WooCommerceLoggerTest extends TestCase {

	private WooCommerceLogger $logger;

	public function setUp(): void {
		parent::setUp();
		$this->logger = new WooCommerceLogger( 'test-source' );
	}

	public function test_implements_logger_interface(): void {
		$this->assertInstanceOf( LoggerInterface::class, $this->logger );
	}

	public function test_sanitizes_openai_api_key_in_message(): void {
		$message = 'API key is sk-1234567890abcdefghijklmnop';

		$sanitized = $this->invokeSanitize( $message );

		$this->assertSame( 'API key is [REDACTED]', $sanitized );
		$this->assertStringNotContainsString( 'sk-1234567890', $sanitized );
	}

	public function test_sanitizes_bearer_token_in_message(): void {
		$message = 'Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.test';

		$sanitized = $this->invokeSanitize( $message );

		$this->assertStringContainsString( '[REDACTED]', $sanitized );
		$this->assertStringNotContainsString( 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9', $sanitized );
	}

	public function test_sanitizes_password_pattern_in_message(): void {
		$message = 'Connection with password="secretPassword123"';

		$sanitized = $this->invokeSanitize( $message );

		$this->assertStringContainsString( '[REDACTED]', $sanitized );
		$this->assertStringNotContainsString( 'secretPassword123', $sanitized );
	}

	public function test_sanitizes_credit_card_pattern_in_message(): void {
		$message = 'Card number: 4111-1111-1111-1111';

		$sanitized = $this->invokeSanitize( $message );

		$this->assertStringContainsString( '[REDACTED]', $sanitized );
		$this->assertStringNotContainsString( '4111-1111-1111-1111', $sanitized );
	}

	public function test_sanitizes_api_key_context_key(): void {
		$context = array(
			'api_key'  => 'sk-secret-key-value',
			'username' => 'john',
		);

		$sanitized = $this->invokeSanitizeContext( $context );

		$this->assertSame( '[REDACTED]', $sanitized['api_key'] );
		$this->assertSame( 'john', $sanitized['username'] );
	}

	public function test_sanitizes_password_context_key(): void {
		$context = array(
			'password' => 'supersecret',
			'email'    => 'user@example.com',
		);

		$sanitized = $this->invokeSanitizeContext( $context );

		$this->assertSame( '[REDACTED]', $sanitized['password'] );
		$this->assertSame( 'user@example.com', $sanitized['email'] );
	}

	public function test_sanitizes_token_context_key(): void {
		$context = array(
			'access_token' => 'eyJhbGciOiJIUzI1NiJ9.payload.signature',
			'user_id'      => 123,
		);

		$sanitized = $this->invokeSanitizeContext( $context );

		$this->assertSame( '[REDACTED]', $sanitized['access_token'] );
		$this->assertSame( 123, $sanitized['user_id'] );
	}

	public function test_sanitizes_nested_context_arrays(): void {
		$context = array(
			'config' => array(
				'api_key' => 'secret-key',
				'timeout' => 30,
			),
		);

		$sanitized = $this->invokeSanitizeContext( $context );

		$this->assertSame( '[REDACTED]', $sanitized['config']['api_key'] );
		$this->assertSame( 30, $sanitized['config']['timeout'] );
	}

	public function test_sanitizes_string_values_in_context(): void {
		$context = array(
			'message' => 'User with token=abc123xyz789defghi logged in',
		);

		$sanitized = $this->invokeSanitizeContext( $context );

		$this->assertStringContainsString( '[REDACTED]', $sanitized['message'] );
	}

	public function test_preserves_non_sensitive_data(): void {
		$message = 'User john@example.com placed order #12345';

		$sanitized = $this->invokeSanitize( $message );

		$this->assertSame( $message, $sanitized );
	}

	public function test_sanitizes_multiple_secrets_in_one_message(): void {
		$message = 'Keys: sk-first123456789012345 and sk-second98765432109876';

		$sanitized = $this->invokeSanitize( $message );

		$this->assertStringNotContainsString( 'sk-first', $sanitized );
		$this->assertStringNotContainsString( 'sk-second', $sanitized );
		$this->assertSame( 'Keys: [REDACTED] and [REDACTED]', $sanitized );
	}

	public function test_handles_openai_key_variations(): void {
		$keys = array(
			'sk-abcdefghijklmnopqrst',
			'sk-proj-abcdefghijklmnopqrst',
			'sk-TestKey12345678901234567890',
		);

		foreach ( $keys as $key ) {
			$sanitized = $this->invokeSanitize( "Key: $key" );
			$this->assertStringNotContainsString( $key, $sanitized, "Failed to sanitize: $key" );
			$this->assertStringContainsString( '[REDACTED]', $sanitized );
		}
	}

	/**
	 * Invoke the private sanitize method.
	 *
	 * @param string $message Message to sanitize.
	 * @return string Sanitized message.
	 */
	private function invokeSanitize( string $message ): string {
		$reflection = new \ReflectionClass( $this->logger );
		$method     = $reflection->getMethod( 'sanitize' );
		$method->setAccessible( true );

		return $method->invoke( $this->logger, $message );
	}

	/**
	 * Invoke the private sanitizeContext method.
	 *
	 * @param array<string, mixed> $context Context to sanitize.
	 * @return array<string, mixed> Sanitized context.
	 */
	private function invokeSanitizeContext( array $context ): array {
		$reflection = new \ReflectionClass( $this->logger );
		$method     = $reflection->getMethod( 'sanitizeContext' );
		$method->setAccessible( true );

		return $method->invoke( $this->logger, $context );
	}
}
