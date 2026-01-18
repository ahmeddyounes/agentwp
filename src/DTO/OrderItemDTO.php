<?php
/**
 * Order Item DTO.
 *
 * @package AgentWP\DTO
 */

namespace AgentWP\DTO;

/**
 * Immutable order item value object.
 *
 * Represents a single item in an order.
 */
final class OrderItemDTO {

	/**
	 * Create a new OrderItemDTO.
	 *
	 * @param string $name     Product name.
	 * @param int    $quantity Quantity ordered.
	 */
	public function __construct(
		public readonly string $name,
		public readonly int $quantity,
	) {
	}

	/**
	 * Create from raw item data.
	 *
	 * @param array $data Raw item data.
	 * @return self
	 */
	public static function fromArray( array $data ): self {
		return new self(
			name: isset( $data['name'] ) ? (string) $data['name'] : '',
			quantity: isset( $data['quantity'] ) ? (int) $data['quantity'] : 1,
		);
	}

	/**
	 * Convert to array format.
	 *
	 * @return array
	 */
	public function toArray(): array {
		return array(
			'name'     => $this->name,
			'quantity' => $this->quantity,
		);
	}

	/**
	 * Get formatted display string.
	 *
	 * @return string
	 */
	public function getDisplayString(): string {
		if ( $this->quantity > 1 ) {
			return sprintf( '%s (x%d)', $this->name, $this->quantity );
		}

		return $this->name;
	}
}
