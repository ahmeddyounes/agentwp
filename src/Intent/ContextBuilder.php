<?php
/**
 * Build enriched intent context.
 *
 * @package AgentWP
 */

namespace AgentWP\Intent;

use AgentWP\Contracts\ContextBuilderInterface;

class ContextBuilder implements ContextBuilderInterface {
	/**
	 * Build context with store and user data.
	 *
	 * @param array $context Request context.
	 * @param array $metadata Request metadata.
	 * @return array
	 */
	public function build( array $context = array(), array $metadata = array() ): array {
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
	private function get_user_context(): array {
		if ( ! function_exists( 'wp_get_current_user' ) ) {
			return array();
		}

			$user = wp_get_current_user();
			if ( 0 === intval( $user->ID ) ) {
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
	private function get_recent_orders(): array {
		if ( ! function_exists( 'wc_get_orders' ) || ! function_exists( 'wc_get_order' ) ) {
			return array();
		}

		$orders = wc_get_orders(
			array(
				'limit'   => 5,
				'orderby' => 'date',
				'order'   => 'DESC',
				'return'  => 'ids',
			)
		);

		if ( ! is_array( $orders ) ) {
			return array();
		}

			$summary = array();
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

				$summary[] = array(
					'id'           => intval( $order->get_id() ),
					'status'       => sanitize_text_field( (string) $status ),
					'total'        => $total,
					'currency'     => sanitize_text_field( (string) $currency ),
					'date_created' => $date_created_iso,
					'customer_id'  => method_exists( $order, 'get_customer_id' ) ? intval( $order->get_customer_id() ) : 0,
					'email'        => method_exists( $order, 'get_billing_email' ) ? sanitize_email( $order->get_billing_email() ) : '',
				);
			}

		return $summary;
	}

	/**
	 * @return array
	 */
	private function get_store_context(): array {
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
