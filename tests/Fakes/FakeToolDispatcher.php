<?php
/**
 * Fake tool dispatcher for testing.
 */

namespace AgentWP\Tests\Fakes;

use AgentWP\Contracts\ExecutableToolInterface;
use AgentWP\Contracts\ToolDispatcherInterface;

/**
 * Fake implementation of ToolDispatcherInterface for testing.
 *
 * Can have executors pre-registered either as callables or via ExecutableToolInterface.
 */
class FakeToolDispatcher implements ToolDispatcherInterface {

	/**
	 * @var array<string, callable>
	 */
	private array $executors = array();

	/**
	 * @var array<array{name: string, arguments: array}>
	 */
	public array $dispatchedCalls = array();

	/**
	 * Register a tool executor.
	 *
	 * @param string   $name     Tool name.
	 * @param callable $executor Callable that executes the tool.
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
	 * Register an executable tool.
	 *
	 * @param ExecutableToolInterface $tool Executable tool instance.
	 * @return void
	 */
	public function registerTool( ExecutableToolInterface $tool ): void {
		$this->register(
			$tool->getName(),
			fn( array $args ): array => $tool->execute( $args )
		);
	}

	/**
	 * Register multiple executable tools.
	 *
	 * @param array<ExecutableToolInterface> $tools Array of executable tool instances.
	 * @return void
	 */
	public function registerTools( array $tools ): void {
		foreach ( $tools as $tool ) {
			$this->registerTool( $tool );
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
	 * @param string $name      Tool name.
	 * @param array  $arguments Tool arguments.
	 * @return array JSON-safe execution result.
	 */
	public function dispatch( string $name, array $arguments ): array {
		$this->dispatchedCalls[] = array(
			'name'      => $name,
			'arguments' => $arguments,
		);

		if ( ! $this->has( $name ) ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Unknown tool "%s".', $name ),
				'code'    => 'unknown_tool',
			);
		}

		$result = $this->executors[ $name ]( $arguments );

		if ( ! is_array( $result ) ) {
			return array( 'result' => $result );
		}

		return $result;
	}
}
