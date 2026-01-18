<?php
/**
 * Interface for executable tools.
 *
 * An ExecutableTool combines schema definition (via FunctionSchema) with execution logic.
 * This enables centralized tool registration where both the schema and executor are
 * managed together.
 *
 * @package AgentWP\Contracts
 */

namespace AgentWP\Contracts;

/**
 * Contract for tools that can be executed by the ToolDispatcher.
 *
 * Executable tools encapsulate:
 * - Tool name (unique identifier)
 * - Execution logic that processes arguments and returns results
 *
 * Tools are registered centrally via the container and made available to handlers
 * that declare them in their getToolNames() method.
 */
interface ExecutableToolInterface {

	/**
	 * Get the tool name.
	 *
	 * Must match the corresponding FunctionSchema name in the ToolRegistry.
	 *
	 * @return string Tool name (e.g., 'search_orders', 'prepare_refund').
	 */
	public function getName(): string;

	/**
	 * Execute the tool with the given arguments.
	 *
	 * @param array $arguments Tool arguments validated against the schema.
	 * @return array JSON-serializable result array.
	 */
	public function execute( array $arguments ): array;
}
