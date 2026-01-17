<?php
/**
 * Order status service interface.
 *
 * @package AgentWP\Contracts
 */

namespace AgentWP\Contracts;

use AgentWP\DTO\ServiceResult;

/**
 * Interface for order status update operations.
 */
interface OrderStatusServiceInterface {

	/**
	 * Prepare a draft order status update.
	 *
	 * @param int    $order_id        Order ID.
	 * @param string $new_status      Target status.
	 * @param string $note            Optional note.
	 * @param bool   $notify_customer Whether to notify the customer.
	 * @return ServiceResult Result with draft_id on success or error.
	 */
	public function prepare_update( int $order_id, string $new_status, string $note = '', bool $notify_customer = false ): ServiceResult;

	/**
	 * Prepare a bulk status update.
	 *
	 * @param array  $order_ids       Order IDs.
	 * @param string $new_status      Target status.
	 * @param bool   $notify_customer Whether to notify customers.
	 * @return ServiceResult Result with draft_id on success or error.
	 */
	public function prepare_bulk_update( array $order_ids, string $new_status, bool $notify_customer = false ): ServiceResult;

	/**
	 * Confirm and execute a status update draft.
	 *
	 * @param string $draft_id Draft ID.
	 * @return ServiceResult Result with updated order info or error.
	 */
	public function confirm_update( string $draft_id ): ServiceResult;
}
