<?php
/**
 * WooCommerce product category gateway interface.
 *
 * @package AgentWP\Contracts
 */

namespace AgentWP\Contracts;

/**
 * Contract for WooCommerce product category operations.
 *
 * Abstracts wc_get_product_terms() and product category retrieval.
 */
interface WooCommerceProductCategoryGatewayInterface {

	/**
	 * Get product categories for a product.
	 *
	 * @param int $product_id Product ID.
	 * @return array<int, string> Associative array of term_id => name.
	 */
	public function get_product_categories( int $product_id ): array;
}
