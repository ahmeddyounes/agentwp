<?php
/**
 * Usage Summary DTO.
 *
 * @package AgentWP\DTO
 */

namespace AgentWP\DTO;

/**
 * Immutable usage summary value object.
 *
 * Contains API usage statistics for a time period.
 */
final class UsageSummaryDTO {

	/**
	 * Create a new UsageSummaryDTO.
	 *
	 * @param string                        $period            Period identifier (day, week, month).
	 * @param string                        $periodStart       Period start datetime.
	 * @param string                        $periodEnd         Period end datetime.
	 * @param int                           $totalTokens       Total tokens used.
	 * @param float                         $totalCostUsd      Total cost in USD.
	 * @param array<UsageBreakdownItemDTO>  $breakdownByIntent Usage breakdown by intent type.
	 * @param array<UsageDailyTrendDTO>     $dailyTrend        Daily usage trend.
	 */
	public function __construct(
		public readonly string $period,
		public readonly string $periodStart,
		public readonly string $periodEnd,
		public readonly int $totalTokens,
		public readonly float $totalCostUsd,
		public readonly array $breakdownByIntent,
		public readonly array $dailyTrend,
	) {
	}

	/**
	 * Create from raw usage summary data.
	 *
	 * @param array $data Raw usage summary data.
	 * @return self
	 */
	public static function fromArray( array $data ): self {
		$breakdown = array();
		if ( isset( $data['breakdown_by_intent'] ) && is_array( $data['breakdown_by_intent'] ) ) {
			foreach ( $data['breakdown_by_intent'] as $item ) {
				if ( is_array( $item ) ) {
					$breakdown[] = UsageBreakdownItemDTO::fromArray( $item );
				}
			}
		}

		$trend = array();
		if ( isset( $data['daily_trend'] ) && is_array( $data['daily_trend'] ) ) {
			foreach ( $data['daily_trend'] as $item ) {
				if ( is_array( $item ) ) {
					$trend[] = UsageDailyTrendDTO::fromArray( $item );
				}
			}
		}

		return new self(
			period: isset( $data['period'] ) ? (string) $data['period'] : 'month',
			periodStart: isset( $data['period_start'] ) ? (string) $data['period_start'] : '',
			periodEnd: isset( $data['period_end'] ) ? (string) $data['period_end'] : '',
			totalTokens: isset( $data['total_tokens'] ) ? (int) $data['total_tokens'] : 0,
			totalCostUsd: isset( $data['total_cost_usd'] ) ? (float) $data['total_cost_usd'] : 0.0,
			breakdownByIntent: $breakdown,
			dailyTrend: $trend,
		);
	}

	/**
	 * Convert to array format suitable for API responses.
	 *
	 * @return array
	 */
	public function toArray(): array {
		return array(
			'period'              => $this->period,
			'period_start'        => $this->periodStart,
			'period_end'          => $this->periodEnd,
			'total_tokens'        => $this->totalTokens,
			'total_cost_usd'      => $this->totalCostUsd,
			'breakdown_by_intent' => array_map( fn( UsageBreakdownItemDTO $item ) => $item->toArray(), $this->breakdownByIntent ),
			'daily_trend'         => array_map( fn( UsageDailyTrendDTO $item ) => $item->toArray(), $this->dailyTrend ),
		);
	}

	/**
	 * Get average daily tokens.
	 *
	 * @return float
	 */
	public function getAverageDailyTokens(): float {
		if ( empty( $this->dailyTrend ) ) {
			return 0.0;
		}

		return round( $this->totalTokens / count( $this->dailyTrend ), 2 );
	}

	/**
	 * Get average daily cost.
	 *
	 * @return float
	 */
	public function getAverageDailyCost(): float {
		if ( empty( $this->dailyTrend ) ) {
			return 0.0;
		}

		return round( $this->totalCostUsd / count( $this->dailyTrend ), 6 );
	}

	/**
	 * Get top intent by token usage.
	 *
	 * @return UsageBreakdownItemDTO|null
	 */
	public function getTopIntent(): ?UsageBreakdownItemDTO {
		if ( empty( $this->breakdownByIntent ) ) {
			return null;
		}

		return $this->breakdownByIntent[0];
	}

	/**
	 * Check if there is any usage.
	 *
	 * @return bool
	 */
	public function hasUsage(): bool {
		return $this->totalTokens > 0;
	}
}
