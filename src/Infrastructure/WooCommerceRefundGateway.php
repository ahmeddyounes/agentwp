<?php
/**
 * WooCommerce refund gateway implementation.
 *
 * @package AgentWP\Infrastructure
 */

namespace AgentWP\Infrastructure;

use AgentWP\Contracts\WooCommerceRefundGatewayInterface;
use WP_Error;

/**
 * Wraps WooCommerce refund functions.
 */
final class WooCommerceRefundGateway implements WooCommerceRefundGatewayInterface {

	/**
	 * {@inheritDoc}
	 */
	public function get_order( int $order_id ): ?object {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order || ! ( $order instanceof \WC_Order ) ) {
			return null;
		}

		return $order;
	}

	/**
	 * {@inheritDoc}
	 */
	public function create_refund( array $args ): object {
		if ( ! function_exists( 'wc_create_refund' ) ) {
			return new WP_Error( 'wc_unavailable', 'WooCommerce is not available.' );
		}

		return wc_create_refund( $args );
	}
}
