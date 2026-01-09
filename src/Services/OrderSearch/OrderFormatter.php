<?php
/**
 * Order formatter for search results.
 *
 * @package AgentWP\Services\OrderSearch
 */

namespace AgentWP\Services\OrderSearch;

use AgentWP\DTO\OrderDTO;
use WC_Order;

/**
 * Formats orders for API responses.
 */
final class OrderFormatter {

	/**
	 * Format a WooCommerce order into a response array.
	 *
	 * @param WC_Order $order The order.
	 * @return array
	 */
	public function format( WC_Order $order ): array {
		$dateCreated = $order->get_date_created();

		return array(
			'id'               => $order->get_id(),
			'status'           => sanitize_text_field( $order->get_status() ),
			'total'            => $order->get_total(),
			'customer_name'    => sanitize_text_field( $this->getCustomerName( $order ) ),
			'customer_email'   => sanitize_email( $this->getCustomerEmail( $order ) ),
			'date_created'     => $dateCreated ? $dateCreated->date( 'c' ) : '',
			'items_summary'    => sanitize_text_field( $this->formatItemsSummary( $order ) ),
			'shipping_address' => $this->formatShippingAddress( $order ),
		);
	}

	/**
	 * Format an OrderDTO into a response array.
	 *
	 * @param OrderDTO $order The order DTO.
	 * @return array
	 */
	public function formatDTO( OrderDTO $order ): array {
		return array(
			'id'               => $order->id,
			'status'           => sanitize_text_field( $order->status ),
			'total'            => $order->total,
			'customer_name'    => sanitize_text_field( $order->customerName ),
			'customer_email'   => sanitize_email( $order->customerEmail ),
			'date_created'     => $order->dateCreated?->format( 'c' ) ?? '',
			'items_summary'    => sanitize_text_field( $order->getItemsSummary() ),
			'shipping_address' => $this->sanitizeAddressArray( $order->shippingAddress ),
		);
	}

	/**
	 * Sanitize an address array for safe output.
	 *
	 * @param array $address Address fields.
	 * @return array Sanitized address.
	 */
	private function sanitizeAddressArray( array $address ): array {
		$sanitized = array();
		foreach ( $address as $key => $value ) {
			$sanitized[ $key ] = is_string( $value ) ? sanitize_text_field( $value ) : $value;
		}
		return $sanitized;
	}

	/**
	 * Format multiple orders.
	 *
	 * @param WC_Order[] $orders Array of orders.
	 * @return array[]
	 */
	public function formatMany( array $orders ): array {
		return array_map( fn( $order ) => $this->format( $order ), $orders );
	}

	/**
	 * Format multiple OrderDTOs.
	 *
	 * @param OrderDTO[] $orders Array of order DTOs.
	 * @return array[]
	 */
	public function formatManyDTOs( array $orders ): array {
		return array_map( fn( $order ) => $this->formatDTO( $order ), $orders );
	}

	/**
	 * Get customer name from order.
	 *
	 * @param WC_Order $order The order.
	 * @return string
	 */
	private function getCustomerName( WC_Order $order ): string {
		$first = $order->get_billing_first_name();
		$last  = $order->get_billing_last_name();
		$name  = trim( $first . ' ' . $last );

		if ( '' !== $name ) {
			return $name;
		}

		$first = $order->get_shipping_first_name();
		$last  = $order->get_shipping_last_name();

		return trim( $first . ' ' . $last );
	}

	/**
	 * Get customer email from order.
	 *
	 * @param WC_Order $order The order.
	 * @return string
	 */
	private function getCustomerEmail( WC_Order $order ): string {
		$email = $order->get_billing_email();

		if ( '' !== $email ) {
			return $email;
		}

		$email = $order->get_meta( '_shipping_email' );

		return is_string( $email ) ? $email : '';
	}

	/**
	 * Format order items summary.
	 *
	 * @param WC_Order $order The order.
	 * @return string
	 */
	private function formatItemsSummary( WC_Order $order ): string {
		$items = $order->get_items();

		if ( empty( $items ) ) {
			return '';
		}

		$summary = array();

		foreach ( $items as $item ) {
			$name = $item->get_name();
			$qty  = $item->get_quantity();

			if ( $qty > 1 ) {
				$summary[] = sprintf( '%dx %s', $qty, $name );
			} else {
				$summary[] = $name;
			}
		}

		return implode( ', ', $summary );
	}

	/**
	 * Format shipping address.
	 *
	 * @param WC_Order $order The order.
	 * @return array
	 */
	private function formatShippingAddress( WC_Order $order ): array {
		$shipping = $order->get_address( 'shipping' );
		$billing  = $order->get_address( 'billing' );

		if ( ! is_array( $shipping ) ) {
			$shipping = array();
		}

		if ( ! is_array( $billing ) ) {
			$billing = array();
		}

		$fields = array(
			'first_name',
			'last_name',
			'company',
			'address_1',
			'address_2',
			'city',
			'state',
			'postcode',
			'country',
		);

		$address = array();

		foreach ( $fields as $field ) {
			$value = isset( $shipping[ $field ] ) ? trim( (string) $shipping[ $field ] ) : '';

			if ( '' === $value && isset( $billing[ $field ] ) ) {
				$value = trim( (string) $billing[ $field ] );
			}

			$address[ $field ] = $value;
		}

		$name = trim( $address['first_name'] . ' ' . $address['last_name'] );

		// Sanitize all address fields to prevent XSS.
		return array(
			'name'      => sanitize_text_field( $name ),
			'company'   => sanitize_text_field( $address['company'] ),
			'address_1' => sanitize_text_field( $address['address_1'] ),
			'address_2' => sanitize_text_field( $address['address_2'] ),
			'city'      => sanitize_text_field( $address['city'] ),
			'state'     => sanitize_text_field( $address['state'] ),
			'postcode'  => sanitize_text_field( $address['postcode'] ),
			'country'   => sanitize_text_field( $address['country'] ),
		);
	}

	/**
	 * Format a query summary for response.
	 *
	 * @param array $normalized Normalized query parameters.
	 * @return array
	 */
	public function formatQuerySummary( array $normalized ): array {
		return array(
			'order_id'   => $normalized['order_id'] ?? 0,
			'email'      => $normalized['email'] ?? '',
			'status'     => $normalized['status'] ?? '',
			'limit'      => $normalized['limit'] ?? 10,
			'date_range' => $normalized['date_range'] ?? null,
		);
	}
}
