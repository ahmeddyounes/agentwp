<?php
/**
 * Executable tool for retrieving sales reports.
 *
 * @package AgentWP\Intent\Tools
 */

namespace AgentWP\Intent\Tools;

use AgentWP\Contracts\AnalyticsServiceInterface;
use AgentWP\Contracts\ExecutableToolInterface;

/**
 * Retrieves sales report data for a specified period.
 *
 * Supports predefined periods (today, yesterday, this_week, etc.)
 * as well as custom date ranges.
 */
class GetSalesReportTool implements ExecutableToolInterface {

	/**
	 * @var AnalyticsServiceInterface
	 */
	private AnalyticsServiceInterface $service;

	/**
	 * Initialize the tool.
	 *
	 * @param AnalyticsServiceInterface $service Analytics service.
	 */
	public function __construct( AnalyticsServiceInterface $service ) {
		$this->service = $service;
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'get_sales_report';
	}

	/**
	 * Get sales report data.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Report data with sales metrics.
	 */
	public function execute( array $arguments ): array {
		$period     = isset( $arguments['period'] ) ? (string) $arguments['period'] : 'today';
		$start_date = isset( $arguments['start_date'] ) ? (string) $arguments['start_date'] : null;
		$end_date   = isset( $arguments['end_date'] ) ? (string) $arguments['end_date'] : null;
		$compare    = isset( $arguments['compare_previous'] ) ? (bool) $arguments['compare_previous'] : false;

		$result = $this->service->get_report_by_period( $period, $start_date, $end_date );

		if ( $result->isFailure() ) {
			return $result->toLegacyArray();
		}

		$data = $result->toLegacyArray();

		// Add comparison flag to response.
		$data['compare_previous'] = $compare;

		return $data;
	}
}
