<?php
/**
 * Base Request DTO for REST API validation.
 *
 * @package AgentWP\DTO
 */

namespace AgentWP\DTO;

use WP_Error;
use WP_REST_Request;

/**
 * Abstract base class for request DTOs.
 *
 * Provides a minimal validation layer that leverages WP REST schema validation
 * while offering typed accessors to reduce boilerplate in controllers.
 */
abstract class RequestDTO {

	/**
	 * Validated payload data.
	 *
	 * @var array<string, mixed>
	 */
	protected array $data;

	/**
	 * Validation errors, if any.
	 *
	 * @var WP_Error|null
	 */
	protected ?WP_Error $errors = null;

	/**
	 * Create DTO from request.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request instance.
	 */
	final public function __construct( WP_REST_Request $request ) {
		$this->data = $this->extractPayload( $request );
		$this->validate();
	}

	/**
	 * Get JSON schema for validation.
	 *
	 * @return array JSON schema definition.
	 */
	abstract protected function getSchema(): array;

	/**
	 * Get payload source type.
	 *
	 * @return string 'json' for body, 'query' for query params.
	 */
	protected function getSource(): string {
		return 'json';
	}

	/**
	 * Extract payload from request based on source type.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request instance.
	 * @return array<string, mixed>
	 */
	protected function extractPayload( WP_REST_Request $request ): array {
		$payload = ( 'query' === $this->getSource() )
			? $request->get_query_params()
			: $request->get_json_params();

		return is_array( $payload ) ? $payload : array();
	}

	/**
	 * Validate payload against schema.
	 *
	 * @return void
	 */
	protected function validate(): void {
		$schema         = $this->getSchema();
		$schema['type'] = $schema['type'] ?? 'object';

		$result = rest_validate_value_from_schema( $this->data, $schema, 'request' );

		if ( is_wp_error( $result ) ) {
			$this->errors = $result;
		}
	}

	/**
	 * Check if validation passed.
	 *
	 * @return bool
	 */
	public function isValid(): bool {
		return null === $this->errors;
	}

	/**
	 * Get validation error.
	 *
	 * @return WP_Error|null
	 */
	public function getError(): ?WP_Error {
		return $this->errors;
	}

	/**
	 * Get a string value from payload.
	 *
	 * @param string $key     Key name.
	 * @param string $default Default value.
	 * @return string
	 */
	protected function getString( string $key, string $default = '' ): string {
		if ( ! isset( $this->data[ $key ] ) ) {
			return $default;
		}

		return is_string( $this->data[ $key ] ) ? trim( $this->data[ $key ] ) : $default;
	}

	/**
	 * Get an integer value from payload.
	 *
	 * @param string $key     Key name.
	 * @param int    $default Default value.
	 * @return int
	 */
	protected function getInt( string $key, int $default = 0 ): int {
		if ( ! isset( $this->data[ $key ] ) ) {
			return $default;
		}

		return intval( $this->data[ $key ] );
	}

	/**
	 * Get a float value from payload.
	 *
	 * @param string $key     Key name.
	 * @param float  $default Default value.
	 * @return float
	 */
	protected function getFloat( string $key, float $default = 0.0 ): float {
		if ( ! isset( $this->data[ $key ] ) ) {
			return $default;
		}

		return floatval( $this->data[ $key ] );
	}

	/**
	 * Get a boolean value from payload.
	 *
	 * @param string $key     Key name.
	 * @param bool   $default Default value.
	 * @return bool
	 */
	protected function getBool( string $key, bool $default = false ): bool {
		if ( ! array_key_exists( $key, $this->data ) ) {
			return $default;
		}

		/** @var mixed $value */
		$value = $this->data[ $key ];
		// @phpstan-ignore argument.templateType (WP stubs issue)
		return (bool) rest_sanitize_boolean( $value );
	}

	/**
	 * Get an array value from payload.
	 *
	 * @param string $key     Key name.
	 * @param array  $default Default value.
	 * @return array
	 */
	protected function getArray( string $key, array $default = array() ): array {
		if ( ! isset( $this->data[ $key ] ) || ! is_array( $this->data[ $key ] ) ) {
			return $default;
		}

		return $this->data[ $key ];
	}

	/**
	 * Check if a key exists in the payload.
	 *
	 * @param string $key Key name.
	 * @return bool
	 */
	protected function has( string $key ): bool {
		return array_key_exists( $key, $this->data );
	}

	/**
	 * Get raw payload data.
	 *
	 * @return array<string, mixed>
	 */
	public function getRawData(): array {
		return $this->data;
	}
}
