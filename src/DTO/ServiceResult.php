<?php
/**
 * Service Result DTO.
 *
 * @package AgentWP\DTO
 */

namespace AgentWP\DTO;

/**
 * Immutable result value object for service operations.
 *
 * Provides a uniform structure for all service outcomes including:
 * - Success/failure status
 * - Error codes for programmatic handling
 * - Human-readable messages
 * - Operation-specific payload data
 */
final class ServiceResult {

	/**
	 * Common error codes.
	 */
	public const CODE_SUCCESS           = 'success';
	public const CODE_PERMISSION_DENIED = 'permission_denied';
	public const CODE_NOT_FOUND         = 'not_found';
	public const CODE_INVALID_INPUT     = 'invalid_input';
	public const CODE_INVALID_STATE     = 'invalid_state';
	public const CODE_OPERATION_FAILED  = 'operation_failed';
	public const CODE_DRAFT_EXPIRED     = 'draft_expired';
	public const CODE_ALREADY_EXISTS    = 'already_exists';
	public const CODE_LIMIT_EXCEEDED    = 'limit_exceeded';

	/**
	 * Create a new ServiceResult.
	 *
	 * @param bool        $success Whether the operation succeeded.
	 * @param string      $code    Result code for programmatic handling.
	 * @param string      $message Human-readable message.
	 * @param array       $data    Operation-specific payload data.
	 * @param int         $httpStatus Suggested HTTP status code.
	 */
	public function __construct(
		public readonly bool $success,
		public readonly string $code,
		public readonly string $message,
		public readonly array $data = array(),
		public readonly int $httpStatus = 200,
	) {
	}

	/**
	 * Create a successful result.
	 *
	 * @param string $message Human-readable success message.
	 * @param array  $data    Operation-specific payload data.
	 * @return self
	 */
	public static function success( string $message, array $data = array() ): self {
		return new self(
			success: true,
			code: self::CODE_SUCCESS,
			message: $message,
			data: $data,
			httpStatus: 200,
		);
	}

	/**
	 * Create a failure result.
	 *
	 * @param string $code       Error code for programmatic handling.
	 * @param string $message    Human-readable error message.
	 * @param int    $httpStatus Suggested HTTP status code.
	 * @param array  $data       Additional error context.
	 * @return self
	 */
	public static function failure(
		string $code,
		string $message,
		int $httpStatus = 400,
		array $data = array()
	): self {
		return new self(
			success: false,
			code: $code,
			message: $message,
			data: $data,
			httpStatus: $httpStatus,
		);
	}

	/**
	 * Create a permission denied result.
	 *
	 * @param string $message Custom message (defaults to 'Permission denied.').
	 * @return self
	 */
	public static function permissionDenied( string $message = 'Permission denied.' ): self {
		return self::failure( self::CODE_PERMISSION_DENIED, $message, 403 );
	}

	/**
	 * Create a not found result.
	 *
	 * @param string $resource Resource type (e.g., 'Order', 'Product').
	 * @param mixed  $id       Resource identifier.
	 * @return self
	 */
	public static function notFound( string $resource, $id = null ): self {
		$message = null !== $id
			? "{$resource} #{$id} not found."
			: "{$resource} not found.";

		return self::failure( self::CODE_NOT_FOUND, $message, 404 );
	}

	/**
	 * Create an invalid input result.
	 *
	 * @param string $message Validation error message.
	 * @param array  $errors  Field-specific errors.
	 * @return self
	 */
	public static function invalidInput( string $message, array $errors = array() ): self {
		return self::failure(
			self::CODE_INVALID_INPUT,
			$message,
			400,
			empty( $errors ) ? array() : array( 'errors' => $errors )
		);
	}

	/**
	 * Create an invalid state result.
	 *
	 * @param string $message State error message.
	 * @return self
	 */
	public static function invalidState( string $message ): self {
		return self::failure( self::CODE_INVALID_STATE, $message, 409 );
	}

	/**
	 * Create a draft expired result.
	 *
	 * @param string $message Custom message.
	 * @return self
	 */
	public static function draftExpired( string $message = 'Draft expired or invalid. Please request the operation again.' ): self {
		return self::failure( self::CODE_DRAFT_EXPIRED, $message, 410 );
	}

	/**
	 * Create an operation failed result.
	 *
	 * @param string $message Failure message.
	 * @param array  $context Additional context.
	 * @return self
	 */
	public static function operationFailed( string $message, array $context = array() ): self {
		return self::failure( self::CODE_OPERATION_FAILED, $message, 500, $context );
	}

	/**
	 * Check if the result indicates success.
	 *
	 * @return bool
	 */
	public function isSuccess(): bool {
		return $this->success;
	}

	/**
	 * Check if the result indicates failure.
	 *
	 * @return bool
	 */
	public function isFailure(): bool {
		return ! $this->success;
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
	 * Convert to array format suitable for API responses.
	 *
	 * @return array
	 */
	public function toArray(): array {
		$result = array(
			'success' => $this->success,
			'code'    => $this->code,
			'message' => $this->message,
		);

		if ( ! empty( $this->data ) ) {
			$result['data'] = $this->data;
		}

		return $result;
	}

	/**
	 * Convert to legacy array format for backwards compatibility.
	 *
	 * This flattens the data into the root level and uses 'error' key for failures.
	 *
	 * @return array
	 */
	public function toLegacyArray(): array {
		if ( $this->success ) {
			return array_merge(
				array(
					'success' => true,
					'message' => $this->message,
				),
				$this->data
			);
		}

		return array_merge(
			array(
				'success' => false,
				'error'   => $this->message,
				'code'    => $this->httpStatus,
			),
			$this->data
		);
	}
}
