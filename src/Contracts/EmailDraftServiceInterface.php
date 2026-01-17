<?php
/**
 * Email draft service interface.
 *
 * @package AgentWP\Contracts
 */

namespace AgentWP\Contracts;

use AgentWP\DTO\ServiceResult;

/**
 * Interface for email draft operations.
 */
interface EmailDraftServiceInterface {

	/**
	 * Get order context for drafting an email.
	 *
	 * @param int $order_id Order ID.
	 * @return ServiceResult Result with order context data or error.
	 */
	public function get_order_context( int $order_id ): ServiceResult;
}
