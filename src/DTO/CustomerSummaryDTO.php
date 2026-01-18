<?php
/**
 * Customer Summary DTO.
 *
 * @package AgentWP\DTO
 */

namespace AgentWP\DTO;

/**
 * Immutable customer summary value object.
 *
 * Contains basic customer identification data.
 */
final class CustomerSummaryDTO {

	/**
	 * Create a new CustomerSummaryDTO.
	 *
	 * @param int    $customerId Customer ID (0 for guests).
	 * @param string $email      Customer email.
	 * @param string $name       Customer display name.
	 * @param bool   $isGuest    Whether this is a guest customer.
	 */
	public function __construct(
		public readonly int $customerId,
		public readonly string $email,
		public readonly string $name,
		public readonly bool $isGuest,
	) {
	}

	/**
	 * Create from raw customer data.
	 *
	 * @param array $data Raw customer data.
	 * @return self
	 */
	public static function fromArray( array $data ): self {
		return new self(
			customerId: isset( $data['customer_id'] ) ? (int) $data['customer_id'] : 0,
			email: isset( $data['email'] ) ? (string) $data['email'] : '',
			name: isset( $data['name'] ) ? (string) $data['name'] : '',
			isGuest: isset( $data['is_guest'] ) ? (bool) $data['is_guest'] : true,
		);
	}

	/**
	 * Convert to array format.
	 *
	 * @return array
	 */
	public function toArray(): array {
		return array(
			'customer_id' => $this->customerId,
			'email'       => $this->email,
			'name'        => $this->name,
			'is_guest'    => $this->isGuest,
		);
	}

	/**
	 * Get display identifier (name or email).
	 *
	 * @return string
	 */
	public function getDisplayIdentifier(): string {
		if ( '' !== $this->name ) {
			return $this->name;
		}

		return $this->email;
	}
}
