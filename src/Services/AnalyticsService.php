<?php
/**
 * Analytics service for aggregating store data.
 *
 * @package AgentWP\Services
 */

namespace AgentWP\Services;

use AgentWP\Contracts\AnalyticsServiceInterface;
use DateTime;
use DateTimeZone;

class AnalyticsService implements AnalyticsServiceInterface {

	/**
	 * Get analytics data for a specific period.
	 *
	 * @param string $period Period identifier ('7d', '30d', '90d').
	 * @return array Analytics data matching frontend expectation.
	 */
	public function get_stats( string $period = '7d' ): array {
		$days = $this->resolve_days( $period );
		$now  = new DateTime( 'now', new DateTimeZone( 'UTC' ) );

		$current_end   = $now->format( 'Y-m-d 23:59:59' );
		$current_start = $now->modify( "-{$days} days" )->format( 'Y-m-d 00:00:00' );

		// Previous period (same length, immediately before).
		$prev_end_dt   = new DateTime( $current_start, new DateTimeZone( 'UTC' ) );
		$prev_end      = $prev_end_dt->modify( '-1 second' )->format( 'Y-m-d 23:59:59' );
		$prev_start    = $prev_end_dt->modify( "-{$days} days" )->modify( '+1 second' )->format( 'Y-m-d 00:00:00' );

		$current_data = $this->query_period( $current_start, $current_end );
		$previous_data = $this->query_period( $prev_start, $prev_end );

		return array(
			'label'    => "Last {$days} days",
			'labels'   => $this->generate_date_labels( $days ),
			'current'  => $this->map_daily_totals( $current_data['daily'], $days ),
			'previous' => $this->map_daily_totals( $previous_data['daily'], $days ),
			'metrics'  => array(
				'labels'   => array( 'Revenue', 'Orders', 'Avg Order', 'Refunds' ),
				'current'  => array(
					$current_data['total_sales'],
					$current_data['order_count'],
					$current_data['order_count'] > 0 ? round( $current_data['total_sales'] / $current_data['order_count'], 2 ) : 0,
					$current_data['total_refunds'],
				),
				'previous' => array(
					$previous_data['total_sales'],
					$previous_data['order_count'],
					$previous_data['order_count'] > 0 ? round( $previous_data['total_sales'] / $previous_data['order_count'], 2 ) : 0,
					$previous_data['total_refunds'],
				),
			),
			// Categories are harder to aggregate efficiently without complex queries. 
			// Sending empty/mock for now to avoid massive performance hit on large stores.
			'categories' => array(
				'labels' => array( 'General' ),
				'values' => array( $current_data['total_sales'] ),
			),
		);
	}

	/**
	 * Get raw report data.
	 *
	 * @param string $start Start Y-m-d.
	 * @param string $end   End Y-m-d.
	 * @return array
	 */
	public function get_report( string $start, string $end ): array {
		// Ensure time is included
		if ( strlen( $start ) === 10 ) {
			$start .= ' 00:00:00';
		}
		if ( strlen( $end ) === 10 ) {
			$end .= ' 23:59:59';
		}
		
		return $this->query_period( $start, $end );
	}

	/**
	 * Resolve period string to days.
	 *
	 * @param string $period Period string.
	 * @return int Number of days.
	 */
	private function resolve_days( $period ) {
		switch ( $period ) {
			case '30d':
				return 30;
			case '90d':
				return 90;
			case '7d':
			default:
				return 7;
		}
	}

	/**
	 * Generate date labels (e.g., "Mon", "Tue" or "Jan 1").
	 *
	 * @param int $days Number of days.
	 * @return array
	 */
	private function generate_date_labels( $days ) {
		$labels = array();
		$now    = new DateTime( 'now', new DateTimeZone( 'UTC' ) );
		
		// Go back $days and step forward
		$start = clone $now;
		$start->modify( "-" . ($days - 1) . " days" );

		for ( $i = 0; $i < $days; $i++ ) {
			if ( $days <= 7 ) {
				$labels[] = $start->format( 'D' ); // Mon, Tue
			} else {
				$labels[] = $start->format( 'M j' ); // Jan 1
			}
			$start->modify( '+1 day' );
		}
		return $labels;
	}

	/**
	 * Query data for a date range.
	 *
	 * @param string $start Start date (Y-m-d H:i:s).
	 * @param string $end   End date (Y-m-d H:i:s).
	 * @return array
	 */
	protected function query_period( $start, $end ) {
		$args = array(
			'date_created' => $start . '...' . $end,
			'limit'        => 500,
			'type'         => 'shop_order',
			'status'       => array( 'wc-completed', 'wc-processing', 'wc-on-hold' ),
			'return'       => 'ids',
		);

		$order_ids = wc_get_orders( $args );

		if ( empty( $order_ids ) ) {
			return array(
				'daily'         => array(),
				'total_sales'   => 0,
				'order_count'   => 0,
				'total_refunds' => 0,
			);
		}

		// Batch load orders to avoid N+1 query.
		$orders = wc_get_orders(
			array(
				'include' => $order_ids,
				'limit'   => count( $order_ids ),
				'orderby' => 'none',
			)
		);

		$daily_totals  = array();
		$total_sales   = 0.0;
		$order_count   = count( $order_ids );
		$total_refunds = 0.0;

		foreach ( $orders as $order ) {
			if ( ! $order ) {
				continue;
			}

			// Null safety: check if date is available.
			$date_created = $order->get_date_created();
			if ( null === $date_created ) {
				continue;
			}

			$date  = $date_created->format( 'Y-m-d' );
			$total = (float) $order->get_total();

			if ( ! isset( $daily_totals[ $date ] ) ) {
				$daily_totals[ $date ] = 0.0;
			}
			$daily_totals[ $date ] += $total;
			$total_sales           += $total;

			$total_refunds += $order->get_total_refunded();
		}

		return array(
			'daily'         => $daily_totals,
			'total_sales'   => $total_sales,
			'order_count'   => $order_count,
			'total_refunds' => $total_refunds,
		);
	}

	/**
	 * Map daily totals to the labels array.
	 *
	 * @param array $daily_data Key-value date->total.
	 * @param int   $days       Number of days.
	 * @return array
	 */
	private function map_daily_totals( $daily_data, $days ) {
		$data = array();
		$now  = new DateTime( 'now', new DateTimeZone( 'UTC' ) );

		$start = clone $now;
		$start->modify( "-" . ($days - 1) . " days" );

		for ( $i = 0; $i < $days; $i++ ) {
			$date_key = $start->format( 'Y-m-d' );
			$data[]   = isset( $daily_data[ $date_key ] ) ? $daily_data[ $date_key ] : 0;
			$start->modify( '+1 day' );
		}
		return $data;
	}

	/**
	 * Get report data by period identifier.
	 *
	 * @param string      $period     Period identifier.
	 * @param string|null $start_date Custom start date (Y-m-d) for 'custom' period.
	 * @param string|null $end_date   Custom end date (Y-m-d) for 'custom' period.
	 * @return array Report data.
	 */
	public function get_report_by_period( string $period, ?string $start_date = null, ?string $end_date = null ): array {
		$tz = $this->get_timezone();

		$now   = new DateTime( 'now', $tz );
		$start = clone $now;
		$end   = clone $now;

		switch ( $period ) {
			case 'today':
				$start->setTime( 0, 0, 0 );
				$end->setTime( 23, 59, 59 );
				break;
			case 'yesterday':
				$start->modify( '-1 day' )->setTime( 0, 0, 0 );
				$end->modify( '-1 day' )->setTime( 23, 59, 59 );
				break;
			case 'this_week':
				$start->modify( 'monday this week' )->setTime( 0, 0, 0 );
				$end->setTime( 23, 59, 59 );
				break;
			case 'last_week':
				$start->modify( 'monday last week' )->setTime( 0, 0, 0 );
				$end->modify( 'sunday last week' )->setTime( 23, 59, 59 );
				break;
			case 'this_month':
				$start->modify( 'first day of this month' )->setTime( 0, 0, 0 );
				$end->modify( 'last day of this month' )->setTime( 23, 59, 59 );
				break;
			case 'last_month':
				$start->modify( 'first day of last month' )->setTime( 0, 0, 0 );
				$end->modify( 'last day of last month' )->setTime( 23, 59, 59 );
				break;
			case 'custom':
				try {
					if ( ! empty( $start_date ) ) {
						$start = new DateTime( $start_date, $tz );
						$start->setTime( 0, 0, 0 );
					}
					if ( ! empty( $end_date ) ) {
						$end = new DateTime( $end_date, $tz );
						$end->setTime( 23, 59, 59 );
					}
				} catch ( \Exception $e ) {
					// Fallback to today if date parsing fails.
					$start->setTime( 0, 0, 0 );
					$end->setTime( 23, 59, 59 );
				}
				break;
			default:
				// Default to today.
				$start->setTime( 0, 0, 0 );
				$end->setTime( 23, 59, 59 );
				break;
		}

		$report = $this->get_report( $start->format( 'Y-m-d H:i:s' ), $end->format( 'Y-m-d H:i:s' ) );

		return array(
			'period'      => $period,
			'start'       => $start->format( 'Y-m-d' ),
			'end'         => $end->format( 'Y-m-d' ),
			'total_sales' => $report['total_sales'],
			'orders'      => $report['order_count'],
			'refunds'     => $report['total_refunds'],
		);
	}

	/**
	 * Get the timezone to use for date calculations.
	 *
	 * @return DateTimeZone
	 */
	private function get_timezone(): DateTimeZone {
		if ( function_exists( 'wp_timezone' ) ) {
			return wp_timezone();
		}
		return new DateTimeZone( 'UTC' );
	}
}
