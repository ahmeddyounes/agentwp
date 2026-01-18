<?php
/**
 * Analytics Chart DTO.
 *
 * @package AgentWP\DTO
 */

namespace AgentWP\DTO;

/**
 * Immutable analytics chart value object.
 *
 * Represents analytics data for a time period with:
 * - Daily trend data (current and previous period)
 * - Summary metrics (revenue, orders, avg order, refunds)
 * - Category breakdown
 */
final class AnalyticsChartDTO {

	/**
	 * Create a new AnalyticsChartDTO.
	 *
	 * @param string                     $label           Period label (e.g., "Last 7 days").
	 * @param array<string>              $labels          Date labels for chart x-axis.
	 * @param array<float>               $current         Daily totals for current period.
	 * @param array<float>               $previous        Daily totals for previous period.
	 * @param AnalyticsMetricsDTO        $metrics         Summary metrics.
	 * @param AnalyticsCategoryBreakdownDTO $categories   Category breakdown.
	 */
	public function __construct(
		public readonly string $label,
		public readonly array $labels,
		public readonly array $current,
		public readonly array $previous,
		public readonly AnalyticsMetricsDTO $metrics,
		public readonly AnalyticsCategoryBreakdownDTO $categories,
	) {
	}

	/**
	 * Create from raw analytics data.
	 *
	 * @param array $data Raw analytics data from AnalyticsService.
	 * @return self
	 */
	public static function fromArray( array $data ): self {
		return new self(
			label: isset( $data['label'] ) ? (string) $data['label'] : '',
			labels: isset( $data['labels'] ) && is_array( $data['labels'] ) ? array_map( 'strval', $data['labels'] ) : array(),
			current: isset( $data['current'] ) && is_array( $data['current'] ) ? array_map( 'floatval', $data['current'] ) : array(),
			previous: isset( $data['previous'] ) && is_array( $data['previous'] ) ? array_map( 'floatval', $data['previous'] ) : array(),
			metrics: AnalyticsMetricsDTO::fromArray( isset( $data['metrics'] ) && is_array( $data['metrics'] ) ? $data['metrics'] : array() ),
			categories: AnalyticsCategoryBreakdownDTO::fromArray( isset( $data['categories'] ) && is_array( $data['categories'] ) ? $data['categories'] : array() ),
		);
	}

	/**
	 * Convert to array format suitable for API responses.
	 *
	 * @return array
	 */
	public function toArray(): array {
		return array(
			'label'      => $this->label,
			'labels'     => $this->labels,
			'current'    => $this->current,
			'previous'   => $this->previous,
			'metrics'    => $this->metrics->toArray(),
			'categories' => $this->categories->toArray(),
		);
	}

	/**
	 * Get total revenue for current period.
	 *
	 * @return float
	 */
	public function getTotalRevenue(): float {
		return $this->metrics->currentRevenue;
	}

	/**
	 * Get total orders for current period.
	 *
	 * @return int
	 */
	public function getTotalOrders(): int {
		return $this->metrics->currentOrders;
	}

	/**
	 * Calculate revenue change percentage.
	 *
	 * @return float|null Percentage change, or null if previous period had no revenue.
	 */
	public function getRevenueChangePercent(): ?float {
		if ( $this->metrics->previousRevenue <= 0 ) {
			return null;
		}

		return round(
			( ( $this->metrics->currentRevenue - $this->metrics->previousRevenue ) / $this->metrics->previousRevenue ) * 100,
			2
		);
	}

	/**
	 * Calculate order count change percentage.
	 *
	 * @return float|null Percentage change, or null if previous period had no orders.
	 */
	public function getOrdersChangePercent(): ?float {
		if ( $this->metrics->previousOrders <= 0 ) {
			return null;
		}

		return round(
			( ( $this->metrics->currentOrders - $this->metrics->previousOrders ) / $this->metrics->previousOrders ) * 100,
			2
		);
	}
}
