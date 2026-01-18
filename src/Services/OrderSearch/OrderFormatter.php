<?php
/**
 * Order formatter for search results.
 *
 * @package AgentWP\Services\OrderSearch
 */

namespace AgentWP\Services\OrderSearch;

use AgentWP\DTO\OrderDTO;

/**
 * Formats orders for API responses.
 */
final class OrderFormatter {

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
	 * Format multiple OrderDTOs.
	 *
	 * @param OrderDTO[] $orders Array of order DTOs.
	 * @return array[]
	 */
	public function formatManyDTOs( array $orders ): array {
		return array_map( fn( $order ) => $this->formatDTO( $order ), $orders );
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
