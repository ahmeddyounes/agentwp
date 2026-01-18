<?php
/**
 * Handle product stock intents.
 *
 * @package AgentWP
 */

namespace AgentWP\Intent\Handlers;

use AgentWP\Contracts\AIClientFactoryInterface;
use AgentWP\Contracts\ToolDispatcherInterface;
use AgentWP\Contracts\ToolRegistryInterface;
use AgentWP\Intent\Attributes\HandlesIntent;
use AgentWP\Intent\Intent;

/**
 * Handles product stock intents using the agentic loop.
 *
 * Uses the centrally-registered SearchProductTool, PrepareStockUpdateTool,
 * and ConfirmStockUpdateTool for execution.
 */
#[HandlesIntent( Intent::PRODUCT_STOCK )]
class ProductStockHandler extends AbstractAgenticHandler {

	/**
	 * Initialize product stock intent handler.
	 *
	 * @param AIClientFactoryInterface $clientFactory  AI client factory.
	 * @param ToolRegistryInterface    $toolRegistry   Tool registry.
	 * @param ToolDispatcherInterface  $toolDispatcher Tool dispatcher with pre-registered tools.
	 */
	public function __construct(
		AIClientFactoryInterface $clientFactory,
		ToolRegistryInterface $toolRegistry,
		ToolDispatcherInterface $toolDispatcher
	) {
		parent::__construct( Intent::PRODUCT_STOCK, $clientFactory, $toolRegistry, $toolDispatcher );
	}

	/**
	 * Register tool executors with the dispatcher.
	 *
	 * No-op: Tools are pre-registered via the container.
	 *
	 * @param ToolDispatcherInterface $dispatcher The tool dispatcher.
	 * @return void
	 */
	protected function registerToolExecutors( ToolDispatcherInterface $dispatcher ): void {
		unset( $dispatcher );

		// Tools are pre-registered via IntentServiceProvider::registerToolDispatcher().
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
