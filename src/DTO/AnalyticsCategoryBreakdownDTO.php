<?php
/**
 * Analytics Category Breakdown DTO.
 *
 * @package AgentWP\DTO
 */

namespace AgentWP\DTO;

/**
 * Immutable analytics category breakdown value object.
 *
 * Contains revenue breakdown by product category.
 */
final class AnalyticsCategoryBreakdownDTO {

	/**
	 * Create a new AnalyticsCategoryBreakdownDTO.
	 *
	 * @param array<string> $labels Category names.
	 * @param array<float>  $values Sales amounts per category.
	 */
	public function __construct(
		public readonly array $labels,
		public readonly array $values,
	) {
	}

	/**
	 * Create from raw category data.
	 *
	 * @param array $data Raw category data with 'labels' and 'values' keys.
	 * @return self
	 */
	public static function fromArray( array $data ): self {
		return new self(
			labels: isset( $data['labels'] ) && is_array( $data['labels'] ) ? array_map( 'strval', $data['labels'] ) : array(),
			values: isset( $data['values'] ) && is_array( $data['values'] ) ? array_map( 'floatval', $data['values'] ) : array(),
		);
	}

	/**
	 * Convert to array format.
	 *
	 * @return array
	 */
	public function toArray(): array {
		return array(
			'labels' => $this->labels,
			'values' => $this->values,
		);
	}

	/**
	 * Get total sales across all categories.
	 *
	 * @return float
	 */
	public function getTotal(): float {
		return array_sum( $this->values );
	}

	/**
	 * Get the number of categories.
	 *
	 * @return int
	 */
	public function getCount(): int {
		return count( $this->labels );
	}

	/**
	 * Get the top category by sales.
	 *
	 * @return array{label: string, value: float}|null
	 */
	public function getTopCategory(): ?array {
		if ( empty( $this->labels ) || empty( $this->values ) ) {
			return null;
		}

		$maxIndex = array_keys( $this->values, max( $this->values ), true )[0];

		return array(
			'label' => $this->labels[ $maxIndex ] ?? '',
			'value' => $this->values[ $maxIndex ] ?? 0.0,
		);
	}
}
