<?php
/**
 * Fake WooCommerce refund gateway for testing.
 *
 * @package AgentWP\Tests\Fakes
 */

namespace AgentWP\Tests\Fakes;

use AgentWP\Contracts\WooCommerceRefundGatewayInterface;
use WP_Error;

/**
 * In-memory refund gateway for testing.
 */
final class FakeWooCommerceRefundGateway implements WooCommerceRefundGatewayInterface {

	/**
	 * Stored orders.
	 *
	 * @var array<int, object>
	 */
	private array $orders = array();

	/**
	 * Created refunds.
	 *
	 * @var array
	 */
	private array $refunds = array();

	/**
	 * Next refund ID.
	 *
	 * @var int
	 */
	private int $nextRefundId = 1;

	/**
	 * Whether to fail the next refund creation.
	 *
	 * @var string|null
	 */
	private ?string $failNextRefund = null;

	/**
	 * Add an order to the gateway.
	 *
	 * @param int    $order_id             Order ID.
	 * @param float  $remaining_refund     Remaining refundable amount.
	 * @param string $currency             Currency code.
	 * @param string $customer_name        Customer name.
	 * @param array  $items                Order items (each with product_id).
	 * @return self
	 */
	public function addOrder(
		int $order_id,
		float $remaining_refund,
		string $currency = 'USD',
		string $customer_name = 'Test Customer',
		array $items = array()
	): self {
		$this->orders[ $order_id ] = new class( $order_id, $remaining_refund, $currency, $customer_name, $items ) {
			private int $id;
			private float $remaining;
			private string $currency;
			private string $customerName;
			private array $items;

			public function __construct( int $id, float $remaining, string $currency, string $customerName, array $items ) {
				$this->id           = $id;
				$this->remaining    = $remaining;
				$this->currency     = $currency;
				$this->customerName = $customerName;
				$this->items        = $items;
			}

			public function get_id(): int {
				return $this->id;
			}

			public function get_remaining_refund_amount(): float {
				return $this->remaining;
			}

			public function get_currency(): string {
				return $this->currency;
			}

			public function get_formatted_billing_full_name(): string {
				return $this->customerName;
			}

			public function get_items(): array {
				return array_map(
					function ( $item ) {
						return new class( $item['product_id'] ) {
							private int $productId;

							public function __construct( int $productId ) {
								$this->productId = $productId;
							}

							public function get_product_id(): int {
								return $this->productId;
							}
						};
					},
					$this->items
				);
			}

			public function set_remaining_refund_amount( float $amount ): void {
				$this->remaining = $amount;
			}
		};

		return $this;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_order( int $order_id ): ?object {
		return $this->orders[ $order_id ] ?? null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function create_refund( array $args ): object {
		if ( null !== $this->failNextRefund ) {
			$error                 = new WP_Error( 'refund_failed', $this->failNextRefund );
			$this->failNextRefund = null;
			return $error;
		}

		$refund_id = $this->nextRefundId++;
		$refund    = array(
			'id'             => $refund_id,
			'amount'         => $args['amount'] ?? 0,
			'reason'         => $args['reason'] ?? '',
			'order_id'       => $args['order_id'] ?? 0,
			'restock_items'  => $args['restock_items'] ?? false,
			'refund_payment' => $args['refund_payment'] ?? false,
		);

		$this->refunds[ $refund_id ] = $refund;

		// Update remaining refund amount.
		$order_id = $refund['order_id'];
		if ( isset( $this->orders[ $order_id ] ) ) {
			$order     = $this->orders[ $order_id ];
			$remaining = $order->get_remaining_refund_amount() - $refund['amount'];
			$order->set_remaining_refund_amount( max( 0, $remaining ) );
		}

		return new class( $refund_id ) {
			private int $id;

			public function __construct( int $id ) {
				$this->id = $id;
			}

			public function get_id(): int {
				return $this->id;
			}
		};
	}

	// Test helpers.

	/**
	 * Set the next refund creation to fail.
	 *
	 * @param string $message Error message.
	 * @return self
	 */
	public function failNextRefund( string $message ): self {
		$this->failNextRefund = $message;
		return $this;
	}

	/**
	 * Get all created refunds.
	 *
	 * @return array
	 */
	public function getRefunds(): array {
		return $this->refunds;
	}

	/**
	 * Get a specific refund by ID.
	 *
	 * @param int $refund_id Refund ID.
	 * @return array|null
	 */
	public function getRefund( int $refund_id ): ?array {
		return $this->refunds[ $refund_id ] ?? null;
	}

	/**
	 * Clear all data.
	 *
	 * @return void
	 */
	public function clear(): void {
		$this->orders         = array();
		$this->refunds        = array();
		$this->nextRefundId   = 1;
		$this->failNextRefund = null;
	}
}
