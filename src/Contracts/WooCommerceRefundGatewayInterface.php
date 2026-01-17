<?php
/**
 * WooCommerce refund gateway interface.
 *
 * @package AgentWP\Contracts
 */

namespace AgentWP\Contracts;

use WP_Error;

/**
 * Interface for WooCommerce refund operations.
 *
 * Abstracts wc_create_refund() and related order queries for refund processing.
 */
interface WooCommerceRefundGatewayInterface {

	/**
	 * Get an order by ID.
	 *
	 * @param int $order_id Order ID.
	 * @return object|null WC_Order or null if not found.
	 */
	public function get_order( int $order_id ): ?object;

	/**
	 * Create a refund.
	 *
	 * @param array $args Refund arguments:
	 *                    - amount: float
	 *                    - reason: string
	 *                    - order_id: int
	 *                    - restock_items: bool
	 *                    - refund_payment: bool
	 * @return object WC_Order_Refund on success, WP_Error on failure.
	 */
	public function create_refund( array $args ): object;
}
