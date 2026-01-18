<?php
/**
 * Search Result Item DTO.
 *
 * @package AgentWP\DTO
 */

namespace AgentWP\DTO;

/**
 * Immutable search result item value object.
 *
 * Represents a single search result for products, orders, or customers.
 */
final class SearchResultItemDTO {

	/**
	 * Create a new SearchResultItemDTO.
	 *
	 * @param int    $id        Object ID.
	 * @param string $type      Result type (products, orders, customers).
	 * @param string $primary   Primary display text.
	 * @param string $secondary Secondary display text.
	 * @param string $query     Structured query string for navigation.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $type,
		public readonly string $primary,
		public readonly string $secondary,
		public readonly string $query,
	) {
	}

	/**
	 * Create from raw search result data.
	 *
	 * @param array $data Raw search result data.
	 * @return self
	 */
	public static function fromArray( array $data ): self {
		return new self(
			id: isset( $data['id'] ) ? (int) $data['id'] : 0,
			type: isset( $data['type'] ) ? (string) $data['type'] : '',
			primary: isset( $data['primary'] ) ? (string) $data['primary'] : '',
			secondary: isset( $data['secondary'] ) ? (string) $data['secondary'] : '',
			query: isset( $data['query'] ) ? (string) $data['query'] : '',
		);
	}

	/**
	 * Convert to array format.
	 *
	 * @return array
	 */
	public function toArray(): array {
		return array(
			'id'        => $this->id,
			'type'      => $this->type,
			'primary'   => $this->primary,
			'secondary' => $this->secondary,
			'query'     => $this->query,
		);
	}

	/**
	 * Check if this is a product result.
	 *
	 * @return bool
	 */
	public function isProduct(): bool {
		return 'products' === $this->type;
	}

	/**
	 * Check if this is an order result.
	 *
	 * @return bool
	 */
	public function isOrder(): bool {
		return 'orders' === $this->type;
	}

	/**
	 * Check if this is a customer result.
	 *
	 * @return bool
	 */
	public function isCustomer(): bool {
		return 'customers' === $this->type;
	}

	/**
	 * Get display text combining primary and secondary.
	 *
	 * @return string
	 */
	public function getDisplayText(): string {
		if ( '' === $this->secondary ) {
			return $this->primary;
		}

		return sprintf( '%s (%s)', $this->primary, $this->secondary );
	}
}
