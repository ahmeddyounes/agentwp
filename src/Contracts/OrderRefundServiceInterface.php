<?php
/**
 * Order refund service interface.
 *
 * @package AgentWP\Contracts
 */

namespace AgentWP\Contracts;

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
	 * @return array Result with draft_id or error.
	 */
	public function prepare_refund( int $order_id, ?float $amount = null, string $reason = '', bool $restock_items = true ): array;

	/**
	 * Confirm and execute a refund.
	 *
	 * @param string $draft_id Draft identifier.
	 * @return array Result with refund_id on success or error.
	 */
	public function confirm_refund( string $draft_id ): array;
}
