<?php
/**
 * WooCommerce config gateway interface.
 *
 * @package AgentWP\Contracts
 */

namespace AgentWP\Contracts;

/**
 * Contract for WooCommerce configuration operations.
 *
 * Abstracts wc_get_is_paid_statuses() and similar config functions.
 */
interface WooCommerceConfigGatewayInterface {

	/**
	 * Get the list of paid order statuses.
	 *
	 * @return array<string> List of status slugs.
	 */
	public function get_paid_statuses(): array;

	/**
	 * Check if WooCommerce is available.
	 *
	 * @return bool True if WooCommerce is active.
	 */
	public function is_woocommerce_available(): bool;

	/**
	 * Apply a filter hook.
	 *
	 * @param string $hook  Filter hook name.
	 * @param mixed  $value Value to filter.
	 * @param mixed  ...$args Additional arguments.
	 * @return mixed Filtered value.
	 */
	public function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed;
}
