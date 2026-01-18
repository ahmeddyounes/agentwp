<?php
/**
 * Handle customer lookup intents.
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
 * Handles customer lookup intents using the agentic loop.
 *
 * Uses the centrally-registered GetCustomerProfileTool for execution.
 */
#[HandlesIntent( Intent::CUSTOMER_LOOKUP )]
class CustomerLookupHandler extends AbstractAgenticHandler {

	/**
	 * Initialize customer lookup intent handler.
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
		parent::__construct( Intent::CUSTOMER_LOOKUP, $clientFactory, $toolRegistry, $toolDispatcher );
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
	 * Get the system prompt for customer lookup.
	 *
	 * @return string
	 */
	protected function getSystemPrompt(): string {
		return 'You are a customer success manager. Look up customer profiles and summarize key metrics (LTV, last order, health).';
	}

	/**
	 * Get the tool names for customer lookup.
	 *
	 * @return array<string>
	 */
	protected function getToolNames(): array {
		return array( 'get_customer_profile' );
	}

	/**
	 * Get the default input for customer lookup.
	 *
	 * @return string
	 */
	protected function getDefaultInput(): string {
		return 'Look up customer';
	}
}
