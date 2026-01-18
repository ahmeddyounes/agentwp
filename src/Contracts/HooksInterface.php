<?php
/**
 * WordPress hooks interface.
 *
 * @package AgentWP\Contracts
 */

namespace AgentWP\Contracts;

/**
 * Contract for WordPress filter and action hooks.
 *
 * This interface enables testability by abstracting WordPress's global
 * apply_filters() and do_action() functions. Business logic should depend
 * on this interface rather than calling WordPress functions directly.
 */
interface HooksInterface {

	/**
	 * Apply WordPress filters.
	 *
	 * @param string $hook_name Filter hook name.
	 * @param mixed  $value     Value to filter.
	 * @param mixed  ...$args   Additional arguments passed to the filter.
	 * @return mixed Filtered value.
	 */
	public function applyFilters( string $hook_name, $value, ...$args );

	/**
	 * Execute a WordPress action.
	 *
	 * @param string $hook_name Action hook name.
	 * @param mixed  ...$args   Arguments passed to the action.
	 * @return void
	 */
	public function doAction( string $hook_name, ...$args ): void;
}
