<?php
/**
 * Value object for tool argument validation results.
 *
 * @package AgentWP\Validation
 */

namespace AgentWP\Validation;

/**
 * Encapsulates the result of tool argument validation.
 *
 * Provides a consistent structure for both valid and invalid results,
 * enabling handlers to properly respond to the AI with validation errors.
 */
class ToolArgumentValidationResult {

	/**
	 * Tool name that was validated.
	 *
	 * @var string
	 */
	public string $toolName;

	/**
	 * Whether validation passed.
	 *
	 * @var bool
	 */
	public bool $isValid;

	/**
	 * Validation errors if invalid.
	 *
	 * @var array<array{field: string, message: string, code: string}>
	 */
	public array $errors;

	/**
	 * Create a new validation result.
	 *
	 * @param string $toolName Tool name.
	 * @param bool   $isValid  Whether valid.
	 * @param array  $errors   Validation errors.
	 */
	private function __construct( string $toolName, bool $isValid, array $errors = array() ) {
		$this->toolName = $toolName;
		$this->isValid  = $isValid;
		$this->errors   = $errors;
	}

	/**
	 * Create a valid result.
	 *
	 * @param string $toolName Tool name.
	 * @return self
	 */
	public static function valid( string $toolName ): self {
		return new self( $toolName, true );
	}

	/**
	 * Create an invalid result with errors.
	 *
	 * @param string $toolName Tool name.
	 * @param array  $errors   Validation errors.
	 * @return self
	 */
	public static function invalid( string $toolName, array $errors ): self {
		return new self( $toolName, false, $errors );
	}

	/**
	 * Convert to error response array for the AI.
	 *
	 * Returns a consistent error shape that can be JSON-encoded
	 * and returned to the AI as a tool result.
	 *
	 * @return array{success: false, error: string, code: string, validation_errors: array}
	 */
	public function toErrorArray(): array {
		$messages = array_map(
			function ( array $error ): string {
				if ( ! empty( $error['field'] ) ) {
					return sprintf( '%s: %s', $error['field'], $error['message'] );
				}
				return $error['message'];
			},
			$this->errors
		);

		return array(
			'success'           => false,
			'error'             => sprintf(
				'Invalid arguments for tool "%s": %s',
				$this->toolName,
				implode( '; ', $messages )
			),
			'code'              => 'invalid_tool_arguments',
			'validation_errors' => $this->errors,
		);
	}
}
