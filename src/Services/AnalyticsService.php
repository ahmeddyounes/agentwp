<?php
/**
 * Analytics service for aggregating store data.
 *
 * @package AgentWP\Services
 */

namespace AgentWP\Services;

use DateTime;
use DateTimeZone;

class AnalyticsService {

	/**
	 * Get analytics data for a specific period.
	 *
	 * @param string $period Period identifier ('7d', '30d', '90d').
	 * @return array Analytics data matching frontend expectation.
	 */
	public function get_stats( $period = '7d' ) {
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
	public function get_report( $start, $end ) {
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
		// Use wc_get_orders. It handles HPOS transparently.
		// Performance Warning: This fetches IDs then objects. On huge stores this is bad.
		// Optimized approach: Use wc_get_orders with 'return' => 'ids', then maybe direct DB for totals if needed.
		// But for reliability, we stick to WC APIs.
		
		$args = array(
			'date_created' => $start . '...' . $end,
			'limit'        => 500, // Safety limit
			'type'         => 'shop_order',
			'status'       => array( 'wc-completed', 'wc-processing', 'wc-on-hold' ),
			'return'       => 'ids',
		);

		$order_ids = wc_get_orders( $args );
		
		$daily_totals  = array();
		$total_sales   = 0;
		$order_count   = count( $order_ids );
		$total_refunds = 0; // Would need separate query for refunds usually.

		// Batch processing to avoid memory issues?
		// For now, iterate. If $order_ids is huge, this will timeout. 
		// A proper implementation uses SQL aggregations.
		
		global $wpdb;
		
		if ( empty( $order_ids ) ) {
			return array(
				'daily' => array(),
				'total_sales' => 0,
				'order_count' => 0,
				'total_refunds' => 0,
			);
		}
		
		// Attempt optimized retrieval if possible.
		// Since we can't easily rely on HPOS tables being present, and posts table is slow.
		// But we have the IDs.
		
		foreach ( $order_ids as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order ) continue;

			$date = $order->get_date_created()->format( 'Y-m-d' );
			$total = (float) $order->get_total();
			
			if ( ! isset( $daily_totals[ $date ] ) ) {
				$daily_totals[ $date ] = 0;
			}
			$daily_totals[ $date ] += $total;
			$total_sales += $total;
			
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
}
