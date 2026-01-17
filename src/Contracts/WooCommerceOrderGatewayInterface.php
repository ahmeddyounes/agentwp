<?php
/**
 * WooCommerce order gateway interface.
 *
 * @package AgentWP\Contracts
 */

namespace AgentWP\Contracts;

/**
 * Interface for WooCommerce order status operations.
 *
 * Abstracts wc_get_order(), wc_get_order_statuses() and order status updates.
 */
interface WooCommerceOrderGatewayInterface {

	/**
	 * Get an order by ID.
	 *
	 * @param int $order_id Order ID.
	 * @return object|null WC_Order or null if not found.
	 */
	public function get_order( int $order_id ): ?object;

	/**
	 * Get all valid order statuses.
	 *
	 * @return array<string, string> Associative array of status slug => label.
	 */
	public function get_order_statuses(): array;

	/**
	 * Update an order's status.
	 *
	 * @param object $order      WC_Order instance.
	 * @param string $new_status New status slug (without 'wc-' prefix).
	 * @param string $note       Optional note to add to the order.
	 * @return bool True on success, false on failure.
	 */
	public function update_order_status( object $order, string $new_status, string $note = '' ): bool;

	/**
	 * Enable or disable WooCommerce emails.
	 *
	 * @param bool $enabled Whether emails should be enabled.
	 * @return void
	 */
	public function set_emails_enabled( bool $enabled ): void;
}
