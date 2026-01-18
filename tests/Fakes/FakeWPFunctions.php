<?php
/**
 * Fake WordPress functions wrapper for testing.
 *
 * @package AgentWP\Tests\Fakes
 */

namespace AgentWP\Tests\Fakes;

use AgentWP\Contracts\HooksInterface;

/**
 * Testable WordPress functions wrapper that captures hook calls.
 *
 * This class implements HooksInterface for use in unit tests.
 * It captures all action and filter calls for assertions.
 */
class FakeWPFunctions implements HooksInterface {

	/**
	 * Captured doAction calls.
	 *
	 * @var array<array{hook: string, args: array}>
	 */
	public array $actions = array();

	/**
	 * Captured applyFilters calls.
	 *
	 * @var array<array{hook: string, value: mixed, args: array}>
	 */
	public array $filters = array();

	/**
	 * Filter return values to mock.
	 *
	 * @var array<string, mixed>
	 */
	private array $filterReturns = array();

	/**
	 * Do WordPress action.
	 *
	 * @param string $hook_name Action name.
	 * @param mixed  ...$args   Action arguments.
	 * @return void
	 */
	public function doAction( string $hook_name, ...$args ): void {
		$this->actions[] = array(
			'hook' => $hook_name,
			'args' => $args,
		);
	}

	/**
	 * Apply WordPress filters.
	 *
	 * @param string $hook_name Filter name.
	 * @param mixed  $value     Value to filter.
	 * @return mixed Filtered value.
	 */
	public function applyFilters( string $hook_name, $value ) {
		$args = func_get_args();
		array_shift( $args ); // Remove $hook_name.

		$this->filters[] = array(
			'hook'  => $hook_name,
			'value' => $value,
			'args'  => $args,
		);

		// Return mocked value if set.
		if ( isset( $this->filterReturns[ $hook_name ] ) ) {
			return $this->filterReturns[ $hook_name ];
		}

		return $value;
	}

	/**
	 * Set a mocked filter return value.
	 *
	 * @param string $hook_name Filter name.
	 * @param mixed  $value     Value to return.
	 * @return void
	 */
	public function setFilterReturn( string $hook_name, $value ): void {
		$this->filterReturns[ $hook_name ] = $value;
	}

	/**
	 * Get the last action call.
	 *
	 * @return array{hook: string, args: array}|null
	 */
	public function getLastAction(): ?array {
		return count( $this->actions ) > 0 ? $this->actions[ count( $this->actions ) - 1 ] : null;
	}

	/**
	 * Get the last filter call.
	 *
	 * @return array{hook: string, value: mixed, args: array}|null
	 */
	public function getLastFilter(): ?array {
		return count( $this->filters ) > 0 ? $this->filters[ count( $this->filters ) - 1 ] : null;
	}

	/**
	 * Check if a specific action was fired.
	 *
	 * @param string $hook_name Action name to check.
	 * @return bool
	 */
	public function wasActionFired( string $hook_name ): bool {
		foreach ( $this->actions as $action ) {
			if ( $action['hook'] === $hook_name ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Check if a specific filter was applied.
	 *
	 * @param string $hook_name Filter name to check.
	 * @return bool
	 */
	public function wasFilterApplied( string $hook_name ): bool {
		foreach ( $this->filters as $filter ) {
			if ( $filter['hook'] === $hook_name ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Get all actions fired for a specific hook.
	 *
	 * @param string $hook_name Action name.
	 * @return array<array{hook: string, args: array}>
	 */
	public function getActionsForHook( string $hook_name ): array {
		return array_filter(
			$this->actions,
			fn( $action ) => $action['hook'] === $hook_name
		);
	}

	/**
	 * Reset all captured calls.
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->actions       = array();
		$this->filters       = array();
		$this->filterReturns = array();
	}
}
