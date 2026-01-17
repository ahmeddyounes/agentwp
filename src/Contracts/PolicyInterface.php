<?php
/**
 * Policy interface.
 *
 * @package AgentWP\Contracts
 */

namespace AgentWP\Contracts;

/**
 * Interface for capability/policy checks.
 *
 * This interface abstracts permission checking away from domain services,
 * allowing them to remain free of WordPress-specific function calls.
 */
interface PolicyInterface {

	/**
	 * Check if the current user can manage WooCommerce orders.
	 *
	 * @return bool True if user has permission.
	 */
	public function canManageOrders(): bool;

	/**
	 * Check if the current user can manage WooCommerce products.
	 *
	 * @return bool True if user has permission.
	 */
	public function canManageProducts(): bool;

	/**
	 * Check if the current user can issue refunds.
	 *
	 * @return bool True if user has permission.
	 */
	public function canRefundOrders(): bool;

	/**
	 * Check if the current user can update order statuses.
	 *
	 * @return bool True if user has permission.
	 */
	public function canUpdateOrderStatus(): bool;

	/**
	 * Check if the current user can manage product stock.
	 *
	 * @return bool True if user has permission.
	 */
	public function canManageStock(): bool;

	/**
	 * Check if the current user can draft customer emails.
	 *
	 * @return bool True if user has permission.
	 */
	public function canDraftEmails(): bool;
}
