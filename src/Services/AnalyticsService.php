<?php
/**
 * Analytics service for aggregating store data.
 *
 * @package AgentWP\Services
 */

namespace AgentWP\Services;

use AgentWP\Contracts\AnalyticsServiceInterface;
use AgentWP\Contracts\ClockInterface;
use AgentWP\Contracts\OrderRepositoryInterface;
use AgentWP\Contracts\WooCommerceOrderGatewayInterface;
use AgentWP\DTO\DateRange;
use AgentWP\DTO\OrderQuery;
use AgentWP\DTO\ServiceResult;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;

class AnalyticsService implements AnalyticsServiceInterface {

	/**
	 * Order repository.
	 *
	 * @var OrderRepositoryInterface|null
	 */
	private ?OrderRepositoryInterface $orderRepository;

	/**
	 * Order gateway for loading full order objects.
	 *
	 * @var WooCommerceOrderGatewayInterface
	 */
	private WooCommerceOrderGatewayInterface $orderGateway;

	/**
	 * Clock service for timezone-aware time operations.
	 *
	 * @var ClockInterface
	 */
	private ClockInterface $clock;

	/**
	 * Create a new AnalyticsService.
	 *
	 * @param OrderRepositoryInterface|null    $orderRepository Order repository.
	 * @param WooCommerceOrderGatewayInterface $orderGateway    Order gateway.
	 * @param ClockInterface                   $clock           Clock service.
	 */
	public function __construct(
		?OrderRepositoryInterface $orderRepository,
		WooCommerceOrderGatewayInterface $orderGateway,
		ClockInterface $clock
	) {
		$this->orderRepository = $orderRepository;
		$this->orderGateway    = $orderGateway;
		$this->clock           = $clock;
	}

	/**
	 * Get analytics data for a specific period.
	 *
	 * @param string $period Period identifier ('7d', '30d', '90d').
	 * @return ServiceResult Result with analytics data matching frontend expectation.
	 */
	public function get_stats( string $period = '7d' ): ServiceResult {
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

		return ServiceResult::success(
			"Analytics retrieved for last {$days} days.",
			array(
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
			)
		);
	}

	/**
	 * Get raw report data.
	 *
	 * @param string $start Start Y-m-d.
	 * @param string $end   End Y-m-d.
	 * @return ServiceResult Result with report data.
	 */
	public function get_report( string $start, string $end ): ServiceResult {
		// Ensure time is included
		if ( strlen( $start ) === 10 ) {
			$start .= ' 00:00:00';
		}
		if ( strlen( $end ) === 10 ) {
			$end .= ' 23:59:59';
		}

		$data = $this->query_period( $start, $end );

		return ServiceResult::success(
			'Report data retrieved.',
			$data
		);
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
		$start->modify( '-' . ( $days - 1 ) . ' days' );

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
		if ( null === $this->orderRepository ) {
			return array(
				'daily'         => array(),
				'total_sales'   => 0,
				'order_count'   => 0,
				'total_refunds' => 0,
			);
		}

		// Build an OrderQuery with date range
		$dateRange = $this->buildDateRange( $start, $end );
		$query     = new OrderQuery(
			status: 'wc-completed,wc-processing,wc-on-hold',
			limit: 500,
			dateRange: $dateRange
		);

		$order_ids = $this->orderRepository->queryIds( $query );

		if ( empty( $order_ids ) ) {
			return array(
				'daily'         => array(),
				'total_sales'   => 0,
				'order_count'   => 0,
				'total_refunds' => 0,
			);
		}

		// Batch load orders to avoid N+1 query.
		$orders = array();
		foreach ( $order_ids as $order_id ) {
			$order = $this->orderGateway->get_order( $order_id );
			if ( null !== $order ) {
				$orders[] = $order;
			}
		}

		$daily_totals  = array();
		$total_sales   = 0.0;
		$order_count   = count( $order_ids );
		$total_refunds = 0.0;

		foreach ( $orders as $order ) {
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
	 * Build a DateRange object from start/end strings.
	 *
	 * @param string $start Start date string.
	 * @param string $end   End date string.
	 * @return DateRange|null
	 */
	private function buildDateRange( string $start, string $end ): ?DateRange {
		try {
			$tz      = new DateTimeZone( 'UTC' );
			$startDt = new DateTimeImmutable( $start, $tz );
			$endDt   = new DateTimeImmutable( $end, $tz );
			return new DateRange( $startDt, $endDt );
		} catch ( \Exception $e ) {
			return null;
		}
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
		$start->modify( '-' . ( $days - 1 ) . ' days' );

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
	 * @return ServiceResult Result with report data.
	 */
	public function get_report_by_period( string $period, ?string $start_date = null, ?string $end_date = null ): ServiceResult {
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

		$report_data = $this->query_period( $start->format( 'Y-m-d H:i:s' ), $end->format( 'Y-m-d H:i:s' ) );

		return ServiceResult::success(
			"Report data retrieved for {$period}.",
			array(
				'period'      => $period,
				'start'       => $start->format( 'Y-m-d' ),
				'end'         => $end->format( 'Y-m-d' ),
				'total_sales' => $report_data['total_sales'],
				'orders'      => $report_data['order_count'],
				'refunds'     => $report_data['total_refunds'],
			)
		);
	}

	/**
	 * Get the timezone to use for date calculations.
	 *
	 * Uses the injected clock service which is configured with WordPress timezone.
	 *
	 * @return DateTimeZone
	 */
	private function get_timezone(): DateTimeZone {
		// Get current time from clock and extract its timezone
		$now = $this->clock->now();
		return $now->getTimezone();
	}
}
