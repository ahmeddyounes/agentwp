<?php
/**
 * WooCommerce config gateway implementation.
 *
 * @package AgentWP\Infrastructure
 */

namespace AgentWP\Infrastructure;

use AgentWP\Contracts\WooCommerceConfigGatewayInterface;

/**
 * Wraps WooCommerce configuration functions.
 */
final class WooCommerceConfigGateway implements WooCommerceConfigGatewayInterface {

	/**
	 * {@inheritDoc}
	 */
	public function get_paid_statuses(): array {
		$statuses = function_exists( 'wc_get_is_paid_statuses' )
			? wc_get_is_paid_statuses()
			: array( 'processing', 'completed', 'on-hold' );

		$statuses = array_filter( array_map( 'sanitize_text_field', (array) $statuses ) );

		if ( empty( $statuses ) ) {
			return array( 'processing', 'completed', 'on-hold' );
		}

		return array_values( array_unique( $statuses ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_woocommerce_available(): bool {
		return function_exists( 'wc_get_orders' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
		if ( ! function_exists( 'apply_filters' ) ) {
			return $value;
		}

		return apply_filters( $hook, $value, ...$args );
	}
}
