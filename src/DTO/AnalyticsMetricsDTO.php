<?php
/**
 * Analytics Metrics DTO.
 *
 * @package AgentWP\DTO
 */

namespace AgentWP\DTO;

/**
 * Immutable analytics metrics value object.
 *
 * Contains summary metrics for current and previous periods:
 * - Revenue
 * - Order count
 * - Average order value
 * - Refunds
 */
final class AnalyticsMetricsDTO {

	/**
	 * Metric labels in display order.
	 */
	public const LABELS = array( 'Revenue', 'Orders', 'Avg Order', 'Refunds' );

	/**
	 * Create a new AnalyticsMetricsDTO.
	 *
	 * @param float $currentRevenue      Current period total revenue.
	 * @param int   $currentOrders       Current period order count.
	 * @param float $currentAvgOrder     Current period average order value.
	 * @param float $currentRefunds      Current period total refunds.
	 * @param float $previousRevenue     Previous period total revenue.
	 * @param int   $previousOrders      Previous period order count.
	 * @param float $previousAvgOrder    Previous period average order value.
	 * @param float $previousRefunds     Previous period total refunds.
	 */
	public function __construct(
		public readonly float $currentRevenue,
		public readonly int $currentOrders,
		public readonly float $currentAvgOrder,
		public readonly float $currentRefunds,
		public readonly float $previousRevenue,
		public readonly int $previousOrders,
		public readonly float $previousAvgOrder,
		public readonly float $previousRefunds,
	) {
	}

	/**
	 * Create from raw metrics data.
	 *
	 * @param array $data Raw metrics data with 'labels', 'current', 'previous' keys.
	 * @return self
	 */
	public static function fromArray( array $data ): self {
		$current  = isset( $data['current'] ) && is_array( $data['current'] ) ? $data['current'] : array();
		$previous = isset( $data['previous'] ) && is_array( $data['previous'] ) ? $data['previous'] : array();

		return new self(
			currentRevenue: isset( $current[0] ) ? (float) $current[0] : 0.0,
			currentOrders: isset( $current[1] ) ? (int) $current[1] : 0,
			currentAvgOrder: isset( $current[2] ) ? (float) $current[2] : 0.0,
			currentRefunds: isset( $current[3] ) ? (float) $current[3] : 0.0,
			previousRevenue: isset( $previous[0] ) ? (float) $previous[0] : 0.0,
			previousOrders: isset( $previous[1] ) ? (int) $previous[1] : 0,
			previousAvgOrder: isset( $previous[2] ) ? (float) $previous[2] : 0.0,
			previousRefunds: isset( $previous[3] ) ? (float) $previous[3] : 0.0,
		);
	}

	/**
	 * Convert to array format matching the original API structure.
	 *
	 * @return array
	 */
	public function toArray(): array {
		return array(
			'labels'   => self::LABELS,
			'current'  => array(
				$this->currentRevenue,
				$this->currentOrders,
				$this->currentAvgOrder,
				$this->currentRefunds,
			),
			'previous' => array(
				$this->previousRevenue,
				$this->previousOrders,
				$this->previousAvgOrder,
				$this->previousRefunds,
			),
		);
	}

	/**
	 * Get revenue change.
	 *
	 * @return float
	 */
	public function getRevenueChange(): float {
		return $this->currentRevenue - $this->previousRevenue;
	}

	/**
	 * Get orders change.
	 *
	 * @return int
	 */
	public function getOrdersChange(): int {
		return $this->currentOrders - $this->previousOrders;
	}

	/**
	 * Check if current period revenue is higher than previous.
	 *
	 * @return bool
	 */
	public function isRevenueUp(): bool {
		return $this->currentRevenue > $this->previousRevenue;
	}

	/**
	 * Check if current period orders are higher than previous.
	 *
	 * @return bool
	 */
	public function isOrdersUp(): bool {
		return $this->currentOrders > $this->previousOrders;
	}
}
