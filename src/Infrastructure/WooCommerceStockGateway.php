<?php
/**
 * WooCommerce stock gateway implementation.
 *
 * @package AgentWP\Infrastructure
 */

namespace AgentWP\Infrastructure;

use AgentWP\Contracts\WooCommerceStockGatewayInterface;

/**
 * Wraps WooCommerce product stock functions.
 */
final class WooCommerceStockGateway implements WooCommerceStockGatewayInterface {

	/**
	 * {@inheritDoc}
	 */
	public function get_product( int $product_id ): ?object {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return null;
		}

		$product = wc_get_product( $product_id );

		if ( ! $product || ! ( $product instanceof \WC_Product ) ) {
			return null;
		}

		return $product;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_products( array $args ): array {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array();
		}

		$products = wc_get_products( $args );

		return is_array( $products ) ? $products : array();
	}

	/**
	 * {@inheritDoc}
	 */
	public function update_product_stock( object|int $product, int $quantity ): int|bool {
		if ( ! function_exists( 'wc_update_product_stock' ) ) {
			return false;
		}

		return wc_update_product_stock( $product, $quantity );
	}
}
