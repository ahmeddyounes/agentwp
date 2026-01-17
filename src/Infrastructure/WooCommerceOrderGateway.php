<?php
/**
 * WooCommerce order gateway implementation.
 *
 * @package AgentWP\Infrastructure
 */

namespace AgentWP\Infrastructure;

use AgentWP\Contracts\WooCommerceOrderGatewayInterface;
use Exception;

/**
 * Wraps WooCommerce order status functions.
 */
final class WooCommerceOrderGateway implements WooCommerceOrderGatewayInterface {

	/**
	 * Whether emails are currently suppressed.
	 *
	 * @var bool
	 */
	private bool $emailsSuppressed = false;

	/**
	 * {@inheritDoc}
	 */
	public function get_order( int $order_id ): ?object {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order || ! ( $order instanceof \WC_Order ) ) {
			return null;
		}

		return $order;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_order_statuses(): array {
		if ( ! function_exists( 'wc_get_order_statuses' ) ) {
			return array();
		}

		return wc_get_order_statuses();
	}

	/**
	 * {@inheritDoc}
	 */
	public function update_order_status( object $order, string $new_status, string $note = '' ): bool {
		if ( ! method_exists( $order, 'update_status' ) ) {
			return false;
		}

		try {
			$order->update_status( $new_status, $note );
			return true;
		} catch ( Exception $e ) {
			return false;
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function set_emails_enabled( bool $enabled ): void {
		if ( $enabled && $this->emailsSuppressed ) {
			remove_filter( 'woocommerce_email_enabled', '__return_false' );
			$this->emailsSuppressed = false;
		} elseif ( ! $enabled && ! $this->emailsSuppressed ) {
			add_filter( 'woocommerce_email_enabled', '__return_false' );
			$this->emailsSuppressed = true;
		}
	}
}
