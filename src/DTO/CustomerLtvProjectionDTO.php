<?php
/**
 * Customer LTV Projection DTO.
 *
 * @package AgentWP\DTO
 */

namespace AgentWP\DTO;

/**
 * Immutable customer LTV projection value object.
 *
 * Contains lifetime value projection data.
 */
final class CustomerLtvProjectionDTO {

	/**
	 * Create a new CustomerLtvProjectionDTO.
	 *
	 * @param float  $estimatedLtv          Estimated lifetime value.
	 * @param string $estimatedLtvFormatted Formatted estimated LTV.
	 * @param int    $projectionMonths      Months used for projection.
	 * @param float  $ordersPerMonth        Average orders per month.
	 * @param int    $daysSinceFirstOrder   Days since first order.
	 */
	public function __construct(
		public readonly float $estimatedLtv,
		public readonly string $estimatedLtvFormatted,
		public readonly int $projectionMonths,
		public readonly float $ordersPerMonth,
		public readonly int $daysSinceFirstOrder,
	) {
	}

	/**
	 * Create from raw LTV data.
	 *
	 * @param array $data Raw customer data with LTV fields.
	 * @return self
	 */
	public static function fromArray( array $data ): self {
		$projection = isset( $data['ltv_projection'] ) && is_array( $data['ltv_projection'] ) ? $data['ltv_projection'] : array();

		return new self(
			estimatedLtv: isset( $data['estimated_ltv'] ) ? (float) $data['estimated_ltv'] : 0.0,
			estimatedLtvFormatted: isset( $data['estimated_ltv_formatted'] ) ? (string) $data['estimated_ltv_formatted'] : '',
			projectionMonths: isset( $projection['projection_months'] ) ? (int) $projection['projection_months'] : 12,
			ordersPerMonth: isset( $projection['orders_per_month'] ) ? (float) $projection['orders_per_month'] : 0.0,
			daysSinceFirstOrder: isset( $projection['days_since_first_order'] ) ? (int) $projection['days_since_first_order'] : 0,
		);
	}

	/**
	 * Convert to array format.
	 *
	 * @return array
	 */
	public function toArray(): array {
		return array(
			'estimated_ltv'           => $this->estimatedLtv,
			'estimated_ltv_formatted' => $this->estimatedLtvFormatted,
			'projection'              => array(
				'projection_months'      => $this->projectionMonths,
				'orders_per_month'       => $this->ordersPerMonth,
				'days_since_first_order' => $this->daysSinceFirstOrder,
			),
		);
	}

	/**
	 * Get estimated monthly value.
	 *
	 * @return float
	 */
	public function getMonthlyValue(): float {
		if ( $this->projectionMonths <= 0 ) {
			return 0.0;
		}

		return round( $this->estimatedLtv / $this->projectionMonths, 2 );
	}

	/**
	 * Check if customer has significant LTV.
	 *
	 * @param float $threshold Minimum LTV threshold.
	 * @return bool
	 */
	public function isSignificant( float $threshold = 500.0 ): bool {
		return $this->estimatedLtv >= $threshold;
	}
}
