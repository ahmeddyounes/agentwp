<?php
/**
 * HTTP response DTO.
 *
 * @package AgentWP\DTO
 */

namespace AgentWP\DTO;

/**
 * Immutable HTTP response value object.
 */
final class HttpResponse {

	/**
	 * Create a new HttpResponse.
	 *
	 * @param bool        $success    Whether the request was successful.
	 * @param int         $statusCode HTTP status code.
	 * @param string      $body       Response body.
	 * @param array       $headers    Response headers.
	 * @param string|null $error      Error message if request failed.
	 * @param string|null $errorCode  Error code if request failed.
	 */
	public function __construct(
		public readonly bool $success,
		public readonly int $statusCode,
		public readonly string $body,
		public readonly array $headers = array(),
		public readonly ?string $error = null,
		public readonly ?string $errorCode = null,
	) {
	}

	/**
	 * Check if the response indicates a retryable error.
	 *
	 * @return bool True if retryable.
	 */
	public function isRetryable(): bool {
		// Rate limit.
		if ( 429 === $this->statusCode ) {
			return true;
		}

		// Server errors.
		if ( $this->statusCode >= 500 && $this->statusCode < 600 ) {
			return true;
		}

		// Network errors (status 0).
		if ( 0 === $this->statusCode && ! $this->success ) {
			return true;
		}

		return false;
	}

	/**
	 * Check if this is a client error (4xx).
	 *
	 * @return bool True if client error.
	 */
	public function isClientError(): bool {
		return $this->statusCode >= 400 && $this->statusCode < 500;
	}

	/**
	 * Check if this is a server error (5xx).
	 *
	 * @return bool True if server error.
	 */
	public function isServerError(): bool {
		return $this->statusCode >= 500 && $this->statusCode < 600;
	}

	/**
	 * Get the Retry-After header value in seconds.
	 *
	 * @return int|null Seconds to wait, or null if not present.
	 */
	public function getRetryAfter(): ?int {
		$value = $this->headers['retry-after'] ?? $this->headers['Retry-After'] ?? null;

		if ( null === $value ) {
			return null;
		}

		// Handle array headers (some servers return arrays).
		if ( is_array( $value ) ) {
			$value = reset( $value );
			if ( false === $value || '' === $value ) {
				return null;
			}
		}

		// If it's a number, return it (but not negative).
		if ( is_numeric( $value ) ) {
			$seconds = (int) $value;
			return $seconds >= 0 ? $seconds : null;
		}

		// If it's a date, calculate seconds from now.
		// HTTP dates are always in GMT/UTC, so parse with UTC context.
		try {
			$date = new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
			$now = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
			$seconds = $date->getTimestamp() - $now->getTimestamp();
			// Only return non-negative values; past dates mean no wait needed.
			return $seconds > 0 ? $seconds : 0;
		} catch ( \Exception $e ) {
			// Invalid date format.
		}

		return null;
	}

	/**
	 * Parse body as JSON array.
	 *
	 * @return array|null Parsed JSON array or null if invalid/not an array.
	 */
	public function json(): ?array {
		if ( '' === $this->body ) {
			return null;
		}

		$decoded = json_decode( $this->body, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return null;
		}

		// Ensure we only return arrays as the return type promises.
		if ( ! is_array( $decoded ) ) {
			return null;
		}

		return $decoded;
	}

	/**
	 * Create a successful response.
	 *
	 * @param string $body       Response body.
	 * @param int    $statusCode HTTP status code.
	 * @param array  $headers    Response headers.
	 * @return self
	 */
	public static function success(
		string $body,
		int $statusCode = 200,
		array $headers = array()
	): self {
		return new self(
			success: true,
			statusCode: $statusCode,
			body: $body,
			headers: $headers,
		);
	}

	/**
	 * Create an error response.
	 *
	 * @param string      $error      Error message.
	 * @param string|null $errorCode  Error code.
	 * @param int         $statusCode HTTP status code.
	 * @return self
	 */
	public static function error(
		string $error,
		?string $errorCode = null,
		int $statusCode = 0
	): self {
		return new self(
			success: false,
			statusCode: $statusCode,
			body: '',
			headers: array(),
			error: $error,
			errorCode: $errorCode,
		);
	}
}
