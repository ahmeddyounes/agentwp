<?php
/**
 * Interface for centralized tool execution dispatch.
 *
 * The ToolDispatcher resolves tools by name, validates arguments against
 * their schemas, executes them, and returns JSON-safe results.
 *
 * @package AgentWP\Contracts
 */

namespace AgentWP\Contracts;

/**
 * Dispatches tool execution calls to registered executors.
 *
 * This interface centralizes the tool execution logic that was previously
 * duplicated across handler `execute_tool()` implementations. It provides:
 * - Tool registration with callable executors
 * - Argument validation against JSON schemas
 * - Execution with JSON-safe result handling
 */
interface ToolDispatcherInterface {

	/**
	 * Register a tool executor.
	 *
	 * @param string   $name     Tool name.
	 * @param callable $executor Callable that executes the tool: fn(array $args): mixed
	 * @return void
	 */
	public function register( string $name, callable $executor ): void;

	/**
	 * Register multiple tool executors.
	 *
	 * @param array<string, callable> $executors Map of tool name to executor callable.
	 * @return void
	 */
	public function registerMany( array $executors ): void;

	/**
	 * Check if a tool executor is registered.
	 *
	 * @param string $name Tool name.
	 * @return bool
	 */
	public function has( string $name ): bool;

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
	public function dispatch( string $name, array $arguments ): array;
}
