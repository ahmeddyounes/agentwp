<?php
/**
 * WooCommerce logger adapter.
 *
 * @package AgentWP\Infrastructure
 */

namespace AgentWP\Infrastructure;

use AgentWP\Contracts\LoggerInterface;

/**
 * Logger implementation using WooCommerce logger with error_log fallback.
 *
 * Uses WooCommerce's logging system when available, otherwise falls back
 * to PHP's error_log. All messages are sanitized to prevent leaking secrets.
 */
final class WooCommerceLogger implements LoggerInterface {

	/**
	 * Log source identifier.
	 *
	 * @var string
	 */
	private string $source;

	/**
	 * Patterns that match sensitive data to redact.
	 *
	 * @var array<string>
	 */
	private const SECRET_PATTERNS = array(
		// OpenAI API keys (including project keys like sk-proj-...).
		'/sk-[a-zA-Z0-9\-_]{20,}/',
		// Stripe-style keys (sk_test_ / sk_live_).
		'/sk_(?:live|test)_[a-zA-Z0-9]{10,}/',
		// Generic API key patterns.
		'/(?:x[-_])?api[ _-]?key["\']?\s*[:=]\s*["\']?[^\s"\']+["\']?/i',
		// Client secrets.
		'/client[_-]?secret["\']?\s*[:=]\s*["\']?[^\s"\']+["\']?/i',
		// Bearer tokens.
		'/Bearer\s+[A-Za-z0-9\-._~+/=:]+/i',
		// Basic auth tokens.
		'/Basic\s+[A-Za-z0-9+/=]+/i',
		// Authorization headers.
		'/Authorization["\']?\s*[:=]\s*["\']?(?:[A-Za-z]+\s+)?[A-Za-z0-9\-._~+/=:]+["\']?/i',
		// Password patterns.
		'/password["\']?\s*[:=]\s*["\']?[^\s"\']+["\']?/i',
		// Secret patterns.
		'/secret["\']?\s*[:=]\s*["\']?[^\s"\']+["\']?/i',
		// Token patterns.
		'/token["\']?\s*[:=]\s*["\']?[^\s"\']+["\']?/i',
		// Cookie headers.
		'/(?:set-cookie|cookie)["\']?\s*[:=]\s*["\']?[^"\r\n]+["\']?/i',
		// JWT tokens without labels.
		'/eyJ[a-zA-Z0-9\-_]+\.[a-zA-Z0-9\-_]+\.[a-zA-Z0-9\-_]+/',
		// Credit card patterns (basic).
		'/\b\d{4}[\s\-]?\d{4}[\s\-]?\d{4}[\s\-]?\d{4}\b/',
		// PEM private keys.
		'/-----BEGIN [A-Z ]+PRIVATE KEY-----.*?-----END [A-Z ]+PRIVATE KEY-----/s',
	);

	/**
	 * Replacement string for redacted content.
	 *
	 * @var string
	 */
	private const REDACTED = '[REDACTED]';

	/**
	 * Create a new WooCommerceLogger.
	 *
	 * @param string $source Log source identifier.
	 */
	public function __construct( string $source = 'agentwp' ) {
		$this->source = $source;
	}

	/**
	 * {@inheritDoc}
	 */
	public function emergency( string $message, array $context = array() ): void {
		$this->log( 'emergency', $message, $context );
	}

	/**
	 * {@inheritDoc}
	 */
	public function alert( string $message, array $context = array() ): void {
		$this->log( 'alert', $message, $context );
	}

	/**
	 * {@inheritDoc}
	 */
	public function critical( string $message, array $context = array() ): void {
		$this->log( 'critical', $message, $context );
	}

	/**
	 * {@inheritDoc}
	 */
	public function error( string $message, array $context = array() ): void {
		$this->log( 'error', $message, $context );
	}

	/**
	 * {@inheritDoc}
	 */
	public function warning( string $message, array $context = array() ): void {
		$this->log( 'warning', $message, $context );
	}

	/**
	 * {@inheritDoc}
	 */
	public function notice( string $message, array $context = array() ): void {
		$this->log( 'notice', $message, $context );
	}

	/**
	 * {@inheritDoc}
	 */
	public function info( string $message, array $context = array() ): void {
		$this->log( 'info', $message, $context );
	}

	/**
	 * {@inheritDoc}
	 */
	public function debug( string $message, array $context = array() ): void {
		$this->log( 'debug', $message, $context );
	}

	/**
	 * Log a message at the specified level.
	 *
	 * @param string               $level   Log level.
	 * @param string               $message Log message.
	 * @param array<string, mixed> $context Additional context data.
	 * @return void
	 */
	private function log( string $level, string $message, array $context ): void {
		$sanitized_message = $this->sanitize( $message );
		$sanitized_context = $this->sanitizeContext( $context );
		$sanitized_context['source'] = $this->source;

		if ( function_exists( 'wc_get_logger' ) ) {
			$this->logToWooCommerce( $level, $sanitized_message, $sanitized_context );
			return;
		}

		$this->logToErrorLog( $level, $sanitized_message, $sanitized_context );
	}

	/**
	 * Log to WooCommerce logger.
	 *
	 * @param string               $level   Log level.
	 * @param string               $message Log message.
	 * @param array<string, mixed> $context Context data.
	 * @return void
	 */
	private function logToWooCommerce( string $level, string $message, array $context ): void {
		$logger = wc_get_logger();

		switch ( $level ) {
			case 'emergency':
				$logger->emergency( $message, $context );
				break;
			case 'alert':
				$logger->alert( $message, $context );
				break;
			case 'critical':
				$logger->critical( $message, $context );
				break;
			case 'error':
				$logger->error( $message, $context );
				break;
			case 'warning':
				$logger->warning( $message, $context );
				break;
			case 'notice':
				$logger->notice( $message, $context );
				break;
			case 'info':
				$logger->info( $message, $context );
				break;
			case 'debug':
			default:
				$logger->debug( $message, $context );
				break;
		}
	}

	/**
	 * Log to PHP error_log as fallback.
	 *
	 * @param string               $level   Log level.
	 * @param string               $message Log message.
	 * @param array<string, mixed> $context Context data.
	 * @return void
	 */
	private function logToErrorLog( string $level, string $message, array $context ): void {
		$formatted = sprintf(
			'[%s] %s.%s: %s',
			gmdate( 'Y-m-d H:i:s' ),
			$this->source,
			strtoupper( $level ),
			$message
		);

		// Add context if present (excluding source which is already in format).
		$context_without_source = $context;
		unset( $context_without_source['source'] );

		if ( ! empty( $context_without_source ) ) {
			$formatted .= ' ' . wp_json_encode( $context_without_source );
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Fallback when WC logger unavailable.
		error_log( $formatted );
	}

	/**
	 * Sanitize a message to remove sensitive data.
	 *
	 * @param string $message Message to sanitize.
	 * @return string Sanitized message.
	 */
	private function sanitize( string $message ): string {
		foreach ( self::SECRET_PATTERNS as $pattern ) {
			$message = preg_replace( $pattern, self::REDACTED, $message ) ?? $message;
		}

		return $message;
	}

	/**
	 * Recursively sanitize context array.
	 *
	 * @param array<string, mixed> $context Context to sanitize.
	 * @return array<string, mixed> Sanitized context.
	 */
	private function sanitizeContext( array $context ): array {
		$sanitized = array();

		foreach ( $context as $key => $value ) {
			// Redact keys that are likely to contain secrets.
			if ( is_string( $key ) ) {
				$lower_key = strtolower( $key );
				if ( $this->isSensitiveKey( $lower_key ) ) {
					$sanitized[ $key ] = self::REDACTED;
					continue;
				}
			}

			if ( is_string( $value ) ) {
				$sanitized[ $key ] = $this->sanitize( $value );
			} elseif ( is_array( $value ) ) {
				$sanitized[ $key ] = $this->sanitizeContext( $value );
			} else {
				$sanitized[ $key ] = $value;
			}
		}

		return $sanitized;
	}

	/**
	 * Check if a key name indicates sensitive data.
	 *
	 * @param string $key Key name (lowercase).
	 * @return bool True if sensitive.
	 */
	private function isSensitiveKey( string $key ): bool {
		$sensitive_keys = array(
			'api_key',
			'apikey',
			'api-key',
			'client_secret',
			'clientsecret',
			'client-secret',
			'secret',
			'secret_key',
			'secretkey',
			'secret-key',
			'password',
			'passwd',
			'token',
			'id_token',
			'idtoken',
			'auth',
			'authorization',
			'bearer',
			'credential',
			'credentials',
			'private_key',
			'privatekey',
			'private-key',
			'cookie',
			'set-cookie',
			'session_id',
			'sessionid',
			'session-token',
			'access_token',
			'accesstoken',
			'access-token',
			'refresh_token',
			'refreshtoken',
			'refresh-token',
			'csrf',
			'xsrf',
			'signature',
			'openai_key',
			'openai-key',
			'sk-',
		);

		foreach ( $sensitive_keys as $sensitive ) {
			if ( str_contains( $key, $sensitive ) ) {
				return true;
			}
		}

		return false;
	}
}
