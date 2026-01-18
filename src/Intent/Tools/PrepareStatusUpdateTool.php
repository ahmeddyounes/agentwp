<?php
/**
 * Executable tool for preparing order status updates.
 *
 * @package AgentWP\Intent\Tools
 */

namespace AgentWP\Intent\Tools;

use AgentWP\Contracts\ExecutableToolInterface;
use AgentWP\Contracts\OrderStatusServiceInterface;

/**
 * Prepares a draft order status update without applying it.
 *
 * Uses two-phase execution: prepare creates a draft, confirm applies it.
 */
class PrepareStatusUpdateTool implements ExecutableToolInterface {

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
		return 'prepare_status_update';
	}

	/**
	 * Prepare a status update draft.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Preparation result with draft_id on success.
	 */
	public function execute( array $arguments ): array {
		$order_id        = isset( $arguments['order_id'] ) ? (int) $arguments['order_id'] : 0;
		$new_status      = isset( $arguments['new_status'] ) ? (string) $arguments['new_status'] : '';
		$note            = isset( $arguments['note'] ) ? (string) $arguments['note'] : '';
		$notify_customer = isset( $arguments['notify_customer'] ) ? (bool) $arguments['notify_customer'] : false;

		return $this->service->prepare_update( $order_id, $new_status, $note, $notify_customer )->toLegacyArray();
	}
}
