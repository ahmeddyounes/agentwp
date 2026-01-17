<?php
/**
 * Fake WooCommerce order gateway for testing.
 *
 * @package AgentWP\Tests\Fakes
 */

namespace AgentWP\Tests\Fakes;

use AgentWP\Contracts\WooCommerceOrderGatewayInterface;

/**
 * In-memory order gateway for testing.
 */
final class FakeWooCommerceOrderGateway implements WooCommerceOrderGatewayInterface {

	/**
	 * Stored orders.
	 *
	 * @var array<int, object>
	 */
	private array $orders = array();

	/**
	 * Valid order statuses.
	 *
	 * @var array<string, string>
	 */
	private array $statuses = array(
		'wc-pending'    => 'Pending payment',
		'wc-processing' => 'Processing',
		'wc-on-hold'    => 'On hold',
		'wc-completed'  => 'Completed',
		'wc-cancelled'  => 'Cancelled',
		'wc-refunded'   => 'Refunded',
		'wc-failed'     => 'Failed',
	);

	/**
	 * Whether emails are enabled.
	 *
	 * @var bool
	 */
	private bool $emailsEnabled = true;

	/**
	 * Status update history.
	 *
	 * @var array
	 */
	private array $statusUpdateHistory = array();

	/**
	 * Add an order to the gateway.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $status   Current status (without 'wc-' prefix).
	 * @return self
	 */
	public function addOrder( int $order_id, string $status = 'pending' ): self {
		$gateway = $this;

		$this->orders[ $order_id ] = new class( $order_id, $status, $gateway ) {
			private int $id;
			private string $status;
			private FakeWooCommerceOrderGateway $gateway;

			public function __construct( int $id, string $status, FakeWooCommerceOrderGateway $gateway ) {
				$this->id      = $id;
				$this->status  = $status;
				$this->gateway = $gateway;
			}

			public function get_id(): int {
				return $this->id;
			}

			public function get_status(): string {
				return $this->status;
			}

			public function update_status( string $new_status, string $note = '' ): void {
				$this->gateway->recordStatusUpdate( $this->id, $this->status, $new_status, $note );
				$this->status = $new_status;
			}
		};

		return $this;
	}

	/**
	 * Record a status update (called from order object).
	 *
	 * @param int    $order_id   Order ID.
	 * @param string $old_status Old status.
	 * @param string $new_status New status.
	 * @param string $note       Optional note.
	 * @return void
	 */
	public function recordStatusUpdate( int $order_id, string $old_status, string $new_status, string $note ): void {
		$this->statusUpdateHistory[] = array(
			'order_id'       => $order_id,
			'old_status'     => $old_status,
			'new_status'     => $new_status,
			'note'           => $note,
			'emails_enabled' => $this->emailsEnabled,
		);
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
	public function get_order_statuses(): array {
		return $this->statuses;
	}

	/**
	 * {@inheritDoc}
	 */
	public function update_order_status( object $order, string $new_status, string $note = '' ): bool {
		if ( ! method_exists( $order, 'update_status' ) ) {
			return false;
		}

		$order->update_status( $new_status, $note );
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function set_emails_enabled( bool $enabled ): void {
		$this->emailsEnabled = $enabled;
	}

	// Test helpers.

	/**
	 * Set custom order statuses.
	 *
	 * @param array<string, string> $statuses Statuses array.
	 * @return self
	 */
	public function setStatuses( array $statuses ): self {
		$this->statuses = $statuses;
		return $this;
	}

	/**
	 * Check if emails are currently enabled.
	 *
	 * @return bool
	 */
	public function areEmailsEnabled(): bool {
		return $this->emailsEnabled;
	}

	/**
	 * Get status update history.
	 *
	 * @return array
	 */
	public function getStatusUpdateHistory(): array {
		return $this->statusUpdateHistory;
	}

	/**
	 * Get the current status of an order.
	 *
	 * @param int $order_id Order ID.
	 * @return string|null
	 */
	public function getOrderStatus( int $order_id ): ?string {
		$order = $this->orders[ $order_id ] ?? null;
		return $order ? $order->get_status() : null;
	}

	/**
	 * Clear all data.
	 *
	 * @return void
	 */
	public function clear(): void {
		$this->orders              = array();
		$this->statusUpdateHistory = array();
		$this->emailsEnabled       = true;
	}
}
