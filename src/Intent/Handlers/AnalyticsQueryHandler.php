<?php
/**
 * Handle analytics query intents.
 *
 * @package AgentWP
 */

namespace AgentWP\Intent\Handlers;

use AgentWP\Contracts\AIClientFactoryInterface;
use AgentWP\Contracts\AnalyticsServiceInterface;
use AgentWP\Contracts\ToolDispatcherInterface;
use AgentWP\Contracts\ToolRegistryInterface;
use AgentWP\Intent\Attributes\HandlesIntent;
use AgentWP\Intent\Intent;

/**
 * Handles analytics query intents using the agentic loop.
 */
#[HandlesIntent( Intent::ANALYTICS_QUERY )]
class AnalyticsQueryHandler extends AbstractAgenticHandler {

	/**
	 * @var AnalyticsServiceInterface
	 */
	private AnalyticsServiceInterface $service;

	/**
	 * Initialize analytics intent handler.
	 *
	 * @param AnalyticsServiceInterface    $service        Analytics service.
	 * @param AIClientFactoryInterface     $clientFactory  AI client factory.
	 * @param ToolRegistryInterface        $toolRegistry   Tool registry.
	 * @param ToolDispatcherInterface|null $toolDispatcher Tool dispatcher (optional).
	 */
	public function __construct(
		AnalyticsServiceInterface $service,
		AIClientFactoryInterface $clientFactory,
		ToolRegistryInterface $toolRegistry,
		?ToolDispatcherInterface $toolDispatcher = null
	) {
		$this->service = $service;
		parent::__construct( Intent::ANALYTICS_QUERY, $clientFactory, $toolRegistry, $toolDispatcher );
	}

	/**
	 * Register tool executors with the dispatcher.
	 *
	 * @param ToolDispatcherInterface $dispatcher The tool dispatcher.
	 * @return void
	 */
	protected function registerToolExecutors( ToolDispatcherInterface $dispatcher ): void {
		$dispatcher->register(
			'get_sales_report',
			function ( array $args ): array {
				$period     = isset( $args['period'] ) ? (string) $args['period'] : 'today';
				$start_date = isset( $args['start_date'] ) ? (string) $args['start_date'] : null;
				$end_date   = isset( $args['end_date'] ) ? (string) $args['end_date'] : null;

				$result = $this->service->get_report_by_period( $period, $start_date, $end_date );

				if ( $result->isFailure() ) {
					return array( 'error' => $result->message );
				}

				return $result->data;
			}
		);
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
