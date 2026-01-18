<?php
/**
 * Handle product stock intents.
 *
 * @package AgentWP
 */

namespace AgentWP\Intent\Handlers;

use AgentWP\Contracts\AIClientFactoryInterface;
use AgentWP\Contracts\ProductStockServiceInterface;
use AgentWP\Contracts\ToolDispatcherInterface;
use AgentWP\Contracts\ToolRegistryInterface;
use AgentWP\Intent\Attributes\HandlesIntent;
use AgentWP\Intent\Intent;

/**
 * Handles product stock intents using the agentic loop.
 */
#[HandlesIntent( Intent::PRODUCT_STOCK )]
class ProductStockHandler extends AbstractAgenticHandler {

	/**
	 * @var ProductStockServiceInterface
	 */
	private ProductStockServiceInterface $service;

	/**
	 * Initialize product stock intent handler.
	 *
	 * @param ProductStockServiceInterface $service        Stock service.
	 * @param AIClientFactoryInterface     $clientFactory  AI client factory.
	 * @param ToolRegistryInterface        $toolRegistry   Tool registry.
	 * @param ToolDispatcherInterface|null $toolDispatcher Tool dispatcher (optional).
	 */
	public function __construct(
		ProductStockServiceInterface $service,
		AIClientFactoryInterface $clientFactory,
		ToolRegistryInterface $toolRegistry,
		?ToolDispatcherInterface $toolDispatcher = null
	) {
		$this->service = $service;
		parent::__construct( Intent::PRODUCT_STOCK, $clientFactory, $toolRegistry, $toolDispatcher );
	}

	/**
	 * Register tool executors with the dispatcher.
	 *
	 * @param ToolDispatcherInterface $dispatcher The tool dispatcher.
	 * @return void
	 */
	protected function registerToolExecutors( ToolDispatcherInterface $dispatcher ): void {
		$dispatcher->registerMany(
			array(
				'search_product'       => function ( array $args ): array {
					$query = isset( $args['query'] ) ? (string) $args['query'] : '';
					return $this->service->search_products( $query );
				},
				'prepare_stock_update' => function ( array $args ): array {
					$product_id = isset( $args['product_id'] ) ? (int) $args['product_id'] : 0;
					$quantity   = isset( $args['quantity'] ) ? (int) $args['quantity'] : 0;
					$operation  = isset( $args['operation'] ) ? (string) $args['operation'] : 'set';
					return $this->service->prepare_update( $product_id, $quantity, $operation )->toLegacyArray();
				},
				'confirm_stock_update' => function ( array $args ): array {
					$draft_id = isset( $args['draft_id'] ) ? (string) $args['draft_id'] : '';
					return $this->service->confirm_update( $draft_id )->toLegacyArray();
				},
			)
		);
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
	 * Get the tool names for stock handling.
	 *
	 * @return array<string>
	 */
	protected function getToolNames(): array {
		return array( 'search_product', 'prepare_stock_update', 'confirm_stock_update' );
	}

	/**
	 * Get the default input for stock operations.
	 *
	 * @return string
	 */
	protected function getDefaultInput(): string {
		return 'Check stock levels';
	}
}
