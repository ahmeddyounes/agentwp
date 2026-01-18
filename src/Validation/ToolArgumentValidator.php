<?php
/**
 * Validates tool arguments against their JSON schemas.
 *
 * @package AgentWP\Validation
 */

namespace AgentWP\Validation;

use AgentWP\AI\Functions\FunctionSchema;
use WP_Error;

/**
 * Validator for AI tool call arguments.
 *
 * Uses WordPress REST API schema validation to ensure tool arguments
 * conform to their defined JSON schemas before execution.
 */
class ToolArgumentValidator {

	/**
	 * Validate tool arguments against the function schema.
	 *
	 * @param FunctionSchema $schema    Function schema to validate against.
	 * @param array          $arguments Arguments to validate.
	 * @return ToolArgumentValidationResult
	 */
	public function validate( FunctionSchema $schema, array $arguments ): ToolArgumentValidationResult {
		$param_schema = $schema->get_parameters();

		// Ensure schema has a type.
		if ( ! isset( $param_schema['type'] ) ) {
			$param_schema['type'] = 'object';
		}

		$result = rest_validate_value_from_schema( $arguments, $param_schema, $schema->get_name() );

		if ( is_wp_error( $result ) ) {
			return ToolArgumentValidationResult::invalid(
				$schema->get_name(),
				$this->formatErrors( $result )
			);
		}

		return ToolArgumentValidationResult::valid( $schema->get_name() );
	}

	/**
	 * Format WP_Error into a consistent array of error messages.
	 *
	 * @param WP_Error $error Validation error.
	 * @return array<array{field: string, message: string, code: string}>
	 */
	private function formatErrors( WP_Error $error ): array {
		$errors = array();

		foreach ( $error->get_error_codes() as $code ) {
			$messages = $error->get_error_messages( $code );
			$data     = $error->get_error_data( $code );

			foreach ( $messages as $message ) {
				$field = '';
				// Try to extract field name from error data or message.
				if ( is_array( $data ) && isset( $data['param'] ) ) {
					$field = (string) $data['param'];
				}

				$errors[] = array(
					'field'   => $field,
					'message' => $message,
					'code'    => (string) $code,
				);
			}
		}

		return $errors;
	}
}
