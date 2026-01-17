<?php
/**
 * Tool registry implementation.
 *
 * Manages AI function schemas for intent handlers.
 *
 * @package AgentWP\Intent
 */

namespace AgentWP\Intent;

use AgentWP\AI\Functions\FunctionSchema;
use AgentWP\Contracts\ToolRegistryInterface;

/**
 * Registry for AI function schemas.
 */
class ToolRegistry implements ToolRegistryInterface {

	/**
	 * Registered function schemas.
	 *
	 * @var array<string, FunctionSchema>
	 */
	private array $schemas = array();

	/**
	 * Register a function schema.
	 *
	 * @param FunctionSchema $schema Function schema to register.
	 * @return void
	 */
	public function register( FunctionSchema $schema ): void {
		$this->schemas[ $schema->get_name() ] = $schema;
	}

	/**
	 * Get a function schema by name.
	 *
	 * @param string $name Function name.
	 * @return FunctionSchema|null
	 */
	public function get( string $name ): ?FunctionSchema {
		return $this->schemas[ $name ] ?? null;
	}

	/**
	 * Get multiple function schemas by names.
	 *
	 * @param array<string> $names Function names.
	 * @return array<FunctionSchema>
	 */
	public function getMany( array $names ): array {
		$result = array();
		foreach ( $names as $name ) {
			$schema = $this->get( $name );
			if ( null !== $schema ) {
				$result[] = $schema;
			}
		}
		return $result;
	}

	/**
	 * Check if a function schema exists.
	 *
	 * @param string $name Function name.
	 * @return bool
	 */
	public function has( string $name ): bool {
		return isset( $this->schemas[ $name ] );
	}

	/**
	 * Get all registered function schemas.
	 *
	 * @return array<string, FunctionSchema>
	 */
	public function all(): array {
		return $this->schemas;
	}
}
