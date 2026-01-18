<?php
/**
 * Executable tool for preparing bulk order status updates.
 *
 * @package AgentWP\Intent\Tools
 */

namespace AgentWP\Intent\Tools;

use AgentWP\Contracts\ExecutableToolInterface;
use AgentWP\Contracts\OrderStatusServiceInterface;

/**
 * Prepares a draft bulk order status update without applying it.
 *
 * Uses two-phase execution: prepare creates a draft for multiple orders,
 * confirm applies all updates atomically.
 */
class PrepareBulkStatusUpdateTool implements ExecutableToolInterface {

	/**
	 * @var OrderStatusServiceInterface
	 */
	private OrderStatusServiceInterface $service;

	/**
	 * Initialize the tool.
	 *
	 * @param OrderStatusServiceInterface $service Status service.
	 */
	public function __construct( OrderStatusServiceInterface $service ) {
		$this->service = $service;
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'prepare_bulk_status_update';
	}

	/**
	 * Prepare a bulk status update draft.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Preparation result with draft_id on success.
	 */
	public function execute( array $arguments ): array {
		$order_ids       = isset( $arguments['order_ids'] ) ? array_map( 'intval', (array) $arguments['order_ids'] ) : array();
		$new_status      = isset( $arguments['new_status'] ) ? (string) $arguments['new_status'] : '';
		$notify_customer = isset( $arguments['notify_customer'] ) ? (bool) $arguments['notify_customer'] : false;

		return $this->service->prepare_bulk_update( $order_ids, $new_status, $notify_customer )->toLegacyArray();
	}
}
