<?php
/**
 * Interface for handlers that can execute AI tools.
 *
 * @package AgentWP\Contracts
 */

namespace AgentWP\Contracts;

interface ToolExecutorInterface {
	/**
	 * Execute a named tool with arguments.
	 *
	 * @param string $name      Tool name.
	 * @param array  $arguments Tool arguments.
	 * @return mixed Tool execution result.
	 */
	public function execute_tool( string $name, array $arguments );
}
