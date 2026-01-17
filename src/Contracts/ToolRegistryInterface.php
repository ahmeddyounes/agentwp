<?php
/**
 * Tool registry interface.
 *
 * Provides function schema definitions for intent handlers,
 * enabling dependency injection and avoiding hidden instantiation.
 *
 * @package AgentWP\Contracts
 */

namespace AgentWP\Contracts;

use AgentWP\AI\Functions\FunctionSchema;

/**
 * Registry for AI function schemas.
 */
interface ToolRegistryInterface {

	/**
	 * Register a function schema.
	 *
	 * @param FunctionSchema $schema Function schema to register.
	 * @return void
	 */
	public function register( FunctionSchema $schema ): void;

	/**
	 * Get a function schema by name.
	 *
	 * @param string $name Function name.
	 * @return FunctionSchema|null
	 */
	public function get( string $name ): ?FunctionSchema;

	/**
	 * Get multiple function schemas by names.
	 *
	 * @param array<string> $names Function names.
	 * @return array<FunctionSchema>
	 */
	public function getMany( array $names ): array;

	/**
	 * Check if a function schema exists.
	 *
	 * @param string $name Function name.
	 * @return bool
	 */
	public function has( string $name ): bool;

	/**
	 * Get all registered function schemas.
	 *
	 * @return array<string, FunctionSchema>
	 */
	public function all(): array;
}
