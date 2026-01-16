<?php
/**
 * Email draft service interface.
 *
 * @package AgentWP\Contracts
 */

namespace AgentWP\Contracts;

/**
 * Interface for email draft operations.
 */
interface EmailDraftServiceInterface {

	/**
	 * Get order context for drafting an email.
	 *
	 * @param int $order_id Order ID.
	 * @return array Order context data or error array.
	 */
	public function get_order_context( int $order_id ): array;
}
