<?php
/**
 * Usage Breakdown Item DTO.
 *
 * @package AgentWP\DTO
 */

namespace AgentWP\DTO;

/**
 * Immutable usage breakdown item value object.
 *
 * Represents token usage for a specific intent type.
 */
final class UsageBreakdownItemDTO {

	/**
	 * Create a new UsageBreakdownItemDTO.
	 *
	 * @param string $intentType   Intent type identifier.
	 * @param int    $totalTokens  Total tokens for this intent.
	 * @param float  $totalCostUsd Total cost in USD.
	 */
	public function __construct(
		public readonly string $intentType,
		public readonly int $totalTokens,
		public readonly float $totalCostUsd,
	) {
	}

	/**
	 * Create from raw breakdown data.
	 *
	 * @param array $data Raw breakdown data.
	 * @return self
	 */
	public static function fromArray( array $data ): self {
		return new self(
			intentType: isset( $data['intent_type'] ) ? (string) $data['intent_type'] : 'UNKNOWN',
			totalTokens: isset( $data['total_tokens'] ) ? (int) $data['total_tokens'] : 0,
			totalCostUsd: isset( $data['total_cost_usd'] ) ? (float) $data['total_cost_usd'] : 0.0,
		);
	}

	/**
	 * Convert to array format.
	 *
	 * @return array
	 */
	public function toArray(): array {
		return array(
			'intent_type'    => $this->intentType,
			'total_tokens'   => $this->totalTokens,
			'total_cost_usd' => $this->totalCostUsd,
		);
	}

	/**
	 * Get cost per token (in USD).
	 *
	 * @return float
	 */
	public function getCostPerToken(): float {
		if ( $this->totalTokens <= 0 ) {
			return 0.0;
		}

		return $this->totalCostUsd / $this->totalTokens;
	}

	/**
	 * Get formatted display name.
	 *
	 * @return string
	 */
	public function getDisplayName(): string {
		return ucwords( str_replace( '_', ' ', strtolower( $this->intentType ) ) );
	}
}
