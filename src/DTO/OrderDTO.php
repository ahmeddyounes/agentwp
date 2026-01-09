<?php
/**
 * Order DTO.
 *
 * @package AgentWP\DTO
 */

namespace AgentWP\DTO;

use DateTimeImmutable;

/**
 * Immutable order value object.
 */
final class OrderDTO {

	/**
	 * Create a new OrderDTO.
	 *
	 * @param int                    $id              Order ID.
	 * @param string                 $status          Order status.
	 * @param float                  $total           Order total.
	 * @param string                 $currency        Currency code.
	 * @param string                 $customerName    Customer name.
	 * @param string                 $customerEmail   Customer email.
	 * @param DateTimeImmutable|null $dateCreated     Order creation date.
	 * @param DateTimeImmutable|null $dateModified    Last modification date.
	 * @param array                  $items           Order items.
	 * @param array                  $shippingAddress Shipping address.
	 * @param array                  $billingAddress  Billing address.
	 * @param int|null               $customerId      Customer ID.
	 * @param string                 $paymentMethod   Payment method.
	 * @param string                 $shippingMethod  Shipping method.
	 * @param array                  $meta            Additional metadata.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $status,
		public readonly float $total,
		public readonly string $currency,
		public readonly string $customerName,
		public readonly string $customerEmail,
		public readonly ?DateTimeImmutable $dateCreated,
		public readonly ?DateTimeImmutable $dateModified = null,
		public readonly array $items = array(),
		public readonly array $shippingAddress = array(),
		public readonly array $billingAddress = array(),
		public readonly ?int $customerId = null,
		public readonly string $paymentMethod = '',
		public readonly string $shippingMethod = '',
		public readonly array $meta = array(),
	) {
	}

	/**
	 * Create from a WooCommerce order object.
	 *
	 * @param object $order WC_Order instance.
	 * @return self
	 */
	public static function fromWcOrder( object $order ): self {
		$billing  = method_exists( $order, 'get_billing_first_name' ) ? array(
			'first_name' => $order->get_billing_first_name(),
			'last_name'  => $order->get_billing_last_name(),
			'company'    => $order->get_billing_company(),
			'address_1'  => $order->get_billing_address_1(),
			'address_2'  => $order->get_billing_address_2(),
			'city'       => $order->get_billing_city(),
			'state'      => $order->get_billing_state(),
			'postcode'   => $order->get_billing_postcode(),
			'country'    => $order->get_billing_country(),
			'email'      => $order->get_billing_email(),
			'phone'      => $order->get_billing_phone(),
		) : array();

		$shipping = method_exists( $order, 'get_shipping_first_name' ) ? array(
			'first_name' => $order->get_shipping_first_name(),
			'last_name'  => $order->get_shipping_last_name(),
			'company'    => $order->get_shipping_company(),
			'address_1'  => $order->get_shipping_address_1(),
			'address_2'  => $order->get_shipping_address_2(),
			'city'       => $order->get_shipping_city(),
			'state'      => $order->get_shipping_state(),
			'postcode'   => $order->get_shipping_postcode(),
			'country'    => $order->get_shipping_country(),
		) : array();

		$items = array();
		if ( method_exists( $order, 'get_items' ) ) {
			foreach ( $order->get_items() as $item ) {
				$items[] = array(
					'name'     => $item->get_name(),
					'quantity' => $item->get_quantity(),
					'total'    => (float) $item->get_total(),
				);
			}
		}

		$customerName = trim(
			( $billing['first_name'] ?? '' ) . ' ' . ( $billing['last_name'] ?? '' )
		);

		$dateCreated = null;
		if ( method_exists( $order, 'get_date_created' ) ) {
			$wcDate = $order->get_date_created();
			if ( null !== $wcDate ) {
				// Use strict check - timestamp 0 (Unix epoch) is valid.
				$timestamp   = $wcDate->getTimestamp();
				$dateCreated = DateTimeImmutable::createFromMutable(
					new \DateTime( '@' . $timestamp )
				);
			}
		}

		$dateModified = null;
		if ( method_exists( $order, 'get_date_modified' ) ) {
			$wcDate = $order->get_date_modified();
			if ( null !== $wcDate ) {
				// Use strict check - timestamp 0 (Unix epoch) is valid.
				$timestamp    = $wcDate->getTimestamp();
				$dateModified = DateTimeImmutable::createFromMutable(
					new \DateTime( '@' . $timestamp )
				);
			}
		}

		return new self(
			id: (int) $order->get_id(),
			status: $order->get_status(),
			total: (float) $order->get_total(),
			currency: $order->get_currency(),
			customerName: $customerName,
			customerEmail: $billing['email'] ?? '',
			dateCreated: $dateCreated,
			dateModified: $dateModified,
			items: $items,
			shippingAddress: $shipping,
			billingAddress: $billing,
			// Customer ID 0 means guest order - preserve it, only null if method returns null/false/empty.
			customerId: ( $id = $order->get_customer_id() ) !== false && $id !== '' && $id !== null ? (int) $id : null,
			paymentMethod: $order->get_payment_method_title(),
			shippingMethod: implode( ', ', array_map(
				fn( $item ) => $item->get_method_title(),
				$order->get_shipping_methods()
			) ),
		);
	}

	/**
	 * Get formatted status label.
	 *
	 * @return string Human-readable status.
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

	/**
	 * Get formatted total.
	 *
	 * @return string Formatted total with currency.
	 */
	public function getFormattedTotal(): string {
		return sprintf( '%s %s', $this->currency, number_format( $this->total, 2 ) );
	}

	/**
	 * Get items summary.
	 *
	 * @return string Summary of items.
	 */
	public function getItemsSummary(): string {
		if ( empty( $this->items ) ) {
			return 'No items';
		}

		$count = count( $this->items );
		$total = array_sum( array_column( $this->items, 'quantity' ) );

		return sprintf( '%d item%s (%d total)', $count, 1 === $count ? '' : 's', $total );
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
			'status_label'     => $this->getStatusLabel(),
			'total'            => $this->total,
			'currency'         => $this->currency,
			'formatted_total'  => $this->getFormattedTotal(),
			'customer_name'    => $this->customerName,
			'customer_email'   => $this->customerEmail,
			'customer_id'      => $this->customerId,
			'date_created'     => $this->dateCreated?->format( 'Y-m-d H:i:s' ),
			'date_modified'    => $this->dateModified?->format( 'Y-m-d H:i:s' ),
			'items'            => $this->items,
			'items_summary'    => $this->getItemsSummary(),
			'shipping_address' => $this->shippingAddress,
			'billing_address'  => $this->billingAddress,
			'payment_method'   => $this->paymentMethod,
			'shipping_method'  => $this->shippingMethod,
		);
	}
}
