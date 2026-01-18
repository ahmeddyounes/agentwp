<?php
/**
 * Customer Metrics DTO.
 *
 * @package AgentWP\DTO
 */

namespace AgentWP\DTO;

/**
 * Immutable customer metrics value object.
 *
 * Contains order history metrics for a customer.
 */
final class CustomerMetricsDTO {

	/**
	 * Create a new CustomerMetricsDTO.
	 *
	 * @param int      $totalOrders               Total order count.
	 * @param float    $totalSpent                Total amount spent.
	 * @param string   $totalSpentFormatted       Formatted total spent.
	 * @param float    $averageOrderValue         Average order value.
	 * @param string   $averageOrderValueFormatted Formatted average order value.
	 * @param string   $firstOrderDate            First order date (ISO 8601).
	 * @param string   $lastOrderDate             Last order date (ISO 8601).
	 * @param int|null $daysSinceLastOrder        Days since last order.
	 */
	public function __construct(
		public readonly int $totalOrders,
		public readonly float $totalSpent,
		public readonly string $totalSpentFormatted,
		public readonly float $averageOrderValue,
		public readonly string $averageOrderValueFormatted,
		public readonly string $firstOrderDate,
		public readonly string $lastOrderDate,
		public readonly ?int $daysSinceLastOrder,
	) {
	}

	/**
	 * Create from raw metrics data.
	 *
	 * @param array $data Raw metrics data.
	 * @return self
	 */
	public static function fromArray( array $data ): self {
		return new self(
			totalOrders: isset( $data['total_orders'] ) ? (int) $data['total_orders'] : 0,
			totalSpent: isset( $data['total_spent'] ) ? (float) $data['total_spent'] : 0.0,
			totalSpentFormatted: isset( $data['total_spent_formatted'] ) ? (string) $data['total_spent_formatted'] : '',
			averageOrderValue: isset( $data['average_order_value'] ) ? (float) $data['average_order_value'] : 0.0,
			averageOrderValueFormatted: isset( $data['average_order_value_formatted'] ) ? (string) $data['average_order_value_formatted'] : '',
			firstOrderDate: isset( $data['first_order_date'] ) ? (string) $data['first_order_date'] : '',
			lastOrderDate: isset( $data['last_order_date'] ) ? (string) $data['last_order_date'] : '',
			daysSinceLastOrder: isset( $data['days_since_last_order'] ) ? (int) $data['days_since_last_order'] : null,
		);
	}

	/**
	 * Convert to array format.
	 *
	 * @return array
	 */
	public function toArray(): array {
		return array(
			'total_orders'                  => $this->totalOrders,
			'total_spent'                   => $this->totalSpent,
			'total_spent_formatted'         => $this->totalSpentFormatted,
			'average_order_value'           => $this->averageOrderValue,
			'average_order_value_formatted' => $this->averageOrderValueFormatted,
			'first_order_date'              => $this->firstOrderDate,
			'last_order_date'               => $this->lastOrderDate,
			'days_since_last_order'         => $this->daysSinceLastOrder,
		);
	}

	/**
	 * Check if customer has any orders.
	 *
	 * @return bool
	 */
	public function hasOrders(): bool {
		return $this->totalOrders > 0;
	}

	/**
	 * Check if customer is a high-value customer.
	 *
	 * @param float $threshold Minimum average order value.
	 * @return bool
	 */
	public function isHighValue( float $threshold = 100.0 ): bool {
		return $this->averageOrderValue >= $threshold;
	}
}
