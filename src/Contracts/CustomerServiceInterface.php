<?php
/**
 * Customer service interface.
 *
 * @package AgentWP\Contracts
 */

namespace AgentWP\Contracts;

use AgentWP\DTO\ServiceResult;

/**
 * Interface for customer profile operations.
 */
interface CustomerServiceInterface {

	/**
	 * Handle a customer profile request.
	 *
	 * @param array $args Request arguments including customer_id and/or email.
	 * @return ServiceResult Result with customer profile data including metrics, orders, and health status.
	 */
	public function handle( array $args ): ServiceResult;
}
