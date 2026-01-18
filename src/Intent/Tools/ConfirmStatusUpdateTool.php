<?php
/**
 * Executable tool for confirming order status updates.
 *
 * @package AgentWP\Intent\Tools
 */

namespace AgentWP\Intent\Tools;

use AgentWP\Contracts\ExecutableToolInterface;
use AgentWP\Contracts\OrderStatusServiceInterface;

/**
 * Confirms and applies a prepared status update draft.
 *
 * Second phase of two-phase execution: claims the draft and applies
 * the status change (works for both single and bulk updates).
 */
class ConfirmStatusUpdateTool implements ExecutableToolInterface {

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
		return 'confirm_status_update';
	}

	/**
	 * Confirm and apply a status update.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Confirmation result with updated order details on success.
	 */
	public function execute( array $arguments ): array {
		$draft_id = isset( $arguments['draft_id'] ) ? (string) $arguments['draft_id'] : '';

		return $this->service->confirm_update( $draft_id )->toLegacyArray();
	}
}
