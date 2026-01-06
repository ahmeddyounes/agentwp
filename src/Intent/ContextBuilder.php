<?php
/**
 * Build enriched intent context.
 *
 * @package AgentWP
 */

namespace AgentWP\Intent;

class ContextBuilder {
	/**
	 * Build context with store and user data.
	 *
	 * @param array $context Request context.
	 * @param array $metadata Request metadata.
	 * @return array
	 */
	public function build( array $context = array(), array $metadata = array() ) {
		return array(
			'request'       => $context,
			'metadata'      => $metadata,
			'user'          => $this->get_user_context(),
			'recent_orders' => $this->get_recent_orders(),
			'store'         => $this->get_store_context(),
		);
	}

	/**
	 * @return array
	 */
	private function get_user_context() {
		if ( ! function_exists( 'wp_get_current_user' ) ) {
			return array();
		}

		$user = wp_get_current_user();
		if ( ! $user || ! isset( $user->ID ) || 0 === intval( $user->ID ) ) {
			return array();
		}

		return array(
			'id'           => intval( $user->ID ),
			'display_name' => sanitize_text_field( $user->display_name ),
			'email'        => sanitize_email( $user->user_email ),
			'roles'        => is_array( $user->roles ) ? array_values( $user->roles ) : array(),
		);
	}

	/**
	 * @return array
	 */
	private function get_recent_orders() {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$orders = wc_get_orders(
			array(
				'limit'   => 5,
				'orderby' => 'date',
				'order'   => 'DESC',
			)
		);

		if ( ! is_array( $orders ) ) {
			return array();
		}

		$summary = array();
		foreach ( $orders as $order ) {
			if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
				continue;
			}

			$date_created = method_exists( $order, 'get_date_created' ) ? $order->get_date_created() : null;

			$summary[] = array(
				'id'           => intval( $order->get_id() ),
				'status'       => sanitize_text_field( $order->get_status() ),
				'total'        => $order->get_total(),
				'currency'     => sanitize_text_field( $order->get_currency() ),
				'date_created' => $date_created ? $date_created->date( 'c' ) : '',
				'customer_id'  => method_exists( $order, 'get_customer_id' ) ? intval( $order->get_customer_id() ) : 0,
				'email'        => method_exists( $order, 'get_billing_email' ) ? sanitize_email( $order->get_billing_email() ) : '',
			);
		}

		return $summary;
	}

	/**
	 * @return array
	 */
	private function get_store_context() {
		$timezone = '';

		if ( function_exists( 'wp_timezone_string' ) ) {
			$timezone = wp_timezone_string();
		}

		if ( '' === $timezone && function_exists( 'get_option' ) ) {
			$timezone = (string) get_option( 'timezone_string' );
		}

		if ( '' === $timezone && function_exists( 'get_option' ) ) {
			$offset   = (float) get_option( 'gmt_offset' );
			$sign     = $offset >= 0 ? '+' : '-';
			$timezone = sprintf( 'UTC%s%s', $sign, abs( $offset ) );
		}

		$currency = '';
		if ( function_exists( 'get_woocommerce_currency' ) ) {
			$currency = get_woocommerce_currency();
		} elseif ( function_exists( 'get_option' ) ) {
			$currency = (string) get_option( 'woocommerce_currency' );
		}

		return array(
			'timezone' => sanitize_text_field( $timezone ),
			'currency' => sanitize_text_field( $currency ),
		);
	}
}
