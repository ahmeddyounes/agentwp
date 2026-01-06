<?php
/**
 * Build email drafting context for orders.
 *
 * @package AgentWP
 */

namespace AgentWP\Context;

class EmailContextBuilder {
	const RECENT_NOTES_LIMIT     = 5;
	const DELAYED_SHIPPING_DAYS  = 3;
	const FALLBACK_SPEND_CUTOFF  = 50;

	/**
	 * Build email drafting context for a specific order.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function build( $order_id ) {
		$order_id = absint( $order_id );
		if ( 0 === $order_id ) {
			return array(
				'order_id' => 0,
				'error'    => 'Invalid order ID.',
			);
		}

		if ( ! function_exists( 'wc_get_order' ) ) {
			return array(
				'order_id' => $order_id,
				'error'    => 'WooCommerce is required to load order context.',
			);
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array(
				'order_id' => $order_id,
				'error'    => 'Order not found.',
			);
		}

		$items            = $this->get_line_items( $order );
		$tracking         = $this->get_tracking_context( $order );
		$shipping_methods = $this->get_shipping_methods( $order );

		return array(
			'order_id' => $order_id,
			'order'    => array(
				'id'         => $order_id,
				'status'     => sanitize_text_field( $order->get_status() ),
				'currency'   => sanitize_text_field( $order->get_currency() ),
				'items'      => $items,
				'item_count' => count( $items ),
				'totals'     => $this->get_order_totals( $order ),
				'dates'      => $this->get_order_dates( $order ),
			),
			'customer' => $this->get_customer_context( $order ),
			'shipping' => array(
				'methods'            => $shipping_methods,
				'tracking'           => $tracking,
				'estimated_delivery' => $this->get_estimated_delivery( $order, $shipping_methods ),
			),
			'payment'  => $this->get_payment_context( $order ),
			'notes'    => $this->get_recent_notes( $order_id ),
			'issues'   => $this->detect_issues( $order, $items, $tracking ),
		);
	}

	/**
	 * @param object $order Order instance.
	 * @return array
	 */
	private function get_line_items( $order ) {
		if ( ! $order || ! method_exists( $order, 'get_items' ) ) {
			return array();
		}

		$items = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_name' ) ) {
				continue;
			}

			$product    = method_exists( $item, 'get_product' ) ? $item->get_product() : null;
			$quantity   = method_exists( $item, 'get_quantity' ) ? (int) $item->get_quantity() : 0;
			$sku        = ( $product && method_exists( $product, 'get_sku' ) ) ? (string) $product->get_sku() : '';
			$backorder  = false;

			if ( $product && method_exists( $product, 'is_on_backorder' ) ) {
				$backorder = (bool) $product->is_on_backorder( $quantity );
			} elseif ( method_exists( $item, 'is_on_backorder' ) ) {
				$backorder = (bool) $item->is_on_backorder( $quantity );
			}

			$items[] = array(
				'item_id'      => intval( $item_id ),
				'product_id'   => method_exists( $item, 'get_product_id' ) ? intval( $item->get_product_id() ) : 0,
				'variation_id' => method_exists( $item, 'get_variation_id' ) ? intval( $item->get_variation_id() ) : 0,
				'name'         => sanitize_text_field( $item->get_name() ),
				'sku'          => sanitize_text_field( $sku ),
				'quantity'     => $quantity,
				'subtotal'     => method_exists( $item, 'get_subtotal' ) ? $item->get_subtotal() : '',
				'total'        => method_exists( $item, 'get_total' ) ? $item->get_total() : '',
				'backordered'  => $backorder,
			);
		}

		return $items;
	}

	/**
	 * @param object $order Order instance.
	 * @return array
	 */
	private function get_order_totals( $order ) {
		$subtotal = method_exists( $order, 'get_subtotal' ) ? $order->get_subtotal() : '';
		if ( '' === $subtotal && method_exists( $order, 'get_total' ) ) {
			$subtotal = $order->get_total();
		}

		return array(
			'subtotal'       => $subtotal,
			'discount_total' => method_exists( $order, 'get_discount_total' ) ? $order->get_discount_total() : '',
			'shipping_total' => method_exists( $order, 'get_shipping_total' ) ? $order->get_shipping_total() : '',
			'tax_total'      => method_exists( $order, 'get_total_tax' ) ? $order->get_total_tax() : '',
			'total'          => method_exists( $order, 'get_total' ) ? $order->get_total() : '',
		);
	}

	/**
	 * @param object $order Order instance.
	 * @return array
	 */
	private function get_order_dates( $order ) {
		$created   = method_exists( $order, 'get_date_created' ) ? $order->get_date_created() : null;
		$paid      = method_exists( $order, 'get_date_paid' ) ? $order->get_date_paid() : null;
		$completed = method_exists( $order, 'get_date_completed' ) ? $order->get_date_completed() : null;
		$modified  = method_exists( $order, 'get_date_modified' ) ? $order->get_date_modified() : null;

		return array(
			'created'   => $this->format_datetime( $created ),
			'paid'      => $this->format_datetime( $paid ),
			'completed' => $this->format_datetime( $completed ),
			'modified'  => $this->format_datetime( $modified ),
		);
	}

	/**
	 * @param object $order Order instance.
	 * @return array
	 */
	private function get_customer_context( $order ) {
		$customer_id = method_exists( $order, 'get_customer_id' ) ? intval( $order->get_customer_id() ) : 0;
		$email       = method_exists( $order, 'get_billing_email' ) ? sanitize_email( $order->get_billing_email() ) : '';
		$first       = method_exists( $order, 'get_billing_first_name' ) ? $order->get_billing_first_name() : '';
		$last        = method_exists( $order, 'get_billing_last_name' ) ? $order->get_billing_last_name() : '';

		if ( '' === trim( $first . $last ) ) {
			$first = method_exists( $order, 'get_shipping_first_name' ) ? $order->get_shipping_first_name() : $first;
			$last  = method_exists( $order, 'get_shipping_last_name' ) ? $order->get_shipping_last_name() : $last;
		}

		$name        = trim( $first . ' ' . $last );
		$identifier  = $customer_id > 0 ? $customer_id : $email;
		$order_count = 0;
		$total_spent = 0.0;

		if ( '' !== $identifier && function_exists( 'wc_get_customer_order_count' ) ) {
			$order_count = (int) wc_get_customer_order_count( $identifier );
		}

		if ( '' !== $identifier && function_exists( 'wc_get_customer_total_spent' ) ) {
			$total_spent = (float) wc_get_customer_total_spent( $identifier );
		}

		if ( 0 === $order_count && function_exists( 'wc_get_orders' ) && '' !== $email ) {
			$order_count = $this->get_order_count_fallback( $customer_id, $email );
		}

		if ( 0.0 === $total_spent && $order_count > 0 && $order_count <= self::FALLBACK_SPEND_CUTOFF ) {
			$total_spent = $this->get_total_spent_fallback( $customer_id, $email );
		}

		return array(
			'id'          => $customer_id,
			'name'        => sanitize_text_field( $name ),
			'email'       => $email,
			'order_count' => $order_count,
			'total_spent' => $total_spent,
		);
	}

	/**
	 * @param object $order Order instance.
	 * @return array
	 */
	private function get_shipping_methods( $order ) {
		if ( ! $order || ! method_exists( $order, 'get_items' ) ) {
			return array();
		}

		$methods = array();
		foreach ( $order->get_items( 'shipping' ) as $item ) {
			if ( ! is_object( $item ) ) {
				continue;
			}

			$methods[] = array(
				'method_id'    => method_exists( $item, 'get_method_id' ) ? sanitize_text_field( $item->get_method_id() ) : '',
				'method_title' => method_exists( $item, 'get_method_title' ) ? sanitize_text_field( $item->get_method_title() ) : '',
				'instance_id'  => method_exists( $item, 'get_instance_id' ) ? intval( $item->get_instance_id() ) : 0,
				'total'        => method_exists( $item, 'get_total' ) ? $item->get_total() : '',
			);
		}

		return $methods;
	}

	/**
	 * @param object $order Order instance.
	 * @return array
	 */
	private function get_payment_context( $order ) {
		$method    = method_exists( $order, 'get_payment_method_title' ) ? $order->get_payment_method_title() : '';
		$method_id = method_exists( $order, 'get_payment_method' ) ? $order->get_payment_method() : '';

		return array(
			'method'      => sanitize_text_field( $method ),
			'method_id'   => sanitize_text_field( $method_id ),
			'card_last4'  => $this->get_card_last4( $order ),
		);
	}

	/**
	 * @param int $order_id Order ID.
	 * @return array
	 */
	private function get_recent_notes( $order_id ) {
		if ( ! function_exists( 'wc_get_order_notes' ) ) {
			return array();
		}

		$notes = wc_get_order_notes(
			array(
				'order_id' => $order_id,
				'limit'    => self::RECENT_NOTES_LIMIT,
				'orderby'  => 'date_created',
				'order'    => 'DESC',
			)
		);

		if ( ! is_array( $notes ) ) {
			return array();
		}

		$summary = array();
		foreach ( $notes as $note ) {
			if ( ! is_object( $note ) && ! is_array( $note ) ) {
				continue;
			}

			$content = '';
			if ( is_object( $note ) && method_exists( $note, 'get_content' ) ) {
				$content = $note->get_content();
			} elseif ( isset( $note->content ) ) {
				$content = $note->content;
			} elseif ( is_array( $note ) && isset( $note['content'] ) ) {
				$content = $note['content'];
			}

			$summary[] = array(
				'id'            => $this->extract_note_id( $note ),
				'date_created'  => $this->format_datetime( $this->extract_note_date( $note ) ),
				'author'        => sanitize_text_field( $this->extract_note_author( $note ) ),
				'content'       => sanitize_text_field( $this->strip_note_content( $content ) ),
				'customer_note' => (bool) $this->extract_note_customer_flag( $note ),
			);
		}

		return $summary;
	}

	/**
	 * @param object $order Order instance.
	 * @return array
	 */
	private function get_tracking_context( $order ) {
		$shipments = array();

		if ( $this->is_wc_shipment_tracking_active() ) {
			$shipments = array_merge( $shipments, $this->get_wc_shipment_tracking( $order ) );
		}

		if ( $this->is_aftership_active() ) {
			$shipments = array_merge( $shipments, $this->get_aftership_tracking( $order ) );
		}

		if ( $this->is_shipstation_active() ) {
			$shipments = array_merge( $shipments, $this->get_shipstation_tracking( $order ) );
		}

		$shipments = $this->dedupe_shipments( $shipments );
		$primary   = ! empty( $shipments ) ? $shipments[0] : array();

		return array(
			'number'    => isset( $primary['number'] ) ? $primary['number'] : '',
			'url'       => isset( $primary['url'] ) ? $primary['url'] : '',
			'provider'  => isset( $primary['provider'] ) ? $primary['provider'] : '',
			'source'    => isset( $primary['source'] ) ? $primary['source'] : '',
			'shipments' => $shipments,
		);
	}

	/**
	 * @param object $order Order instance.
	 * @param array  $items Item summary.
	 * @param array  $tracking Tracking context.
	 * @return array
	 */
	private function detect_issues( $order, array $items, array $tracking ) {
		$backordered_items = array();
		foreach ( $items as $item ) {
			if ( ! empty( $item['backordered'] ) ) {
				$backordered_items[] = array(
					'item_id'      => isset( $item['item_id'] ) ? intval( $item['item_id'] ) : 0,
					'product_id'   => isset( $item['product_id'] ) ? intval( $item['product_id'] ) : 0,
					'variation_id' => isset( $item['variation_id'] ) ? intval( $item['variation_id'] ) : 0,
					'name'         => isset( $item['name'] ) ? sanitize_text_field( $item['name'] ) : '',
					'quantity'     => isset( $item['quantity'] ) ? intval( $item['quantity'] ) : 0,
				);
			}
		}

		$payment_failed  = $this->has_payment_failed( $order );
		$delayed_shipping = $this->is_delayed_shipping( $order, $tracking );

		return array(
			'payment_failed'    => $payment_failed,
			'backordered_items' => $backordered_items,
			'delayed_shipping'  => $delayed_shipping,
			'has_issues'        => ( $payment_failed || $delayed_shipping || ! empty( $backordered_items ) ),
		);
	}

	/**
	 * @param object $order Order instance.
	 * @param array  $shipping_methods Shipping items.
	 * @return string
	 */
	private function get_estimated_delivery( $order, array $shipping_methods ) {
		if ( ! $order || ! method_exists( $order, 'get_meta' ) ) {
			return '';
		}

		$keys = array(
			'_estimated_delivery',
			'_estimated_delivery_date',
			'estimated_delivery',
			'estimated_delivery_date',
			'_delivery_date',
			'delivery_date',
			'aftership_estimated_delivery',
		);

		foreach ( $keys as $key ) {
			$value = $order->get_meta( $key );
			if ( '' !== $value && null !== $value ) {
				return $this->normalize_date_value( $value );
			}
		}

		if ( method_exists( $order, 'get_items' ) ) {
			foreach ( $order->get_items( 'shipping' ) as $item ) {
				if ( ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) ) {
					continue;
				}

				foreach ( $keys as $key ) {
					$value = $item->get_meta( $key, true );
					if ( '' !== $value && null !== $value ) {
						return $this->normalize_date_value( $value );
					}
				}
			}
		}

		return '';
	}

	/**
	 * @param object $order Order instance.
	 * @return string
	 */
	private function get_card_last4( $order ) {
		if ( ! $order || ! method_exists( $order, 'get_meta' ) ) {
			return '';
		}

		$keys = array(
			'_card_last4',
			'card_last4',
			'_stripe_card_last4',
			'stripe_card_last4',
			'_wc_authorize_net_cim_credit_card_last4',
			'_authorize_net_last4',
			'_braintree_credit_card_last4',
			'_braintree_last4',
			'_paypal_card_last4',
		);

		foreach ( $keys as $key ) {
			$value = $order->get_meta( $key );
			$value = is_scalar( $value ) ? (string) $value : '';
			if ( '' === $value ) {
				continue;
			}

			$digits = preg_replace( '/\D+/', '', $value );
			if ( strlen( $digits ) >= 4 ) {
				return substr( $digits, -4 );
			}
		}

		return '';
	}

	/**
	 * @param object $order Order instance.
	 * @return bool
	 */
	private function has_payment_failed( $order ) {
		if ( ! $order ) {
			return false;
		}

		if ( method_exists( $order, 'has_status' ) ) {
			return (bool) $order->has_status( 'failed' );
		}

		return ( method_exists( $order, 'get_status' ) && 'failed' === $order->get_status() );
	}

	/**
	 * @param object $order Order instance.
	 * @param array  $tracking Tracking context.
	 * @return bool
	 */
	private function is_delayed_shipping( $order, array $tracking ) {
		if ( ! $order || ! method_exists( $order, 'get_date_created' ) ) {
			return false;
		}

		$created = $order->get_date_created();
		if ( ! $created || ! method_exists( $created, 'getTimestamp' ) ) {
			return false;
		}

		$age_seconds = time() - $created->getTimestamp();
		$cutoff      = self::DELAYED_SHIPPING_DAYS * ( defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400 );

		if ( $age_seconds < $cutoff ) {
			return false;
		}

		return ! $this->is_order_shipped( $order, $tracking );
	}

	/**
	 * @param object $order Order instance.
	 * @param array  $tracking Tracking context.
	 * @return bool
	 */
	private function is_order_shipped( $order, array $tracking ) {
		$shipped_statuses = array( 'completed', 'shipped', 'delivered' );

		if ( $order && method_exists( $order, 'has_status' ) && $order->has_status( $shipped_statuses ) ) {
			return true;
		}

		if ( $order && method_exists( $order, 'get_status' ) ) {
			$status = $order->get_status();
			if ( in_array( $status, $shipped_statuses, true ) ) {
				return true;
			}
		}

		if ( ! empty( $tracking['shipments'] ) ) {
			return true;
		}

		if ( isset( $tracking['number'] ) && '' !== $tracking['number'] ) {
			return true;
		}

		if ( $order && method_exists( $order, 'get_meta' ) ) {
			$date_shipped = $order->get_meta( '_date_shipped' );
			if ( '' !== $date_shipped && null !== $date_shipped ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param object $order Order instance.
	 * @return array
	 */
	private function get_wc_shipment_tracking( $order ) {
		if ( ! $order || ! method_exists( $order, 'get_meta' ) ) {
			return array();
		}

		$items = $order->get_meta( '_wc_shipment_tracking_items' );
		if ( empty( $items ) ) {
			return array();
		}

		if ( is_string( $items ) && function_exists( 'maybe_unserialize' ) ) {
			$items = maybe_unserialize( $items );
		}

		if ( ! is_array( $items ) ) {
			return array();
		}

		$providers = function_exists( 'wc_shipment_tracking_get_providers' ) ? wc_shipment_tracking_get_providers() : array();
		$shipments = array();

		foreach ( $items as $tracking_item ) {
			if ( ! is_array( $tracking_item ) ) {
				continue;
			}

			$number = isset( $tracking_item['tracking_number'] ) ? (string) $tracking_item['tracking_number'] : '';
			if ( '' === $number ) {
				continue;
			}

			$provider = '';
			if ( ! empty( $tracking_item['tracking_provider'] ) && 'custom' !== $tracking_item['tracking_provider'] ) {
				$provider = (string) $tracking_item['tracking_provider'];
			}

			if ( '' === $provider && ! empty( $tracking_item['custom_tracking_provider'] ) ) {
				$provider = (string) $tracking_item['custom_tracking_provider'];
			}

			$url = '';
			if ( ! empty( $tracking_item['custom_tracking_link'] ) ) {
				$url = (string) $tracking_item['custom_tracking_link'];
			}

			if ( '' === $url && '' !== $provider ) {
				$url = $this->build_wc_tracking_url( $providers, $provider, $number );
			}

			$date_shipped = '';
			if ( isset( $tracking_item['date_shipped'] ) ) {
				$date_shipped = $this->normalize_date_value( $tracking_item['date_shipped'] );
			}

			$shipments[] = $this->normalize_shipment( $number, $url, $provider, 'woocommerce_shipment_tracking', $date_shipped );
		}

		return $shipments;
	}

	/**
	 * @param object $order Order instance.
	 * @return array
	 */
	private function get_aftership_tracking( $order ) {
		if ( ! $order || ! method_exists( $order, 'get_meta' ) ) {
			return array();
		}

		$number   = $order->get_meta( '_aftership_tracking_number' );
		$provider = $order->get_meta( '_aftership_tracking_provider' );
		$url      = $order->get_meta( '_aftership_tracking_url' );

		if ( '' === $number ) {
			$number = $order->get_meta( 'aftership_tracking_number' );
		}

		if ( '' === $provider ) {
			$provider = $order->get_meta( '_aftership_tracking_provider_name' );
		}

		if ( '' === $provider ) {
			$provider = $order->get_meta( 'aftership_tracking_provider' );
		}

		if ( '' === $url ) {
			$url = $order->get_meta( 'aftership_tracking_url' );
		}

		$shipments = array();
		foreach ( $this->split_tracking_numbers( $number ) as $tracking_number ) {
			$tracking_url = $url;
			if ( '' === $tracking_url ) {
				$tracking_url = $this->build_aftership_url( $provider, $tracking_number );
			}

			$shipments[] = $this->normalize_shipment( $tracking_number, $tracking_url, $provider, 'aftership', '' );
		}

		return $shipments;
	}

	/**
	 * @param object $order Order instance.
	 * @return array
	 */
	private function get_shipstation_tracking( $order ) {
		if ( ! $order || ! method_exists( $order, 'get_meta' ) ) {
			return array();
		}

		$number      = $order->get_meta( '_shipstation_tracking_number' );
		$provider    = $order->get_meta( '_shipstation_tracking_provider' );
		$url         = $order->get_meta( '_shipstation_tracking_url' );
		$date_shipped = $order->get_meta( '_shipstation_ship_date' );

		if ( '' === $number ) {
			$number = $order->get_meta( 'shipstation_tracking_number' );
		}

		if ( '' === $provider ) {
			$provider = $order->get_meta( 'shipstation_tracking_provider' );
		}

		if ( '' === $url ) {
			$url = $order->get_meta( 'shipstation_tracking_url' );
		}

		if ( '' === $date_shipped ) {
			$date_shipped = $order->get_meta( 'shipstation_ship_date' );
		}

		$ship_date = $this->normalize_date_value( $date_shipped );
		$shipments = array();

		foreach ( $this->split_tracking_numbers( $number ) as $tracking_number ) {
			$shipments[] = $this->normalize_shipment( $tracking_number, $url, $provider, 'shipstation', $ship_date );
		}

		return $shipments;
	}

	/**
	 * @return bool
	 */
	private function is_wc_shipment_tracking_active() {
		return ( class_exists( 'WC_Shipment_Tracking' ) || function_exists( 'wc_shipment_tracking_get_providers' ) || defined( 'WC_SHIPMENT_TRACKING_VERSION' ) );
	}

	/**
	 * @return bool
	 */
	private function is_aftership_active() {
		return ( class_exists( 'AfterShip_Woocommerce_Tracking' ) || class_exists( 'AfterShip' ) || defined( 'AFTERSHIP_VERSION' ) || defined( 'AFTERSHIP_WOOCOMMERCE_VERSION' ) );
	}

	/**
	 * @return bool
	 */
	private function is_shipstation_active() {
		return ( class_exists( 'WC_ShipStation_Integration' ) || class_exists( 'WC_ShipStation' ) || defined( 'WC_SHIPSTATION_VERSION' ) || defined( 'SHIPSTATION_VERSION' ) );
	}

	/**
	 * @param array  $providers Provider list.
	 * @param string $provider_name Provider name.
	 * @param string $tracking_number Tracking number.
	 * @return string
	 */
	private function build_wc_tracking_url( array $providers, $provider_name, $tracking_number ) {
		foreach ( $providers as $country_providers ) {
			if ( ! is_array( $country_providers ) ) {
				continue;
			}

			if ( isset( $country_providers[ $provider_name ] ) ) {
				$template = $country_providers[ $provider_name ];
				if ( is_string( $template ) && '' !== $template ) {
					return sprintf( $template, rawurlencode( $tracking_number ) );
				}
			}
		}

		return '';
	}

	/**
	 * @param string $provider Provider slug or name.
	 * @param string $tracking_number Tracking number.
	 * @return string
	 */
	private function build_aftership_url( $provider, $tracking_number ) {
		if ( '' === $provider || '' === $tracking_number ) {
			return '';
		}

		$slug = function_exists( 'sanitize_title' ) ? sanitize_title( $provider ) : strtolower( preg_replace( '/\s+/', '-', $provider ) );

		return sprintf(
			'https://track.aftership.com/%s/%s',
			rawurlencode( $slug ),
			rawurlencode( $tracking_number )
		);
	}

	/**
	 * @param string $number Tracking number.
	 * @param string $url Tracking URL.
	 * @param string $provider Provider label.
	 * @param string $source Source identifier.
	 * @param string $date_shipped Optional shipped date.
	 * @return array
	 */
	private function normalize_shipment( $number, $url, $provider, $source, $date_shipped = '' ) {
		$number   = is_scalar( $number ) ? (string) $number : '';
		$provider = is_scalar( $provider ) ? (string) $provider : '';
		$url      = is_scalar( $url ) ? (string) $url : '';

		return array(
			'number'       => sanitize_text_field( $number ),
			'url'          => function_exists( 'esc_url_raw' ) ? esc_url_raw( $url ) : sanitize_text_field( $url ),
			'provider'     => sanitize_text_field( $provider ),
			'source'       => sanitize_text_field( $source ),
			'date_shipped' => sanitize_text_field( $date_shipped ),
		);
	}

	/**
	 * @param array $shipments Shipment list.
	 * @return array
	 */
	private function dedupe_shipments( array $shipments ) {
		$unique = array();
		$seen   = array();

		foreach ( $shipments as $shipment ) {
			if ( ! is_array( $shipment ) ) {
				continue;
			}

			$number   = isset( $shipment['number'] ) ? (string) $shipment['number'] : '';
			$provider = isset( $shipment['provider'] ) ? (string) $shipment['provider'] : '';
			$url      = isset( $shipment['url'] ) ? (string) $shipment['url'] : '';

			if ( '' === trim( $number ) && '' === trim( $url ) ) {
				continue;
			}

			$key = md5( strtolower( $number . '|' . $provider . '|' . $url ) );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$unique[]     = $shipment;
		}

		return $unique;
	}

	/**
	 * @param mixed $value Date value.
	 * @return string
	 */
	private function normalize_date_value( $value ) {
		if ( $value instanceof \DateTimeInterface ) {
			return $value->format( 'c' );
		}

		if ( is_numeric( $value ) ) {
			return date( 'c', (int) $value );
		}

		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		if ( '' === $value ) {
			return '';
		}

		$timestamp = strtotime( $value );
		if ( false !== $timestamp ) {
			return date( 'c', $timestamp );
		}

		return sanitize_text_field( $value );
	}

	/**
	 * @param mixed $date Date input.
	 * @return string
	 */
	private function format_datetime( $date ) {
		if ( ! $date ) {
			return '';
		}

		if ( is_object( $date ) && method_exists( $date, 'date' ) ) {
			return $date->date( 'c' );
		}

		return $this->normalize_date_value( $date );
	}

	/**
	 * @param mixed $value Tracking number(s).
	 * @return array
	 */
	private function split_tracking_numbers( $value ) {
		$list = array();

		if ( is_array( $value ) ) {
			$list = $value;
		} else {
			$value = is_scalar( $value ) ? (string) $value : '';
			if ( '' === trim( $value ) ) {
				return array();
			}

			$list = preg_split( '/[\s,|]+/', $value );
		}

		$numbers = array();
		foreach ( $list as $entry ) {
			$entry = trim( (string) $entry );
			if ( '' === $entry ) {
				continue;
			}

			$numbers[ $entry ] = true;
		}

		return array_keys( $numbers );
	}

	/**
	 * @param int    $customer_id Customer ID.
	 * @param string $email Customer email.
	 * @return int
	 */
	private function get_order_count_fallback( $customer_id, $email ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}

		$statuses = function_exists( 'wc_get_order_statuses' ) ? array_keys( wc_get_order_statuses() ) : array( 'any' );
		$query    = array(
			'limit'    => 1,
			'return'   => 'ids',
			'paginate' => true,
			'status'   => $statuses,
		);

		if ( $customer_id > 0 ) {
			$query['customer'] = $customer_id;
		} elseif ( '' !== $email ) {
			$query['billing_email'] = $email;
		}

		$result = wc_get_orders( $query );

		if ( is_object( $result ) && isset( $result->total ) ) {
			return (int) $result->total;
		}

		if ( is_array( $result ) && isset( $result['total'] ) ) {
			return (int) $result['total'];
		}

		return 0;
	}

	/**
	 * @param int    $customer_id Customer ID.
	 * @param string $email Customer email.
	 * @return float
	 */
	private function get_total_spent_fallback( $customer_id, $email ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return 0.0;
		}

		$paid_statuses = function_exists( 'wc_get_is_paid_statuses' ) ? wc_get_is_paid_statuses() : array( 'processing', 'completed', 'on-hold' );

		$query = array(
			'limit'  => self::FALLBACK_SPEND_CUTOFF,
			'return' => 'ids',
			'status' => $paid_statuses,
		);

		if ( $customer_id > 0 ) {
			$query['customer'] = $customer_id;
		} elseif ( '' !== $email ) {
			$query['billing_email'] = $email;
		}

		$order_ids = wc_get_orders( $query );
		if ( ! is_array( $order_ids ) ) {
			return 0.0;
		}

		$total = 0.0;
		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order && method_exists( $order, 'get_total' ) ) {
				$total += (float) $order->get_total();
			}
		}

		return $total;
	}

	/**
	 * @param mixed $note Note object/array.
	 * @return int
	 */
	private function extract_note_id( $note ) {
		if ( is_object( $note ) && isset( $note->id ) ) {
			return intval( $note->id );
		}

		if ( is_array( $note ) && isset( $note['id'] ) ) {
			return intval( $note['id'] );
		}

		return 0;
	}

	/**
	 * @param mixed $note Note object/array.
	 * @return mixed
	 */
	private function extract_note_date( $note ) {
		if ( is_object( $note ) && isset( $note->date_created ) ) {
			return $note->date_created;
		}

		if ( is_array( $note ) && isset( $note['date_created'] ) ) {
			return $note['date_created'];
		}

		return null;
	}

	/**
	 * @param mixed $note Note object/array.
	 * @return string
	 */
	private function extract_note_author( $note ) {
		if ( is_object( $note ) && isset( $note->added_by ) ) {
			return (string) $note->added_by;
		}

		if ( is_array( $note ) && isset( $note['added_by'] ) ) {
			return (string) $note['added_by'];
		}

		return '';
	}

	/**
	 * @param mixed $note Note object/array.
	 * @return bool
	 */
	private function extract_note_customer_flag( $note ) {
		if ( is_object( $note ) && isset( $note->customer_note ) ) {
			return (bool) $note->customer_note;
		}

		if ( is_array( $note ) && isset( $note['customer_note'] ) ) {
			return (bool) $note['customer_note'];
		}

		return false;
	}

	/**
	 * @param string $content Note content.
	 * @return string
	 */
	private function strip_note_content( $content ) {
		if ( function_exists( 'wp_strip_all_tags' ) ) {
			return wp_strip_all_tags( $content );
		}

		return strip_tags( (string) $content );
	}
}
