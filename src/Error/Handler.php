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
