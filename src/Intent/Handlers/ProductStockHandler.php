<?php
/**
 * Handle product stock intents.
 *
 * @package AgentWP
 */

namespace AgentWP\Intent\Handlers;

use AgentWP\AI\Functions\PrepareStockUpdate;
use AgentWP\AI\Functions\ConfirmStockUpdate;
use AgentWP\AI\Functions\SearchProduct;
use AgentWP\Contracts\AIClientFactoryInterface;
use AgentWP\Contracts\ProductStockServiceInterface;
use AgentWP\Intent\Intent;

/**
 * Handles product stock intents using the agentic loop.
 */
class ProductStockHandler extends AbstractAgenticHandler {

	/**
	 * @var ProductStockServiceInterface
	 */
	private ProductStockServiceInterface $service;

	/**
	 * Initialize product stock intent handler.
	 *
	 * @param ProductStockServiceInterface $service       Stock service.
	 * @param AIClientFactoryInterface     $clientFactory AI client factory.
	 */
	public function __construct(
		ProductStockServiceInterface $service,
		AIClientFactoryInterface $clientFactory
	) {
		parent::__construct( Intent::PRODUCT_STOCK, $clientFactory );
		$this->service = $service;
	}

	/**
	 * Get the system prompt for stock handling.
	 *
	 * @return string
	 */
	protected function getSystemPrompt(): string {
		return 'You are an expert inventory manager. Help the user check stock or update it. Always search for products first to get IDs.';
	}

	/**
	 * Get the tools available for stock handling.
	 *
	 * @return array
	 */
	protected function getTools(): array {
		return array( new SearchProduct(), new PrepareStockUpdate(), new ConfirmStockUpdate() );
	}

	/**
	 * Get the default input for stock operations.
	 *
	 * @return string
	 */
	protected function getDefaultInput(): string {
		return 'Check stock levels';
	}

	/**
	 * Execute a named tool with arguments.
	 *
	 * @param string $name      Tool name.
	 * @param array  $arguments Tool arguments.
	 * @return mixed Tool execution result.
	 */
	public function execute_tool( string $name, array $arguments ) {
		switch ( $name ) {
			case 'search_product':
				$query = isset( $arguments['query'] ) ? (string) $arguments['query'] : '';
				return $this->service->search_products( $query );

			case 'prepare_stock_update':
				$product_id = isset( $arguments['product_id'] ) ? (int) $arguments['product_id'] : 0;
				$quantity   = isset( $arguments['quantity'] ) ? (int) $arguments['quantity'] : 0;
				$operation  = isset( $arguments['operation'] ) ? (string) $arguments['operation'] : 'set';
				return $this->service->prepare_update( $product_id, $quantity, $operation );

			case 'confirm_stock_update':
				$draft_id = isset( $arguments['draft_id'] ) ? (string) $arguments['draft_id'] : '';
				return $this->service->confirm_update( $draft_id );

			default:
				return array( 'error' => "Unknown tool: {$name}" );
		}
	}
}
