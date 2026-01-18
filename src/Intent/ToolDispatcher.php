<?php
/**
 * Tool dispatcher service implementation.
 *
 * Centralizes tool execution that was previously duplicated across
 * handler `execute_tool()` implementations.
 *
 * @package AgentWP\Intent
 */

namespace AgentWP\Intent;

use AgentWP\Contracts\ToolDispatcherInterface;
use AgentWP\Contracts\ToolRegistryInterface;
use AgentWP\Validation\ToolArgumentValidator;

/**
 * Dispatches tool execution calls to registered executors.
 *
 * Provides centralized tool execution with:
 * - Tool registration with callable executors
 * - Argument validation against JSON schemas from ToolRegistry
 * - Execution with JSON-safe result handling
 */
class ToolDispatcher implements ToolDispatcherInterface {

	/**
	 * Registered tool executors.
	 *
	 * @var array<string, callable>
	 */
	private array $executors = array();

	/**
	 * Tool registry for schema validation.
	 *
	 * @var ToolRegistryInterface
	 */
	private ToolRegistryInterface $toolRegistry;

	/**
	 * Argument validator.
	 *
	 * @var ToolArgumentValidator
	 */
	private ToolArgumentValidator $validator;

	/**
	 * Initialize the dispatcher.
	 *
	 * @param ToolRegistryInterface      $toolRegistry Tool registry for schema lookup.
	 * @param ToolArgumentValidator|null $validator    Argument validator (optional).
	 */
	public function __construct(
		ToolRegistryInterface $toolRegistry,
		?ToolArgumentValidator $validator = null
	) {
		$this->toolRegistry = $toolRegistry;
		$this->validator    = $validator ?? new ToolArgumentValidator();
	}

	/**
	 * Register a tool executor.
	 *
	 * @param string   $name     Tool name.
	 * @param callable $executor Callable that executes the tool: fn(array $args): mixed
	 * @return void
	 */
	public function register( string $name, callable $executor ): void {
		$this->executors[ $name ] = $executor;
	}

	/**
	 * Register multiple tool executors.
	 *
	 * @param array<string, callable> $executors Map of tool name to executor callable.
	 * @return void
	 */
	public function registerMany( array $executors ): void {
		foreach ( $executors as $name => $executor ) {
			$this->register( $name, $executor );
		}
	}

	/**
	 * Check if a tool executor is registered.
	 *
	 * @param string $name Tool name.
	 * @return bool
	 */
	public function has( string $name ): bool {
		return isset( $this->executors[ $name ] );
	}

	/**
	 * Dispatch a tool execution.
	 *
	 * Resolves the tool by name, validates arguments against the schema
	 * (if available in the ToolRegistry), executes the tool, and returns
	 * a JSON-safe result.
	 *
	 * @param string $name      Tool name.
	 * @param array  $arguments Tool arguments.
	 * @return array JSON-safe execution result.
	 */
	public function dispatch( string $name, array $arguments ): array {
		// Check if executor is registered.
		if ( ! $this->has( $name ) ) {
			return array( 'error' => "Unknown tool: {$name}" );
		}

		// Validate arguments against schema if available.
		$validation_error = $this->validateArguments( $name, $arguments );
		if ( null !== $validation_error ) {
			return $validation_error;
		}

		// Execute the tool.
		$result = $this->executeAndSanitize( $name, $arguments );

		return $result;
	}

	/**
	 * Validate tool arguments against the schema.
	 *
	 * @param string $name      Tool name.
	 * @param array  $arguments Tool arguments.
	 * @return array|null Error array if validation fails, null if valid.
	 */
	private function validateArguments( string $name, array $arguments ): ?array {
		$schema = $this->toolRegistry->get( $name );

		// Skip validation if schema not found.
		if ( null === $schema ) {
			return null;
		}

		$result = $this->validator->validate( $schema, $arguments );

		if ( ! $result->isValid ) {
			return $result->toErrorArray();
		}

		return null;
	}

	/**
	 * Execute a tool and ensure JSON-safe result.
	 *
	 * @param string $name      Tool name.
	 * @param array  $arguments Tool arguments.
	 * @return array JSON-safe result.
	 */
	private function executeAndSanitize( string $name, array $arguments ): array {
		$executor = $this->executors[ $name ];
		$result   = $executor( $arguments );

		// Ensure result is an array.
		if ( ! is_array( $result ) ) {
			// Wrap scalar values.
			if ( is_scalar( $result ) || is_null( $result ) ) {
				$result = array( 'result' => $result );
			} else {
				// Try to convert objects to arrays.
				$result = $this->toArray( $result );
			}
		}

		// Verify JSON encoding succeeds.
		$encoded = wp_json_encode( $result );
		if ( false === $encoded ) {
			return array( 'error' => 'Failed to encode tool result as JSON' );
		}

		return $result;
	}

	/**
	 * Convert a value to an array.
	 *
	 * @param mixed $value Value to convert.
	 * @return array
	 */
	private function toArray( $value ): array {
		if ( is_array( $value ) ) {
			return $value;
		}

		if ( is_object( $value ) ) {
			// Check for common conversion methods.
			if ( method_exists( $value, 'toArray' ) ) {
				return $value->toArray();
			}
			if ( method_exists( $value, 'toLegacyArray' ) ) {
				return $value->toLegacyArray();
			}
			if ( method_exists( $value, 'jsonSerialize' ) ) {
				$serialized = $value->jsonSerialize();
				return is_array( $serialized ) ? $serialized : array( 'result' => $serialized );
			}
			// Fall back to casting.
			return (array) $value;
		}

		return array( 'result' => $value );
	}
}
