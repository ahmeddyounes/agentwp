<?php
/**
 * Fake policy for testing.
 *
 * @package AgentWP\Tests\Fakes
 */

namespace AgentWP\Tests\Fakes;

use AgentWP\Contracts\PolicyInterface;

/**
 * Test double for PolicyInterface.
 *
 * By default, all capability checks return true (permissive).
 * Individual capabilities can be configured to return false.
 */
final class FakePolicy implements PolicyInterface {

	/**
	 * @var array<string, bool> Configurable capability results.
	 */
	private array $capabilities = array();

	/**
	 * Set a capability result.
	 *
	 * @param string $capability Capability name (method name without "can" prefix).
	 * @param bool   $result     Whether to allow the capability.
	 * @return self
	 */
	public function setCapability( string $capability, bool $result ): self {
		$this->capabilities[ $capability ] = $result;
		return $this;
	}

	/**
	 * Deny all capabilities.
	 *
	 * @return self
	 */
	public function denyAll(): self {
		$this->capabilities['ManageOrders']      = false;
		$this->capabilities['ManageProducts']    = false;
		$this->capabilities['RefundOrders']      = false;
		$this->capabilities['UpdateOrderStatus'] = false;
		$this->capabilities['ManageStock']       = false;
		$this->capabilities['DraftEmails']       = false;
		return $this;
	}

	/**
	 * Allow all capabilities.
	 *
	 * @return self
	 */
	public function allowAll(): self {
		$this->capabilities = array();
		return $this;
	}

	/**
	 * Check if the current user can manage WooCommerce orders.
	 *
	 * @return bool True if user has permission.
	 */
	public function canManageOrders(): bool {
		return $this->capabilities['ManageOrders'] ?? true;
	}

	/**
	 * Check if the current user can manage WooCommerce products.
	 *
	 * @return bool True if user has permission.
	 */
	public function canManageProducts(): bool {
		return $this->capabilities['ManageProducts'] ?? true;
	}

	/**
	 * Check if the current user can issue refunds.
	 *
	 * @return bool True if user has permission.
	 */
	public function canRefundOrders(): bool {
		return $this->capabilities['RefundOrders'] ?? true;
	}

	/**
	 * Check if the current user can update order statuses.
	 *
	 * @return bool True if user has permission.
	 */
	public function canUpdateOrderStatus(): bool {
		return $this->capabilities['UpdateOrderStatus'] ?? true;
	}

	/**
	 * Check if the current user can manage product stock.
	 *
	 * @return bool True if user has permission.
	 */
	public function canManageStock(): bool {
		return $this->capabilities['ManageStock'] ?? true;
	}

	/**
	 * Check if the current user can draft customer emails.
	 *
	 * @return bool True if user has permission.
	 */
	public function canDraftEmails(): bool {
		return $this->capabilities['DraftEmails'] ?? true;
	}
}
