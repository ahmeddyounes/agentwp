<?php
/**
 * Executable tool for preparing stock updates.
 *
 * @package AgentWP\Intent\Tools
 */

namespace AgentWP\Intent\Tools;

use AgentWP\Contracts\ExecutableToolInterface;
use AgentWP\Contracts\ProductStockServiceInterface;

/**
 * Prepares a draft stock update for a product without executing it.
 *
 * Uses two-phase execution: prepare creates a draft, confirm executes it.
 */
class PrepareStockUpdateTool implements ExecutableToolInterface {

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
		return 'prepare_stock_update';
	}

	/**
	 * Prepare a stock update draft.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Preparation result with draft_id on success.
	 */
	public function execute( array $arguments ): array {
		$product_id = isset( $arguments['product_id'] ) ? (int) $arguments['product_id'] : 0;
		$quantity   = isset( $arguments['quantity'] ) ? (int) $arguments['quantity'] : 0;
		$operation  = isset( $arguments['operation'] ) ? (string) $arguments['operation'] : 'set';

		return $this->service->prepare_update( $product_id, $quantity, $operation )->toLegacyArray();
	}
}
