<?php
/**
 * Customer service interface.
 *
 * @package AgentWP\Contracts
 */

namespace AgentWP\Contracts;

/**
 * Interface for customer profile operations.
 */
interface CustomerServiceInterface {

	/**
	 * Handle a customer profile request.
	 *
	 * @param array $args Request arguments including customer_id and/or email.
	 * @return array Customer profile data including metrics, orders, and health status.
	 */
	public function handle( array $args );
}
