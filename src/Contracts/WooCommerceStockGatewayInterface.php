<?php
/**
 * WooCommerce stock gateway interface.
 *
 * @package AgentWP\Contracts
 */

namespace AgentWP\Contracts;

/**
 * Interface for WooCommerce product stock operations.
 *
 * Abstracts wc_get_product(), wc_get_products(), wc_update_product_stock().
 */
interface WooCommerceStockGatewayInterface {

	/**
	 * Get a product by ID.
	 *
	 * @param int $product_id Product ID.
	 * @return object|null WC_Product or null if not found.
	 */
	public function get_product( int $product_id ): ?object;

	/**
	 * Search products.
	 *
	 * @param array $args Query arguments (limit, s for search term, etc.).
	 * @return array Array of WC_Product objects or product IDs.
	 */
	public function get_products( array $args ): array;

	/**
	 * Update product stock quantity.
	 *
	 * @param object|int $product  WC_Product instance or product ID.
	 * @param int        $quantity New stock quantity.
	 * @return int|bool New stock level or false on failure.
	 */
	public function update_product_stock( object|int $product, int $quantity ): int|bool;
}
