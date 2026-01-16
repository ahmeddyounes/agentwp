<?php
/**
 * Handle analytics query intents.
 *
 * @package AgentWP
 */

namespace AgentWP\Intent\Handlers;

use AgentWP\AI\Functions\GetSalesReport;
use AgentWP\Contracts\AIClientFactoryInterface;
use AgentWP\Contracts\AnalyticsServiceInterface;
use AgentWP\Intent\Intent;

/**
 * Handles analytics query intents using the agentic loop.
 */
class AnalyticsQueryHandler extends AbstractAgenticHandler {

	/**
	 * @var AnalyticsServiceInterface
	 */
	private AnalyticsServiceInterface $service;

	/**
	 * Initialize analytics intent handler.
	 *
	 * @param AnalyticsServiceInterface $service       Analytics service.
	 * @param AIClientFactoryInterface  $clientFactory AI client factory.
	 */
	public function __construct(
		AnalyticsServiceInterface $service,
		AIClientFactoryInterface $clientFactory
	) {
		parent::__construct( Intent::ANALYTICS_QUERY, $clientFactory );
		$this->service = $service;
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
	 * Get the tools available for analytics.
	 *
	 * @return array
	 */
	protected function getTools(): array {
		return array( new GetSalesReport() );
	}

	/**
	 * Get the default input for analytics queries.
	 *
	 * @return string
	 */
	protected function getDefaultInput(): string {
		return 'Show sales analytics';
	}

	/**
	 * Execute a named tool with arguments.
	 *
	 * @param string $name      Tool name.
	 * @param array  $arguments Tool arguments.
	 * @return mixed Tool execution result.
	 */
	public function execute_tool( string $name, array $arguments ) {
		if ( 'get_sales_report' === $name ) {
			$period     = isset( $arguments['period'] ) ? (string) $arguments['period'] : 'today';
			$start_date = isset( $arguments['start_date'] ) ? (string) $arguments['start_date'] : null;
			$end_date   = isset( $arguments['end_date'] ) ? (string) $arguments['end_date'] : null;

			return $this->service->get_report_by_period( $period, $start_date, $end_date );
		}

		return array( 'error' => "Unknown tool: {$name}" );
	}
}
