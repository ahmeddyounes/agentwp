<?php
/**
 * Analytics service interface.
 *
 * @package AgentWP\Contracts
 */

namespace AgentWP\Contracts;

use AgentWP\DTO\ServiceResult;

/**
 * Interface for analytics operations.
 */
interface AnalyticsServiceInterface {

	/**
	 * Get analytics data for a specific period.
	 *
	 * @param string $period Period identifier ('7d', '30d', '90d').
	 * @return ServiceResult Result with analytics data including labels, metrics, and chart data.
	 */
	public function get_stats( string $period = '7d' ): ServiceResult;

	/**
	 * Get raw report data for a date range.
	 *
	 * @param string $start Start date (Y-m-d or Y-m-d H:i:s).
	 * @param string $end   End date (Y-m-d or Y-m-d H:i:s).
	 * @return ServiceResult Result with report data including daily totals, total_sales, order_count, total_refunds.
	 */
	public function get_report( string $start, string $end ): ServiceResult;

	/**
	 * Get report data by period identifier.
	 *
	 * @param string      $period     Period identifier ('today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'custom').
	 * @param string|null $start_date Custom start date (Y-m-d) for 'custom' period.
	 * @param string|null $end_date   Custom end date (Y-m-d) for 'custom' period.
	 * @return ServiceResult Result with report data including period, start, end, total_sales, orders, refunds.
	 */
	public function get_report_by_period( string $period, ?string $start_date = null, ?string $end_date = null ): ServiceResult;
}
