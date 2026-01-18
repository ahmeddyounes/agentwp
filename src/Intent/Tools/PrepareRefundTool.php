<?php
/**
 * Executable tool for preparing order refunds.
 *
 * @package AgentWP\Intent\Tools
 */

namespace AgentWP\Intent\Tools;

use AgentWP\Contracts\ExecutableToolInterface;
use AgentWP\Contracts\OrderRefundServiceInterface;

/**
 * Prepares a draft refund for an order without executing it.
 *
 * Uses two-phase execution: prepare creates a draft, confirm executes it.
 */
class PrepareRefundTool implements ExecutableToolInterface {

	/**
	 * @var OrderRefundServiceInterface
	 */
	private OrderRefundServiceInterface $service;

	/**
	 * Initialize the tool.
	 *
	 * @param OrderRefundServiceInterface $service Refund service.
	 */
	public function __construct( OrderRefundServiceInterface $service ) {
		$this->service = $service;
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'prepare_refund';
	}

	/**
	 * Prepare a refund draft.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Preparation result with draft_id on success.
	 */
	public function execute( array $arguments ): array {
		$order_id      = isset( $arguments['order_id'] ) ? (int) $arguments['order_id'] : 0;
		$amount        = isset( $arguments['amount'] ) ? (float) $arguments['amount'] : null;
		$reason        = isset( $arguments['reason'] ) ? (string) $arguments['reason'] : '';
		$restock_items = isset( $arguments['restock_items'] ) ? (bool) $arguments['restock_items'] : true;

		return $this->service->prepare_refund( $order_id, $amount, $reason, $restock_items )->toLegacyArray();
	}
}
