<?php
/**
 * WordPress REST API schema validation function stubs for testing.
 *
 * Provides a simplified implementation of rest_validate_value_from_schema()
 * that handles the most common validation scenarios used by tool argument validation.
 */

if ( ! function_exists( 'rest_validate_value_from_schema' ) ) {
	/**
	 * Validates a value based on a JSON schema.
	 *
	 * Simplified implementation for testing - covers the common cases:
	 * - Type validation (string, integer, number, boolean, object, array)
	 * - Required properties
	 * - Enum values
	 * - Minimum values
	 * - Additional properties (additionalProperties: false)
	 *
	 * @param mixed  $value   Value to validate.
	 * @param array  $schema  JSON schema to validate against.
	 * @param string $context Validation context (parameter name).
	 * @return true|WP_Error True if valid, WP_Error on failure.
	 */
	function rest_validate_value_from_schema( $value, array $schema, string $context = '' ) {
		$type = $schema['type'] ?? null;

		// Handle nullable.
		if ( null === $value && ( ! empty( $schema['nullable'] ) || null === $type ) ) {
			return true;
		}

		// Type validation.
		if ( $type ) {
			$valid_type = rest_validate_type( $value, $type );
			if ( is_wp_error( $valid_type ) ) {
				return new WP_Error(
					'rest_invalid_type',
					sprintf( '%s is not of type %s.', $context, $type ),
					array( 'param' => $context )
				);
			}
		}

		// Object-specific validation.
		if ( 'object' === $type && is_array( $value ) ) {
			// Required properties check.
			if ( ! empty( $schema['required'] ) && is_array( $schema['required'] ) ) {
				foreach ( $schema['required'] as $required_prop ) {
					if ( ! array_key_exists( $required_prop, $value ) ) {
						return new WP_Error(
							'rest_property_required',
							sprintf( '%s is a required property of %s.', $required_prop, $context ),
							array( 'param' => $context . '[' . $required_prop . ']' )
						);
					}
				}
			}

			// Additional properties check.
			if ( isset( $schema['additionalProperties'] ) && false === $schema['additionalProperties'] ) {
				$allowed_keys = array_keys( $schema['properties'] ?? array() );
				$extra_keys   = array_diff( array_keys( $value ), $allowed_keys );
				if ( ! empty( $extra_keys ) ) {
					return new WP_Error(
						'rest_additional_properties_forbidden',
						sprintf( '%s is not a valid property.', reset( $extra_keys ) ),
						array( 'param' => $context )
					);
				}
			}

			// Validate each property against its schema.
			if ( ! empty( $schema['properties'] ) ) {
				foreach ( $value as $prop_name => $prop_value ) {
					if ( isset( $schema['properties'][ $prop_name ] ) ) {
						$prop_schema = $schema['properties'][ $prop_name ];
						$prop_valid  = rest_validate_value_from_schema(
							$prop_value,
							$prop_schema,
							$context . '[' . $prop_name . ']'
						);
						if ( is_wp_error( $prop_valid ) ) {
							return $prop_valid;
						}
					}
				}
			}
		}

		// Enum validation.
		if ( isset( $schema['enum'] ) && is_array( $schema['enum'] ) ) {
			if ( ! in_array( $value, $schema['enum'], true ) ) {
				return new WP_Error(
					'rest_not_in_enum',
					sprintf(
						'%s is not one of %s.',
						$context,
						implode( ', ', $schema['enum'] )
					),
					array( 'param' => $context )
				);
			}
		}

		// Minimum value validation.
		if ( isset( $schema['minimum'] ) && is_numeric( $value ) ) {
			if ( $value < $schema['minimum'] ) {
				return new WP_Error(
					'rest_out_of_bounds',
					sprintf( '%s must be greater than or equal to %s.', $context, $schema['minimum'] ),
					array( 'param' => $context )
				);
			}
		}

		// Maximum value validation.
		if ( isset( $schema['maximum'] ) && is_numeric( $value ) ) {
			if ( $value > $schema['maximum'] ) {
				return new WP_Error(
					'rest_out_of_bounds',
					sprintf( '%s must be less than or equal to %s.', $context, $schema['maximum'] ),
					array( 'param' => $context )
				);
			}
		}

		// MinLength for strings.
		if ( isset( $schema['minLength'] ) && is_string( $value ) ) {
			if ( strlen( $value ) < $schema['minLength'] ) {
				return new WP_Error(
					'rest_too_short',
					sprintf( '%s must be at least %d characters.', $context, $schema['minLength'] ),
					array( 'param' => $context )
				);
			}
		}

		return true;
	}
}

if ( ! function_exists( 'rest_validate_type' ) ) {
	/**
	 * Check if a value matches the expected type.
	 *
	 * @param mixed  $value Value to check.
	 * @param string $type  Expected type.
	 * @return true|WP_Error
	 */
	function rest_validate_type( $value, string $type ) {
		switch ( $type ) {
			case 'string':
				if ( ! is_string( $value ) ) {
					return new WP_Error( 'rest_invalid_type', 'Not a string.' );
				}
				break;
			case 'integer':
				if ( ! is_int( $value ) ) {
					return new WP_Error( 'rest_invalid_type', 'Not an integer.' );
				}
				break;
			case 'number':
				if ( ! is_numeric( $value ) ) {
					return new WP_Error( 'rest_invalid_type', 'Not a number.' );
				}
				break;
			case 'boolean':
				if ( ! is_bool( $value ) ) {
					return new WP_Error( 'rest_invalid_type', 'Not a boolean.' );
				}
				break;
			case 'object':
				if ( ! is_array( $value ) ) {
					return new WP_Error( 'rest_invalid_type', 'Not an object.' );
				}
				break;
			case 'array':
				if ( ! is_array( $value ) || array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
					// Check if it's a sequential array.
					if ( ! is_array( $value ) ) {
						return new WP_Error( 'rest_invalid_type', 'Not an array.' );
					}
				}
				break;
			case 'null':
				if ( null !== $value ) {
					return new WP_Error( 'rest_invalid_type', 'Not null.' );
				}
				break;
		}
		return true;
	}
}
