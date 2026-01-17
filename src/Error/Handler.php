<?php
/**
 * Error categorization helper.
 *
 * @package AgentWP
 */

namespace AgentWP\Error;

class Handler {
	const TYPE_NETWORK    = 'network_error';
	const TYPE_API        = 'api_error';
	const TYPE_RATE_LIMIT = 'rate_limit';
	const TYPE_AUTH       = 'auth_error';
	const TYPE_VALIDATION = 'validation_error';
	const TYPE_UNKNOWN    = 'unknown';

	/**
	 * Categorize errors into user-facing buckets.
	 *
	 * @param string $code Error code.
	 * @param int    $status HTTP status.
	 * @param string $message Error message.
	 * @param array  $meta Optional error metadata.
	 * @return string
	 */
	public static function categorize( $code, $status = 0, $message = '', array $meta = array() ) {
		$status   = (int) $status;
		$code     = is_string( $code ) ? strtolower( $code ) : '';
		$message  = is_string( $message ) ? strtolower( $message ) : '';
		$meta_code = isset( $meta['error_code'] ) ? strtolower( (string) $meta['error_code'] ) : '';
		$meta_type = isset( $meta['error_type'] ) ? strtolower( (string) $meta['error_type'] ) : '';

		$codes = array_filter( array( $code, $meta_code, $meta_type ) );

		if (
			0 === $status
			|| self::contains_any( $codes, array( 'http_request_failed', 'connection_failed', 'timeout', 'timed_out' ) )
			|| false !== strpos( $message, 'timeout' )
			|| false !== strpos( $message, 'timed out' )
		) {
			return self::TYPE_NETWORK;
		}

		if (
			429 === $status
			|| self::contains_any( $codes, array( 'rate_limit', 'rate_limited', 'rate_limit_exceeded', 'rate_limit_error' ) )
		) {
			return self::TYPE_RATE_LIMIT;
		}

		if (
			in_array( $status, array( 401, 403 ), true )
			|| self::contains_any(
				$codes,
				array( 'auth', 'forbidden', 'invalid_api_key', 'invalid_key', 'authentication_error', 'insufficient_quota' )
			)
		) {
			return self::TYPE_AUTH;
		}

		if (
			in_array( $status, array( 400, 422 ), true )
			|| self::contains_any(
				$codes,
				array(
					'invalid',
					'missing',
					'validation',
					'invalid_request_error',
					'context_length_exceeded',
				)
			)
		) {
			return self::TYPE_VALIDATION;
		}

		if ( $status >= 500 ) {
			return self::TYPE_API;
		}

		return self::TYPE_UNKNOWN;
	}

	/**
	 * Get recovery suggestions for an error type.
	 *
	 * @param string $errorType Error type from categorize().
	 * @param string $message   Error message for additional context.
	 * @return array Array of recovery suggestions with actionable steps.
	 */
	public static function suggestRecovery( string $errorType, string $message = '' ): array {
		unset( $message );

		$suggestions = array(
			self::TYPE_RATE_LIMIT => array(
				'wait_before_retry' => true,
				'message'           => __( 'You have exceeded the rate limit. Please wait before retrying.', 'agentwp' ),
				'actions'           => array(
					'wait'                  => __( 'Wait a moment before making another request', 'agentwp' ),
					'check_limit_status'    => __( 'Check your usage statistics in AgentWP settings', 'agentwp' ),
					'upgrade_tier'          => __( 'Consider upgrading your API plan for higher limits', 'agentwp' ),
				),
			),
			self::TYPE_AUTH => array(
				'message' => __( 'Authentication failed. Please check your API credentials.', 'agentwp' ),
				'actions' => array(
					'check_api_key'         => __( 'Verify your API key in AgentWP settings', 'agentwp' ),
					'check_quota'           => __( 'Check your OpenAI account quota and billing status', 'agentwp' ),
					'regenerate_key'        => __( 'Try regenerating your API key', 'agentwp' ),
					'demo_mode'             => __( 'Use demo mode to test without an API key', 'agentwp' ),
				),
			),
			self::TYPE_NETWORK => array(
				'message' => __( 'Network error. Unable to reach the API server.', 'agentwp' ),
				'actions' => array(
					'check_connection'      => __( 'Check your internet connection', 'agentwp' ),
					'check_server_status'   => __( 'Verify OpenAI API status at status.openai.com', 'agentwp' ),
					'firewall'              => __( 'Check if your firewall is blocking requests', 'agentwp' ),
					'retry_later'           => __( 'Try again later', 'agentwp' ),
				),
			),
			self::TYPE_VALIDATION => array(
				'message' => __( 'Invalid request. Please check your input.', 'agentwp' ),
				'actions' => array(
					'check_input'           => __( 'Verify your prompt is properly formatted', 'agentwp' ),
					'check_length'          => __( 'Ensure your prompt is not too long', 'agentwp' ),
					'check_context'         => __( 'Verify context data is valid JSON', 'agentwp' ),
				),
			),
			self::TYPE_API => array(
				'message' => __( 'API server error. This is usually temporary.', 'agentwp' ),
				'actions' => array(
					'retry'                 => __( 'Try your request again', 'agentwp' ),
					'check_status'          => __( 'Check OpenAI API status page for incidents', 'agentwp' ),
					'report'                => __( 'Report the issue if it persists', 'agentwp' ),
				),
			),
			self::TYPE_UNKNOWN => array(
				'message' => __( 'An unknown error occurred.', 'agentwp' ),
				'actions' => array(
					'retry'                 => __( 'Try your request again', 'agentwp' ),
					'check_logs'            => __( 'Check browser console and server logs for details', 'agentwp' ),
					'support'               => __( 'Contact support if the issue persists', 'agentwp' ),
				),
			),
		);

		return $suggestions[ $errorType ] ?? $suggestions[ self::TYPE_UNKNOWN ];
	}

	/**
	 * Log error with structured format for debugging.
	 *
	 * @param string $code    Error code.
	 * @param string $type    Error type.
	 * @param string $message Error message.
	 * @param array  $context Additional error context.
	 * @return void
	 */
	public static function logError( string $code, string $type, string $message, array $context = array() ): void {
		$log_entry = array(
			'plugin'    => 'agentwp',
			'timestamp' => gmdate( 'c' ),
			'code'      => $code,
			'type'      => $type,
			'message'   => $message,
			'context'   => $context,
		);

		if ( function_exists( 'do_action' ) ) {
			do_action( 'agentwp_log_error', $log_entry );
		}
	}

	/**
	 * @param array $values Values to search.
	 * @param array $needles Target substrings.
	 * @return bool
	 */
	private static function contains_any( array $values, array $needles ) {
		foreach ( $values as $value ) {
			foreach ( $needles as $needle ) {
				if ( '' === $needle ) {
					continue;
				}
				if ( false !== strpos( $value, $needle ) ) {
					return true;
				}
			}
		}

		return false;
	}
}
