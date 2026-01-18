<?php
/**
 * Order Summary DTO.
 *
 * @package AgentWP\DTO
 */

namespace AgentWP\DTO;

/**
 * Immutable order summary value object.
 *
 * A lightweight order representation for lists and summaries.
 */
final class OrderSummaryDTO {

	/**
	 * Create a new OrderSummaryDTO.
	 *
	 * @param int                   $id             Order ID.
	 * @param string                $status         Order status.
	 * @param float                 $total          Order total.
	 * @param string                $totalFormatted Formatted total.
	 * @param string                $currency       Currency code.
	 * @param string                $dateCreated    Creation date (ISO 8601).
	 * @param string                $customerName   Customer name.
	 * @param string                $customerEmail  Customer email.
	 * @param array<OrderItemDTO>   $itemsSummary   Order items.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $status,
		public readonly float $total,
		public readonly string $totalFormatted,
		public readonly string $currency,
		public readonly string $dateCreated,
		public readonly string $customerName,
		public readonly string $customerEmail,
		public readonly array $itemsSummary,
	) {
	}

	/**
	 * Create from raw order summary data.
	 *
	 * @param array $data Raw order summary data.
	 * @return self
	 */
	public static function fromArray( array $data ): self {
		$items = array();
		if ( isset( $data['items_summary'] ) && is_array( $data['items_summary'] ) ) {
			foreach ( $data['items_summary'] as $item ) {
				if ( is_array( $item ) ) {
					$items[] = OrderItemDTO::fromArray( $item );
				}
			}
		}

		return new self(
			id: isset( $data['id'] ) ? (int) $data['id'] : 0,
			status: isset( $data['status'] ) ? (string) $data['status'] : '',
			total: isset( $data['total'] ) ? (float) $data['total'] : 0.0,
			totalFormatted: isset( $data['total_formatted'] ) ? (string) $data['total_formatted'] : '',
			currency: isset( $data['currency'] ) ? (string) $data['currency'] : '',
			dateCreated: isset( $data['date_created'] ) ? (string) $data['date_created'] : '',
			customerName: isset( $data['customer_name'] ) ? (string) $data['customer_name'] : '',
			customerEmail: isset( $data['customer_email'] ) ? (string) $data['customer_email'] : '',
			itemsSummary: $items,
		);
	}

	/**
	 * Convert to array format.
	 *
	 * @return array
	 */
	public function toArray(): array {
		return array(
			'id'              => $this->id,
			'status'          => $this->status,
			'total'           => $this->total,
			'total_formatted' => $this->totalFormatted,
			'currency'        => $this->currency,
			'date_created'    => $this->dateCreated,
			'customer_name'   => $this->customerName,
			'customer_email'  => $this->customerEmail,
			'items_summary'   => array_map( fn( OrderItemDTO $item ) => $item->toArray(), $this->itemsSummary ),
		);
	}

	/**
	 * Get the total item count.
	 *
	 * @return int
	 */
	public function getItemCount(): int {
		return array_sum( array_map( fn( OrderItemDTO $item ) => $item->quantity, $this->itemsSummary ) );
	}

	/**
	 * Get human-readable status label.
	 *
	 * @return string
	 */
	public function getStatusLabel(): string {
		$labels = array(
			'pending'    => 'Pending Payment',
			'processing' => 'Processing',
			'on-hold'    => 'On Hold',
			'completed'  => 'Completed',
			'cancelled'  => 'Cancelled',
			'refunded'   => 'Refunded',
			'failed'     => 'Failed',
		);

		return $labels[ $this->status ] ?? ucfirst( $this->status );
	}
}
