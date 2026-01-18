<?php
/**
 * Order Search Results DTO.
 *
 * @package AgentWP\DTO
 */

namespace AgentWP\DTO;

/**
 * Immutable order search results value object.
 *
 * Contains order search results with pagination and query metadata.
 */
final class OrderSearchResultsDTO {

	/**
	 * Create a new OrderSearchResultsDTO.
	 *
	 * @param array<OrderSearchItemDTO> $orders     Order results.
	 * @param int                       $count      Total count of results.
	 * @param bool                      $cached     Whether results were from cache.
	 * @param OrderQuerySummaryDTO      $query      Query summary.
	 */
	public function __construct(
		public readonly array $orders,
		public readonly int $count,
		public readonly bool $cached,
		public readonly OrderQuerySummaryDTO $query,
	) {
	}

	/**
	 * Create from raw search results data.
	 *
	 * @param array $data Raw search results data.
	 * @return self
	 */
	public static function fromArray( array $data ): self {
		$orders = array();
		if ( isset( $data['orders'] ) && is_array( $data['orders'] ) ) {
			foreach ( $data['orders'] as $order ) {
				if ( is_array( $order ) ) {
					$orders[] = OrderSearchItemDTO::fromArray( $order );
				}
			}
		}

		return new self(
			orders: $orders,
			count: isset( $data['count'] ) ? (int) $data['count'] : count( $orders ),
			cached: isset( $data['cached'] ) ? (bool) $data['cached'] : false,
			query: OrderQuerySummaryDTO::fromArray( isset( $data['query'] ) && is_array( $data['query'] ) ? $data['query'] : array() ),
		);
	}

	/**
	 * Convert to array format suitable for API responses.
	 *
	 * @return array
	 */
	public function toArray(): array {
		return array(
			'orders' => array_map( fn( OrderSearchItemDTO $order ) => $order->toArray(), $this->orders ),
			'count'  => $this->count,
			'cached' => $this->cached,
			'query'  => $this->query->toArray(),
		);
	}

	/**
	 * Check if there are any results.
	 *
	 * @return bool
	 */
	public function hasResults(): bool {
		return $this->count > 0;
	}

	/**
	 * Get first order or null.
	 *
	 * @return OrderSearchItemDTO|null
	 */
	public function first(): ?OrderSearchItemDTO {
		return $this->orders[0] ?? null;
	}

	/**
	 * Get IDs of all orders.
	 *
	 * @return array<int>
	 */
	public function getOrderIds(): array {
		return array_map( fn( OrderSearchItemDTO $order ) => $order->id, $this->orders );
	}
}
