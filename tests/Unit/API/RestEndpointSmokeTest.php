<?php
/**
 * REST endpoint smoke tests.
 *
 * Verifies the standard response envelope shape and key error codes for core endpoints:
 * - /intent
 * - /settings
 * - /settings/api-key
 * - /analytics
 * - /history
 * - /theme
 *
 * @package AgentWP\Tests\Unit\API
 */

namespace AgentWP\Tests\Unit\API;

use AgentWP\Config\AgentWPConfig;
use AgentWP\Tests\TestCase;
use WP_REST_Response;

/**
 * Smoke tests for REST endpoint response envelopes.
 *
 * These tests verify that all REST endpoints return responses in the expected
 * envelope format and use the correct error codes.
 */
class RestEndpointSmokeTest extends TestCase {

	/**
	 * Test that success response envelope has required structure.
	 *
	 * Success responses must have:
	 * - "success" => true
	 * - "data" => array with endpoint-specific data
	 */
	public function test_success_response_envelope_structure(): void {
		$response = $this->build_success_response( array( 'test' => 'data' ) );

		$data = $response->get_data();

		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'success', $data );
		$this->assertArrayHasKey( 'data', $data );
		$this->assertTrue( $data['success'] );
		$this->assertIsArray( $data['data'] );
	}

	/**
	 * Test that error response envelope has required structure.
	 *
	 * Error responses must have:
	 * - "success" => false
	 * - "data" => empty array
	 * - "error" => array with code, message, type
	 */
	public function test_error_response_envelope_structure(): void {
		$response = $this->build_error_response(
			AgentWPConfig::ERROR_CODE_INVALID_REQUEST,
			'Test error message',
			400
		);

		$data = $response->get_data();

		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'success', $data );
		$this->assertArrayHasKey( 'data', $data );
		$this->assertArrayHasKey( 'error', $data );
		$this->assertFalse( $data['success'] );
		$this->assertSame( array(), $data['data'] );
		$this->assertIsArray( $data['error'] );
		$this->assertArrayHasKey( 'code', $data['error'] );
		$this->assertArrayHasKey( 'message', $data['error'] );
		$this->assertArrayHasKey( 'type', $data['error'] );
	}

	/**
	 * Test that error response with meta includes meta field.
	 */
	public function test_error_response_includes_meta_when_provided(): void {
		$meta     = array( 'field' => 'test_field', 'reason' => 'test_reason' );
		$response = $this->build_error_response(
			AgentWPConfig::ERROR_CODE_INVALID_REQUEST,
			'Test error with meta',
			400,
			$meta
		);

		$data = $response->get_data();

		$this->assertArrayHasKey( 'meta', $data['error'] );
		$this->assertSame( $meta, $data['error']['meta'] );
	}

	/**
	 * Test that success response preserves HTTP status code.
	 */
	public function test_success_response_preserves_status_code(): void {
		$response = $this->build_success_response( array(), 201 );

		$this->assertSame( 201, $response->get_status() );
	}

	/**
	 * Test that error response preserves HTTP status code.
	 */
	public function test_error_response_preserves_status_code(): void {
		$response = $this->build_error_response(
			AgentWPConfig::ERROR_CODE_FORBIDDEN,
			'Forbidden',
			403
		);

		$this->assertSame( 403, $response->get_status() );
	}

	// =========================================================================
	// Intent Endpoint (/intent) Envelope Tests
	// =========================================================================

	/**
	 * Test /intent success response envelope shape.
	 *
	 * @dataProvider intentSuccessDataProvider
	 */
	public function test_intent_success_envelope_shape( array $data ): void {
		$response = $this->build_success_response( $data );
		$body     = $response->get_data();

		$this->assertTrue( $body['success'] );
		$this->assertIsArray( $body['data'] );
	}

	/**
	 * Data provider for intent success responses.
	 *
	 * @return array
	 */
	public static function intentSuccessDataProvider(): array {
		return array(
			'basic intent response'        => array(
				array(
					'intent_id' => 'uuid-123',
					'status'    => 'handled',
					'intent'    => 'order_search',
					'message'   => 'Found 5 orders.',
				),
			),
			'intent with additional data'  => array(
				array(
					'intent_id' => 'uuid-456',
					'status'    => 'handled',
					'intent'    => 'customer_lookup',
					'message'   => 'Customer found.',
					'results'   => array( 'customer_id' => 123 ),
				),
			),
		);
	}

	/**
	 * Test /intent validation error codes.
	 *
	 * @dataProvider intentErrorCodeProvider
	 */
	public function test_intent_error_codes( string $error_code, int $expected_status ): void {
		$response = $this->build_error_response( $error_code, 'Test message', $expected_status );
		$body     = $response->get_data();

		$this->assertFalse( $body['success'] );
		$this->assertSame( $error_code, $body['error']['code'] );
		$this->assertSame( $expected_status, $response->get_status() );
	}

	/**
	 * Data provider for intent error codes.
	 *
	 * @return array
	 */
	public static function intentErrorCodeProvider(): array {
		return array(
			'invalid request'       => array( AgentWPConfig::ERROR_CODE_INVALID_REQUEST, 400 ),
			'missing prompt'        => array( AgentWPConfig::ERROR_CODE_MISSING_PROMPT, 400 ),
			'intent failed'         => array( AgentWPConfig::ERROR_CODE_INTENT_FAILED, 500 ),
			'service unavailable'   => array( AgentWPConfig::ERROR_CODE_SERVICE_UNAVAILABLE, 500 ),
		);
	}

	// =========================================================================
	// Settings Endpoint (/settings) Envelope Tests
	// =========================================================================

	/**
	 * Test /settings GET success response envelope shape.
	 */
	public function test_settings_get_success_envelope_shape(): void {
		$data = array(
			'settings'       => array(
				'model'             => 'gpt-4o-mini',
				'budget_limit'      => 100.0,
				'draft_ttl_minutes' => 60,
				'hotkey'            => '',
				'theme'             => 'light',
				'demo_mode'         => false,
			),
			'api_key_last4'  => 'xxxx',
			'has_api_key'    => true,
			'api_key_status' => 'stored',
		);

		$response = $this->build_success_response( $data );
		$body     = $response->get_data();

		$this->assertTrue( $body['success'] );
		$this->assertArrayHasKey( 'settings', $body['data'] );
		$this->assertArrayHasKey( 'api_key_last4', $body['data'] );
		$this->assertArrayHasKey( 'has_api_key', $body['data'] );
		$this->assertArrayHasKey( 'api_key_status', $body['data'] );
	}

	/**
	 * Test /settings POST success response envelope shape.
	 */
	public function test_settings_update_success_envelope_shape(): void {
		$data = array(
			'updated'  => true,
			'settings' => array(
				'model'  => 'gpt-4o',
				'theme'  => 'dark',
			),
		);

		$response = $this->build_success_response( $data );
		$body     = $response->get_data();

		$this->assertTrue( $body['success'] );
		$this->assertArrayHasKey( 'updated', $body['data'] );
		$this->assertArrayHasKey( 'settings', $body['data'] );
		$this->assertTrue( $body['data']['updated'] );
	}

	/**
	 * Test /settings validation error codes.
	 */
	public function test_settings_validation_error_code(): void {
		$response = $this->build_error_response(
			AgentWPConfig::ERROR_CODE_INVALID_REQUEST,
			'Invalid model value.',
			400
		);
		$body = $response->get_data();

		$this->assertFalse( $body['success'] );
		$this->assertSame( AgentWPConfig::ERROR_CODE_INVALID_REQUEST, $body['error']['code'] );
	}

	// =========================================================================
	// API Key Endpoint (/settings/api-key) Envelope Tests
	// =========================================================================

	/**
	 * Test /settings/api-key store success response envelope shape.
	 */
	public function test_api_key_store_success_envelope_shape(): void {
		$data = array(
			'stored' => true,
			'last4'  => 'abcd',
		);

		$response = $this->build_success_response( $data );
		$body     = $response->get_data();

		$this->assertTrue( $body['success'] );
		$this->assertArrayHasKey( 'stored', $body['data'] );
		$this->assertArrayHasKey( 'last4', $body['data'] );
		$this->assertTrue( $body['data']['stored'] );
	}

	/**
	 * Test /settings/api-key delete success response envelope shape.
	 */
	public function test_api_key_delete_success_envelope_shape(): void {
		$data = array(
			'stored' => false,
			'last4'  => '',
		);

		$response = $this->build_success_response( $data );
		$body     = $response->get_data();

		$this->assertTrue( $body['success'] );
		$this->assertFalse( $body['data']['stored'] );
		$this->assertSame( '', $body['data']['last4'] );
	}

	/**
	 * Test /settings/api-key error codes.
	 *
	 * @dataProvider apiKeyErrorCodeProvider
	 */
	public function test_api_key_error_codes( string $error_code, int $expected_status ): void {
		$response = $this->build_error_response( $error_code, 'Test message', $expected_status );
		$body     = $response->get_data();

		$this->assertFalse( $body['success'] );
		$this->assertSame( $error_code, $body['error']['code'] );
		$this->assertSame( $expected_status, $response->get_status() );
	}

	/**
	 * Data provider for API key error codes.
	 *
	 * @return array
	 */
	public static function apiKeyErrorCodeProvider(): array {
		return array(
			'invalid request'     => array( AgentWPConfig::ERROR_CODE_INVALID_REQUEST, 400 ),
			'invalid key format'  => array( AgentWPConfig::ERROR_CODE_INVALID_KEY, 400 ),
			'encryption failed'   => array( AgentWPConfig::ERROR_CODE_ENCRYPTION_FAILED, 500 ),
		);
	}

	// =========================================================================
	// Analytics Endpoint (/analytics) Envelope Tests
	// =========================================================================

	/**
	 * Test /analytics success response envelope shape.
	 */
	public function test_analytics_success_envelope_shape(): void {
		$data = array(
			'period'       => '7d',
			'total_tokens' => 50000,
			'total_cost'   => 0.25,
			'daily_trend'  => array(),
		);

		$response = $this->build_success_response( $data );
		$body     = $response->get_data();

		$this->assertTrue( $body['success'] );
		$this->assertIsArray( $body['data'] );
	}

	/**
	 * Test /analytics service unavailable error code.
	 */
	public function test_analytics_service_unavailable_error_code(): void {
		$response = $this->build_error_response(
			AgentWPConfig::ERROR_CODE_SERVICE_UNAVAILABLE,
			'Analytics service unavailable.',
			500
		);
		$body = $response->get_data();

		$this->assertFalse( $body['success'] );
		$this->assertSame( AgentWPConfig::ERROR_CODE_SERVICE_UNAVAILABLE, $body['error']['code'] );
		$this->assertSame( 500, $response->get_status() );
	}

	// =========================================================================
	// History Endpoint (/history) Envelope Tests
	// =========================================================================

	/**
	 * Test /history GET success response envelope shape.
	 */
	public function test_history_get_success_envelope_shape(): void {
		$data = array(
			'history'   => array(
				array(
					'raw_input'      => 'find order 123',
					'parsed_intent'  => 'order_search',
					'timestamp'      => '2025-01-17T12:00:00Z',
					'was_successful' => true,
				),
			),
			'favorites' => array(),
		);

		$response = $this->build_success_response( $data );
		$body     = $response->get_data();

		$this->assertTrue( $body['success'] );
		$this->assertArrayHasKey( 'history', $body['data'] );
		$this->assertArrayHasKey( 'favorites', $body['data'] );
		$this->assertIsArray( $body['data']['history'] );
		$this->assertIsArray( $body['data']['favorites'] );
	}

	/**
	 * Test /history POST success response envelope shape.
	 */
	public function test_history_update_success_envelope_shape(): void {
		$data = array(
			'updated'   => true,
			'history'   => array(),
			'favorites' => array(),
		);

		$response = $this->build_success_response( $data );
		$body     = $response->get_data();

		$this->assertTrue( $body['success'] );
		$this->assertArrayHasKey( 'updated', $body['data'] );
		$this->assertTrue( $body['data']['updated'] );
	}

	/**
	 * Test /history validation error code.
	 */
	public function test_history_validation_error_code(): void {
		$response = $this->build_error_response(
			AgentWPConfig::ERROR_CODE_INVALID_REQUEST,
			'Invalid history format.',
			400
		);
		$body = $response->get_data();

		$this->assertFalse( $body['success'] );
		$this->assertSame( AgentWPConfig::ERROR_CODE_INVALID_REQUEST, $body['error']['code'] );
	}

	// =========================================================================
	// Theme Endpoint (/theme) Envelope Tests
	// =========================================================================

	/**
	 * Test /theme GET success response envelope shape.
	 */
	public function test_theme_get_success_envelope_shape(): void {
		$data = array(
			'theme' => 'light',
		);

		$response = $this->build_success_response( $data );
		$body     = $response->get_data();

		$this->assertTrue( $body['success'] );
		$this->assertArrayHasKey( 'theme', $body['data'] );
	}

	/**
	 * Test /theme POST success response envelope shape.
	 */
	public function test_theme_update_success_envelope_shape(): void {
		$data = array(
			'updated' => true,
			'theme'   => 'dark',
		);

		$response = $this->build_success_response( $data );
		$body     = $response->get_data();

		$this->assertTrue( $body['success'] );
		$this->assertArrayHasKey( 'updated', $body['data'] );
		$this->assertArrayHasKey( 'theme', $body['data'] );
		$this->assertTrue( $body['data']['updated'] );
	}

	/**
	 * Test /theme validation error codes.
	 *
	 * @dataProvider themeErrorCodeProvider
	 */
	public function test_theme_error_codes( string $error_code, int $expected_status ): void {
		$response = $this->build_error_response( $error_code, 'Test message', $expected_status );
		$body     = $response->get_data();

		$this->assertFalse( $body['success'] );
		$this->assertSame( $error_code, $body['error']['code'] );
		$this->assertSame( $expected_status, $response->get_status() );
	}

	/**
	 * Data provider for theme error codes.
	 *
	 * @return array
	 */
	public static function themeErrorCodeProvider(): array {
		return array(
			'invalid request' => array( AgentWPConfig::ERROR_CODE_INVALID_REQUEST, 400 ),
			'invalid theme'   => array( AgentWPConfig::ERROR_CODE_INVALID_THEME, 400 ),
		);
	}

	// =========================================================================
	// Common Error Codes Tests (Auth, Rate Limit, etc.)
	// =========================================================================

	/**
	 * Test authentication error codes.
	 *
	 * @dataProvider authErrorCodeProvider
	 */
	public function test_auth_error_codes( string $error_code, int $expected_status ): void {
		$response = $this->build_error_response( $error_code, 'Test message', $expected_status );
		$body     = $response->get_data();

		$this->assertFalse( $body['success'] );
		$this->assertSame( $error_code, $body['error']['code'] );
		$this->assertSame( $expected_status, $response->get_status() );
	}

	/**
	 * Data provider for authentication error codes.
	 *
	 * @return array
	 */
	public static function authErrorCodeProvider(): array {
		return array(
			'forbidden'     => array( AgentWPConfig::ERROR_CODE_FORBIDDEN, 403 ),
			'unauthorized'  => array( AgentWPConfig::ERROR_CODE_UNAUTHORIZED, 401 ),
			'missing nonce' => array( AgentWPConfig::ERROR_CODE_MISSING_NONCE, 403 ),
			'invalid nonce' => array( AgentWPConfig::ERROR_CODE_INVALID_NONCE, 403 ),
		);
	}

	/**
	 * Test rate limit error code.
	 */
	public function test_rate_limit_error_code(): void {
		$response = $this->build_error_response(
			AgentWPConfig::ERROR_CODE_RATE_LIMITED,
			'Rate limit exceeded.',
			429,
			array( 'retry_after' => 60 )
		);
		$body = $response->get_data();

		$this->assertFalse( $body['success'] );
		$this->assertSame( AgentWPConfig::ERROR_CODE_RATE_LIMITED, $body['error']['code'] );
		$this->assertSame( 429, $response->get_status() );
		$this->assertArrayHasKey( 'meta', $body['error'] );
		$this->assertSame( 60, $body['error']['meta']['retry_after'] );
	}

	/**
	 * Test API/Network error codes.
	 *
	 * @dataProvider networkErrorCodeProvider
	 */
	public function test_network_error_codes( string $error_code, int $expected_status ): void {
		$response = $this->build_error_response( $error_code, 'Test message', $expected_status );
		$body     = $response->get_data();

		$this->assertFalse( $body['success'] );
		$this->assertSame( $error_code, $body['error']['code'] );
	}

	/**
	 * Data provider for network error codes.
	 *
	 * @return array
	 */
	public static function networkErrorCodeProvider(): array {
		return array(
			'api error'           => array( AgentWPConfig::ERROR_CODE_API_ERROR, 500 ),
			'network error'       => array( AgentWPConfig::ERROR_CODE_NETWORK_ERROR, 500 ),
			'openai unreachable'  => array( AgentWPConfig::ERROR_CODE_OPENAI_UNREACHABLE, 502 ),
			'openai invalid'      => array( AgentWPConfig::ERROR_CODE_OPENAI_INVALID, 502 ),
		);
	}

	// =========================================================================
	// Error Code Constants Existence Tests
	// =========================================================================

	/**
	 * Test that all expected error code constants exist.
	 */
	public function test_error_code_constants_exist(): void {
		// Authentication/Authorization.
		$this->assertSame( 'agentwp_forbidden', AgentWPConfig::ERROR_CODE_FORBIDDEN );
		$this->assertSame( 'agentwp_unauthorized', AgentWPConfig::ERROR_CODE_UNAUTHORIZED );
		$this->assertSame( 'agentwp_missing_nonce', AgentWPConfig::ERROR_CODE_MISSING_NONCE );
		$this->assertSame( 'agentwp_invalid_nonce', AgentWPConfig::ERROR_CODE_INVALID_NONCE );
		$this->assertSame( 'agentwp_invalid_key', AgentWPConfig::ERROR_CODE_INVALID_KEY );

		// Validation.
		$this->assertSame( 'agentwp_invalid_request', AgentWPConfig::ERROR_CODE_INVALID_REQUEST );
		$this->assertSame( 'agentwp_validation_error', AgentWPConfig::ERROR_CODE_VALIDATION_ERROR );
		$this->assertSame( 'agentwp_missing_prompt', AgentWPConfig::ERROR_CODE_MISSING_PROMPT );
		$this->assertSame( 'agentwp_invalid_period', AgentWPConfig::ERROR_CODE_INVALID_PERIOD );
		$this->assertSame( 'agentwp_invalid_theme', AgentWPConfig::ERROR_CODE_INVALID_THEME );

		// API/Network.
		$this->assertSame( 'agentwp_rate_limited', AgentWPConfig::ERROR_CODE_RATE_LIMITED );
		$this->assertSame( 'agentwp_api_error', AgentWPConfig::ERROR_CODE_API_ERROR );
		$this->assertSame( 'agentwp_network_error', AgentWPConfig::ERROR_CODE_NETWORK_ERROR );
		$this->assertSame( 'agentwp_intent_failed', AgentWPConfig::ERROR_CODE_INTENT_FAILED );
		$this->assertSame( 'agentwp_openai_unreachable', AgentWPConfig::ERROR_CODE_OPENAI_UNREACHABLE );
		$this->assertSame( 'agentwp_openai_invalid', AgentWPConfig::ERROR_CODE_OPENAI_INVALID );
		$this->assertSame( 'agentwp_encryption_failed', AgentWPConfig::ERROR_CODE_ENCRYPTION_FAILED );
		$this->assertSame( 'agentwp_service_unavailable', AgentWPConfig::ERROR_CODE_SERVICE_UNAVAILABLE );
	}

	// =========================================================================
	// Helper Methods
	// =========================================================================

	/**
	 * Build a success response in the standard envelope format.
	 *
	 * @param mixed $data   Response data.
	 * @param int   $status HTTP status code.
	 * @return WP_REST_Response
	 */
	private function build_success_response( $data, int $status = 200 ): WP_REST_Response {
		$response = new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $data,
			)
		);
		$response->set_status( $status );
		return $response;
	}

	/**
	 * Build an error response in the standard envelope format.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @param int    $status  HTTP status code.
	 * @param array  $meta    Optional error metadata.
	 * @return WP_REST_Response
	 */
	private function build_error_response( string $code, string $message, int $status = 400, array $meta = array() ): WP_REST_Response {
		$error = array(
			'code'    => $code,
			'message' => $message,
			'type'    => $this->categorize_error( $code, $status ),
		);

		if ( ! empty( $meta ) ) {
			$error['meta'] = $meta;
		}

		$response = new WP_REST_Response(
			array(
				'success' => false,
				'data'    => array(),
				'error'   => $error,
			)
		);
		$response->set_status( $status );
		return $response;
	}

	/**
	 * Categorize error type based on code and status.
	 *
	 * Mirrors the logic in AgentWP\Error\Handler::categorize().
	 *
	 * @param string $code   Error code.
	 * @param int    $status HTTP status code.
	 * @return string Error type.
	 */
	private function categorize_error( string $code, int $status ): string {
		if ( 429 === $status || str_contains( $code, 'rate_limit' ) ) {
			return 'rate_limit';
		}

		if ( in_array( $status, array( 401, 403 ), true ) || str_contains( $code, 'auth' ) || str_contains( $code, 'nonce' ) || str_contains( $code, 'forbidden' ) ) {
			return 'auth_error';
		}

		if ( 400 === $status || str_contains( $code, 'invalid' ) || str_contains( $code, 'validation' ) || str_contains( $code, 'missing' ) ) {
			return 'validation_error';
		}

		if ( str_contains( $code, 'network' ) || str_contains( $code, 'unreachable' ) ) {
			return 'network_error';
		}

		if ( $status >= 500 || str_contains( $code, 'api' ) || str_contains( $code, 'failed' ) ) {
			return 'api_error';
		}

		return 'unknown';
	}
}
