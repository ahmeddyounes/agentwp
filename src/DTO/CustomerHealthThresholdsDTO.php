<?php
/**
 * Customer Health Thresholds DTO.
 *
 * @package AgentWP\DTO
 */

namespace AgentWP\DTO;

/**
 * Immutable customer health thresholds value object.
 *
 * Defines the day thresholds for customer health status.
 */
final class CustomerHealthThresholdsDTO {

	/**
	 * Create a new CustomerHealthThresholdsDTO.
	 *
	 * @param int $active  Days threshold for "active" status.
	 * @param int $atRisk  Days threshold for "at_risk" status.
	 */
	public function __construct(
		public readonly int $active,
		public readonly int $atRisk,
	) {
	}

	/**
	 * Create from raw thresholds data.
	 *
	 * @param array $data Raw thresholds data.
	 * @return self
	 */
	public static function fromArray( array $data ): self {
		return new self(
			active: isset( $data['active'] ) ? (int) $data['active'] : 60,
			atRisk: isset( $data['at_risk'] ) ? (int) $data['at_risk'] : 180,
		);
	}

	/**
	 * Convert to array format.
	 *
	 * @return array
	 */
	public function toArray(): array {
		return array(
			'active'  => $this->active,
			'at_risk' => $this->atRisk,
		);
	}

	/**
	 * Determine health status for a given days since last order.
	 *
	 * @param int|null $daysSinceLastOrder Days since last order.
	 * @return string Health status: 'active', 'at_risk', or 'churned'.
	 */
	public function determineStatus( ?int $daysSinceLastOrder ): string {
		if ( null === $daysSinceLastOrder ) {
			return 'churned';
		}

		if ( $daysSinceLastOrder <= $this->active ) {
			return 'active';
		}

		if ( $daysSinceLastOrder <= $this->atRisk ) {
			return 'at_risk';
		}

		return 'churned';
	}
}
