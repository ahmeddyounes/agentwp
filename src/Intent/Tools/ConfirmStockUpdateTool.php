<?php
/**
 * Executable tool for confirming stock updates.
 *
 * @package AgentWP\Intent\Tools
 */

namespace AgentWP\Intent\Tools;

use AgentWP\Contracts\ExecutableToolInterface;
use AgentWP\Contracts\ProductStockServiceInterface;

/**
 * Confirms and executes a previously prepared stock update.
 *
 * Uses two-phase execution: prepare creates a draft, confirm executes it.
 */
class ConfirmStockUpdateTool implements ExecutableToolInterface {

	/**
	 * @var ProductStockServiceInterface
	 */
	private ProductStockServiceInterface $service;

	/**
	 * Initialize the tool.
	 *
	 * @param ProductStockServiceInterface $service Product stock service.
	 */
	public function __construct( ProductStockServiceInterface $service ) {
		$this->service = $service;
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'confirm_stock_update';
	}

	/**
	 * Confirm a stock update draft.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Confirmation result with new stock on success.
	 */
	public function execute( array $arguments ): array {
		$draft_id = isset( $arguments['draft_id'] ) ? (string) $arguments['draft_id'] : '';

		if ( '' === $draft_id ) {
			return array(
				'success' => false,
				'error'   => 'Draft ID is required.',
			);
		}

		return $this->service->confirm_update( $draft_id )->toLegacyArray();
	}
}
