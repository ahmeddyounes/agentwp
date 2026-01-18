<?php
/**
 * Handle order refund intents.
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
 * Handles order refund intents using the agentic loop.
 *
 * Uses the centrally-registered PrepareRefundTool and ConfirmRefundTool for execution.
 */
#[HandlesIntent( Intent::ORDER_REFUND )]
class OrderRefundHandler extends AbstractAgenticHandler {

	/**
	 * Initialize order refund intent handler.
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
		parent::__construct( Intent::ORDER_REFUND, $clientFactory, $toolRegistry, $toolDispatcher );
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
	 * Get the system prompt for refund handling.
	 *
	 * @return string
	 */
	protected function getSystemPrompt(): string {
		return 'You are an expert WooCommerce assistant. You can help process refunds. Always verify order details before confirming.';
	}

	/**
	 * Get the tool names for refund handling.
	 *
	 * @return array<string>
	 */
	protected function getToolNames(): array {
		return array( 'prepare_refund', 'confirm_refund' );
	}

	/**
	 * Get the default input for refund operations.
	 *
	 * @return string
	 */
	protected function getDefaultInput(): string {
		return 'Process a refund';
	}
}
