<?php
/**
 * Handle analytics query intents.
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
 * Handles analytics query intents using the agentic loop.
 *
 * Uses the centrally-registered GetSalesReportTool for execution.
 */
#[HandlesIntent( Intent::ANALYTICS_QUERY )]
class AnalyticsQueryHandler extends AbstractAgenticHandler {

	/**
	 * Initialize analytics intent handler.
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
		parent::__construct( Intent::ANALYTICS_QUERY, $clientFactory, $toolRegistry, $toolDispatcher );
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
	 * Get the system prompt for analytics.
	 *
	 * @return string
	 */
	protected function getSystemPrompt(): string {
		return 'You are an expert data analyst. Use get_sales_report to fetch data, then summarize the key metrics (Sales, Orders, Refunds) for the user. Highlight trends if applicable.';
	}

	/**
	 * Get the tool names for analytics.
	 *
	 * @return array<string>
	 */
	protected function getToolNames(): array {
		return array( 'get_sales_report' );
	}

	/**
	 * Get the default input for analytics queries.
	 *
	 * @return string
	 */
	protected function getDefaultInput(): string {
		return 'Show sales analytics';
	}
}
