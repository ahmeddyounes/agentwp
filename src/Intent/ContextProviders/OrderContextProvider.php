<?php
/**
 * Order context provider.
 *
 * @package AgentWP
 */

namespace AgentWP\Intent\ContextProviders;

/**
 * Provides recent order context information.
 *
 * Wraps WooCommerce order functions for testability.
 */
class OrderContextProvider implements ContextProviderInterface {
	/**
	 * Default number of recent orders to fetch.
	 */
	private const DEFAULT_LIMIT = 5;

	/**
	 * Provide order context data.
	 *
	 * @param array $context Request context.
	 * @param array $metadata Request metadata.
	 * @return array Order context data.
	 */
	public function provide( array $context, array $metadata ): array {
		if ( ! function_exists( 'wc_get_orders' ) || ! function_exists( 'wc_get_order' ) ) {
			return [];
		}

		$limit = isset( $metadata['orders_limit'] )
			? intval( $metadata['orders_limit'] )
			: self::DEFAULT_LIMIT;

		$orders = wc_get_orders(
			[
				'limit'   => $limit,
				'orderby' => 'date',
				'order'   => 'DESC',
				'return'  => 'ids',
			]
		);

		if ( ! is_array( $orders ) ) {
			return [];
		}

		$summary = [];
		foreach ( $orders as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
				continue;
			}

			$status   = method_exists( $order, 'get_status' ) ? $order->get_status() : '';
			$currency = method_exists( $order, 'get_currency' ) ? $order->get_currency() : '';

			$total_raw = method_exists( $order, 'get_total' ) ? $order->get_total() : 0;
			$total     = is_numeric( $total_raw ) ? (float) $total_raw : 0.0;

			$date_created     = method_exists( $order, 'get_date_created' ) ? $order->get_date_created() : null;
			$date_created_iso = '';
			if ( is_object( $date_created ) && method_exists( $date_created, 'date' ) ) {
				$date_created_iso = $date_created->date( 'c' );
			}

			$summary[] = [
				'id'           => intval( $order->get_id() ),
				'status'       => sanitize_text_field( (string) $status ),
				'total'        => $total,
				'currency'     => sanitize_text_field( (string) $currency ),
				'date_created' => $date_created_iso,
				'customer_id'  => method_exists( $order, 'get_customer_id' ) ? intval( $order->get_customer_id() ) : 0,
				'email'        => method_exists( $order, 'get_billing_email' ) ? sanitize_email( $order->get_billing_email() ) : '',
			];
		}

		return $summary;
	}
}
