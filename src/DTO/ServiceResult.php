<?php
/**
 * Service result value object.
 *
 * @package AgentWP\DTO
 */

namespace AgentWP\DTO;

/**
 * Standardized result object for service operations.
 *
 * Provides a consistent response format across all services with
 * success/error states, messages, data payloads, and HTTP-style codes.
 */
final class ServiceResult {

	private bool $success;
	private string $message;
	private array $data;
	private int $code;

	/**
	 * Create a new ServiceResult.
	 *
	 * @param bool   $success Whether the operation succeeded.
	 * @param string $message Human-readable message.
	 * @param array  $data    Additional data payload.
	 * @param int    $code    HTTP-style status code.
	 */
	private function __construct( bool $success, string $message, array $data = array(), int $code = 200 ) {
		$this->success = $success;
		$this->message = $message;
		$this->data    = $data;
		$this->code    = $code;
	}

	/**
	 * Create a success result.
	 *
	 * @param string $message Success message.
	 * @param array  $data    Additional data payload.
	 * @return self
	 */
	public static function success( string $message = '', array $data = array() ): self {
		return new self( true, $message, $data, 200 );
	}

	/**
	 * Create a failure result.
	 *
	 * @param string $message Error message.
	 * @param int    $code    HTTP-style error code.
	 * @param array  $data    Additional error context.
	 * @return self
	 */
	public static function failure( string $message, int $code = 400, array $data = array() ): self {
		return new self( false, $message, $data, $code );
	}

	/**
	 * Alias for failure() for better semantic clarity.
	 *
	 * Use this method when you want to explicitly indicate an error condition.
	 * The method is semantically equivalent to failure() but can make code more readable.
	 *
	 * Usage examples:
	 * ```php
	 * // Validation errors
	 * return ServiceResult::error('Invalid email address', 422);
	 *
	 * // Not found errors
	 * return ServiceResult::error('Resource not found', 404);
	 *
	 * // Server errors
	 * return ServiceResult::error('Database connection failed', 500);
	 *
	 * // With additional context
	 * return ServiceResult::error('Payment failed', 402, [
	 *     'transaction_id' => $txn->id,
	 *     'reason' => 'insufficient_funds'
	 * ]);
	 * ```
	 *
	 * @param string $message Error message.
	 * @param int    $code    HTTP-style error code (default: 400).
	 * @param array  $data    Additional error context (default: empty array).
	 * @return self Service result instance.
	 */
	public static function error( string $message, int $code = 400, array $data = array() ): self {
		return self::failure( $message, $code, $data );
	}

	/**
	 * Create a not found result.
	 *
	 * @param string $message Not found message.
	 * @return self
	 */
	public static function notFound( string $message ): self {
		return new self( false, $message, array(), 404 );
	}

	/**
	 * Create a permission denied result.
	 *
	 * @param string $message Permission denied message.
	 * @return self
	 */
	public static function forbidden( string $message = 'Permission denied.' ): self {
		return new self( false, $message, array(), 403 );
	}

	/**
	 * Create a validation error result.
	 *
	 * @param string $message Validation error message.
	 * @param array  $errors  Specific validation errors.
	 * @return self
	 */
	public static function validationError( string $message, array $errors = array() ): self {
		return new self( false, $message, array( 'errors' => $errors ), 422 );
	}

	/**
	 * Create a server error result.
	 *
	 * @param string $message Error message.
	 * @return self
	 */
	public static function serverError( string $message = 'An internal error occurred.' ): self {
		return new self( false, $message, array(), 500 );
	}

	/**
	 * Check if the operation was successful.
	 *
	 * @return bool
	 */
	public function isSuccess(): bool {
		return $this->success;
	}

	/**
	 * Check if the operation failed.
	 *
	 * @return bool
	 */
	public function isFailure(): bool {
		return ! $this->success;
	}

	/**
	 * Get the message.
	 *
	 * @return string
	 */
	public function getMessage(): string {
		return $this->message;
	}

	/**
	 * Get the data payload.
	 *
	 * @return array
	 */
	public function getData(): array {
		return $this->data;
	}

	/**
	 * Get a specific data value.
	 *
	 * @param string $key     Data key.
	 * @param mixed  $default Default value if key doesn't exist.
	 * @return mixed
	 */
	public function get( string $key, $default = null ) {
		return $this->data[ $key ] ?? $default;
	}

	/**
	 * Get the status code.
	 *
	 * @return int
	 */
	public function getCode(): int {
		return $this->code;
	}

	/**
	 * Convert to array format (for API responses).
	 *
	 * @return array
	 */
	public function toArray(): array {
		$result = array(
			'success' => $this->success,
		);

		if ( '' !== $this->message ) {
			$result['message'] = $this->message;
		}

		if ( ! $this->success ) {
			$result['error'] = $this->message;
			$result['code']  = $this->code;
		}

		if ( ! empty( $this->data ) ) {
			$result = array_merge( $result, $this->data );
		}

		return $result;
	}

	/**
	 * Create a result with additional data merged.
	 *
	 * @param array $data Additional data to merge.
	 * @return self
	 */
	public function withData( array $data ): self {
		return new self(
			$this->success,
			$this->message,
			array_merge( $this->data, $data ),
			$this->code
		);
	}
}
