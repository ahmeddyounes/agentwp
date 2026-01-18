<?php
/**
 * Executable tool for confirming order refunds.
 *
 * @package AgentWP\Intent\Tools
 */

namespace AgentWP\Intent\Tools;

use AgentWP\Contracts\ExecutableToolInterface;
use AgentWP\Contracts\OrderRefundServiceInterface;

/**
 * Confirms and executes a prepared refund draft.
 *
 * Second phase of two-phase execution: claims the draft and processes the refund.
 */
class ConfirmRefundTool implements ExecutableToolInterface {

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
		return 'confirm_refund';
	}

	/**
	 * Confirm and execute a refund.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Confirmation result with refund details on success.
	 */
	public function execute( array $arguments ): array {
		$draft_id = isset( $arguments['draft_id'] ) ? (string) $arguments['draft_id'] : '';

		return $this->service->confirm_refund( $draft_id )->toLegacyArray();
	}
}
