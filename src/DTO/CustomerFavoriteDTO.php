<?php
/**
 * Customer Favorite DTO.
 *
 * @package AgentWP\DTO
 */

namespace AgentWP\DTO;

/**
 * Immutable customer favorite value object.
 *
 * Represents a frequently purchased product or category.
 */
final class CustomerFavoriteDTO {

	/**
	 * Create a new CustomerFavoriteDTO.
	 *
	 * @param int    $id       Product or category ID.
	 * @param string $name     Product or category name.
	 * @param int    $quantity Total quantity purchased.
	 * @param string $type     Type: 'product' or 'category'.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $name,
		public readonly int $quantity,
		public readonly string $type,
	) {
	}

	/**
	 * Create from raw favorite data.
	 *
	 * @param array  $data Raw favorite data.
	 * @param string $type Type: 'product' or 'category'.
	 * @return self
	 */
	public static function fromArray( array $data, string $type = 'product' ): self {
		$idKey = 'product' === $type ? 'product_id' : 'category_id';

		return new self(
			id: isset( $data[ $idKey ] ) ? (int) $data[ $idKey ] : 0,
			name: isset( $data['name'] ) ? (string) $data['name'] : '',
			quantity: isset( $data['quantity'] ) ? (int) $data['quantity'] : 0,
			type: $type,
		);
	}

	/**
	 * Convert to array format.
	 *
	 * @return array
	 */
	public function toArray(): array {
		$idKey = 'product' === $this->type ? 'product_id' : 'category_id';

		return array(
			$idKey     => $this->id,
			'name'     => $this->name,
			'quantity' => $this->quantity,
		);
	}

	/**
	 * Check if this is a product favorite.
	 *
	 * @return bool
	 */
	public function isProduct(): bool {
		return 'product' === $this->type;
	}

	/**
	 * Check if this is a category favorite.
	 *
	 * @return bool
	 */
	public function isCategory(): bool {
		return 'category' === $this->type;
	}
}
