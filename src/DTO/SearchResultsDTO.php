<?php
/**
 * Search Results DTO.
 *
 * @package AgentWP\DTO
 */

namespace AgentWP\DTO;

/**
 * Immutable search results value object.
 *
 * Contains search results grouped by type.
 */
final class SearchResultsDTO {

	/**
	 * Create a new SearchResultsDTO.
	 *
	 * @param string                     $query     Search query.
	 * @param array<SearchResultItemDTO> $products  Product results.
	 * @param array<SearchResultItemDTO> $orders    Order results.
	 * @param array<SearchResultItemDTO> $customers Customer results.
	 */
	public function __construct(
		public readonly string $query,
		public readonly array $products,
		public readonly array $orders,
		public readonly array $customers,
	) {
	}

	/**
	 * Create from raw search results data.
	 *
	 * @param string $query   Search query.
	 * @param array  $results Raw search results grouped by type.
	 * @return self
	 */
	public static function fromArray( string $query, array $results ): self {
		$products  = array();
		$orders    = array();
		$customers = array();

		if ( isset( $results['products'] ) && is_array( $results['products'] ) ) {
			foreach ( $results['products'] as $item ) {
				if ( is_array( $item ) ) {
					$products[] = SearchResultItemDTO::fromArray( $item );
				}
			}
		}

		if ( isset( $results['orders'] ) && is_array( $results['orders'] ) ) {
			foreach ( $results['orders'] as $item ) {
				if ( is_array( $item ) ) {
					$orders[] = SearchResultItemDTO::fromArray( $item );
				}
			}
		}

		if ( isset( $results['customers'] ) && is_array( $results['customers'] ) ) {
			foreach ( $results['customers'] as $item ) {
				if ( is_array( $item ) ) {
					$customers[] = SearchResultItemDTO::fromArray( $item );
				}
			}
		}

		return new self(
			query: $query,
			products: $products,
			orders: $orders,
			customers: $customers,
		);
	}

	/**
	 * Convert to array format suitable for API responses.
	 *
	 * @return array
	 */
	public function toArray(): array {
		return array(
			'query'   => $this->query,
			'results' => array(
				'products'  => array_map( fn( SearchResultItemDTO $item ) => $item->toArray(), $this->products ),
				'orders'    => array_map( fn( SearchResultItemDTO $item ) => $item->toArray(), $this->orders ),
				'customers' => array_map( fn( SearchResultItemDTO $item ) => $item->toArray(), $this->customers ),
			),
		);
	}

	/**
	 * Get total result count.
	 *
	 * @return int
	 */
	public function getTotalCount(): int {
		return count( $this->products ) + count( $this->orders ) + count( $this->customers );
	}

	/**
	 * Check if there are any results.
	 *
	 * @return bool
	 */
	public function hasResults(): bool {
		return $this->getTotalCount() > 0;
	}

	/**
	 * Get all results as a flat array.
	 *
	 * @return array<SearchResultItemDTO>
	 */
	public function getAllResults(): array {
		return array_merge( $this->products, $this->orders, $this->customers );
	}

	/**
	 * Get results count by type.
	 *
	 * @return array<string, int>
	 */
	public function getCountsByType(): array {
		return array(
			'products'  => count( $this->products ),
			'orders'    => count( $this->orders ),
			'customers' => count( $this->customers ),
		);
	}
}
