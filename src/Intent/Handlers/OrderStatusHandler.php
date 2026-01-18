<?php
/**
 * Handle order status intents.
 *
 * @package AgentWP\Intent\Handlers
 */

namespace AgentWP\Intent\Handlers;

use AgentWP\Contracts\AIClientFactoryInterface;
use AgentWP\Contracts\ToolDispatcherInterface;
use AgentWP\Contracts\ToolRegistryInterface;
use AgentWP\Intent\Attributes\HandlesIntent;
use AgentWP\Intent\Intent;

/**
 * Handles order status intents using the agentic loop.
 *
 * Uses the centrally-registered PrepareStatusUpdateTool, PrepareBulkStatusUpdateTool,
 * and ConfirmStatusUpdateTool for execution.
 */
#[HandlesIntent( Intent::ORDER_STATUS )]
class OrderStatusHandler extends AbstractAgenticHandler {

	/**
	 * Initialize order status intent handler.
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
		parent::__construct( Intent::ORDER_STATUS, $clientFactory, $toolRegistry, $toolDispatcher );
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
		// Tools are pre-registered via IntentServiceProvider::registerToolDispatcher().
	}

	/**
	 * Get the system prompt for status handling.
	 *
	 * @return string
	 */
	protected function getSystemPrompt(): string {
		return 'You are an expert WooCommerce assistant. You can check and update order statuses (single or bulk). Always prepare updates first.';
	}

	/**
	 * Get the tool names for status handling.
	 *
	 * @return array<string>
	 */
	protected function getToolNames(): array {
		return array( 'prepare_status_update', 'prepare_bulk_status_update', 'confirm_status_update' );
	}

	/**
	 * Get the default input for status operations.
	 *
	 * @return string
	 */
	protected function getDefaultInput(): string {
		return 'Check order status';
	}
}
