<?php
/**
 * Usage Daily Trend DTO.
 *
 * @package AgentWP\DTO
 */

namespace AgentWP\DTO;

/**
 * Immutable usage daily trend value object.
 *
 * Represents token usage for a single day.
 */
final class UsageDailyTrendDTO {

	/**
	 * Create a new UsageDailyTrendDTO.
	 *
	 * @param string $date         Date (Y-m-d format).
	 * @param int    $totalTokens  Total tokens for the day.
	 * @param float  $totalCostUsd Total cost in USD.
	 */
	public function __construct(
		public readonly string $date,
		public readonly int $totalTokens,
		public readonly float $totalCostUsd,
	) {
	}

	/**
	 * Create from raw daily trend data.
	 *
	 * @param array $data Raw daily trend data.
	 * @return self
	 */
	public static function fromArray( array $data ): self {
		return new self(
			date: isset( $data['date'] ) ? (string) $data['date'] : '',
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
			'date'           => $this->date,
			'total_tokens'   => $this->totalTokens,
			'total_cost_usd' => $this->totalCostUsd,
		);
	}

	/**
	 * Check if this day had any usage.
	 *
	 * @return bool
	 */
	public function hasUsage(): bool {
		return $this->totalTokens > 0;
	}

	/**
	 * Get formatted date for display.
	 *
	 * @param string $format Date format.
	 * @return string
	 */
	public function getFormattedDate( string $format = 'M j' ): string {
		try {
			$dt = new \DateTimeImmutable( $this->date );
			return $dt->format( $format );
		} catch ( \Exception $e ) {
			return $this->date;
		}
	}
}
