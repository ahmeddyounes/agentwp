<?php
/**
 * Order Search Item DTO.
 *
 * @package AgentWP\DTO
 */

namespace AgentWP\DTO;

/**
 * Immutable order search item value object.
 *
 * Represents a single order in search results.
 */
final class OrderSearchItemDTO {

	/**
	 * Create a new OrderSearchItemDTO.
	 *
	 * @param int               $id              Order ID.
	 * @param string            $status          Order status.
	 * @param float             $total           Order total.
	 * @param string            $customerName    Customer name.
	 * @param string            $customerEmail   Customer email.
	 * @param string            $dateCreated     Creation date (ISO 8601).
	 * @param string            $itemsSummary    Items summary string.
	 * @param AddressDTO        $shippingAddress Shipping address.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $status,
		public readonly float $total,
		public readonly string $customerName,
		public readonly string $customerEmail,
		public readonly string $dateCreated,
		public readonly string $itemsSummary,
		public readonly AddressDTO $shippingAddress,
	) {
	}

	/**
	 * Create from raw order data.
	 *
	 * @param array $data Raw order data.
	 * @return self
	 */
	public static function fromArray( array $data ): self {
		return new self(
			id: isset( $data['id'] ) ? (int) $data['id'] : 0,
			status: isset( $data['status'] ) ? (string) $data['status'] : '',
			total: isset( $data['total'] ) ? (float) $data['total'] : 0.0,
			customerName: isset( $data['customer_name'] ) ? (string) $data['customer_name'] : '',
			customerEmail: isset( $data['customer_email'] ) ? (string) $data['customer_email'] : '',
			dateCreated: isset( $data['date_created'] ) ? (string) $data['date_created'] : '',
			itemsSummary: isset( $data['items_summary'] ) ? (string) $data['items_summary'] : '',
			shippingAddress: AddressDTO::fromArray( isset( $data['shipping_address'] ) && is_array( $data['shipping_address'] ) ? $data['shipping_address'] : array() ),
		);
	}

	/**
	 * Convert to array format.
	 *
	 * @return array
	 */
	public function toArray(): array {
		return array(
			'id'               => $this->id,
			'status'           => $this->status,
			'total'            => $this->total,
			'customer_name'    => $this->customerName,
			'customer_email'   => $this->customerEmail,
			'date_created'     => $this->dateCreated,
			'items_summary'    => $this->itemsSummary,
			'shipping_address' => $this->shippingAddress->toArray(),
		);
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
