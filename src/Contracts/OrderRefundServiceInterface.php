<?php
/**
 * Order refund service interface.
 *
 * @package AgentWP\Contracts
 */

namespace AgentWP\Contracts;

use AgentWP\DTO\ServiceResult;

/**
 * Interface for order refund operations.
 */
interface OrderRefundServiceInterface {

	/**
	 * Prepare a refund draft.
	 *
	 * @param int         $order_id      Order ID.
	 * @param float|null  $amount        Refund amount (optional, defaults to full refund).
	 * @param string      $reason        Refund reason.
	 * @param bool        $restock_items Whether to restock items.
	 * @return ServiceResult Result with draft_id or error.
	 */
	public function prepare_refund( int $order_id, ?float $amount = null, string $reason = '', bool $restock_items = true ): ServiceResult;

	/**
	 * Confirm and execute a refund.
	 *
	 * @param string $draft_id Draft identifier.
	 * @return ServiceResult Result with refund_id on success or error.
	 */
	public function confirm_refund( string $draft_id ): ServiceResult;
}
