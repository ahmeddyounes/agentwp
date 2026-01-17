<?php
/**
 * WooCommerce policy implementation.
 *
 * @package AgentWP\Security\Policy
 */

namespace AgentWP\Security\Policy;

use AgentWP\Contracts\PolicyInterface;
use AgentWP\Infrastructure\WPFunctions;

/**
 * WooCommerce-specific policy implementation.
 *
 * This class centralizes all capability checks for WooCommerce operations,
 * keeping domain services free of direct WordPress function calls.
 */
final class WooCommercePolicy implements PolicyInterface {

	private WPFunctions $wp;

	/**
	 * Constructor.
	 *
	 * @param WPFunctions $wp WordPress functions wrapper.
	 */
	public function __construct( WPFunctions $wp ) {
		$this->wp = $wp;
	}

	/**
	 * Check if the current user can manage WooCommerce orders.
	 *
	 * @return bool True if user has permission.
	 */
	public function canManageOrders(): bool {
		return $this->wp->currentUserCan( 'manage_woocommerce' );
	}

	/**
	 * Check if the current user can manage WooCommerce products.
	 *
	 * @return bool True if user has permission.
	 */
	public function canManageProducts(): bool {
		return $this->wp->currentUserCan( 'manage_woocommerce' );
	}

	/**
	 * Check if the current user can issue refunds.
	 *
	 * @return bool True if user has permission.
	 */
	public function canRefundOrders(): bool {
		return $this->wp->currentUserCan( 'manage_woocommerce' );
	}

	/**
	 * Check if the current user can update order statuses.
	 *
	 * @return bool True if user has permission.
	 */
	public function canUpdateOrderStatus(): bool {
		return $this->wp->currentUserCan( 'manage_woocommerce' );
	}

	/**
	 * Check if the current user can manage product stock.
	 *
	 * @return bool True if user has permission.
	 */
	public function canManageStock(): bool {
		return $this->wp->currentUserCan( 'manage_woocommerce' );
	}

	/**
	 * Check if the current user can draft customer emails.
	 *
	 * @return bool True if user has permission.
	 */
	public function canDraftEmails(): bool {
		return $this->wp->currentUserCan( 'manage_woocommerce' );
	}
}
