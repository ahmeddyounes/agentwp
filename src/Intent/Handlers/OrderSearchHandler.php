<?php
/**
 * Handle order search intents.
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
 * Handles order search intents using the agentic loop.
 *
 * Uses the centrally-registered SearchOrdersTool for execution.
 */
#[HandlesIntent( Intent::ORDER_SEARCH )]
class OrderSearchHandler extends AbstractAgenticHandler {

	/**
	 * Initialize order search intent handler.
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
		parent::__construct( Intent::ORDER_SEARCH, $clientFactory, $toolRegistry, $toolDispatcher );
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
	 * Get the system prompt for order search.
	 *
	 * @return string
	 */
	protected function getSystemPrompt(): string {
		return 'You are an order search assistant. Find orders based on user criteria (date, status, customer).';
	}

	/**
	 * Get the tool names for order search.
	 *
	 * @return array<string>
	 */
	protected function getToolNames(): array {
		return array( 'search_orders' );
	}

	/**
	 * Get the default input for order search.
	 *
	 * @return string
	 */
	protected function getDefaultInput(): string {
		return 'Find orders';
	}
}
