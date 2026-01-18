<?php
/**
 * Address DTO.
 *
 * @package AgentWP\DTO
 */

namespace AgentWP\DTO;

/**
 * Immutable address value object.
 *
 * Represents a shipping or billing address.
 */
final class AddressDTO {

	/**
	 * Create a new AddressDTO.
	 *
	 * @param string $firstName First name.
	 * @param string $lastName  Last name.
	 * @param string $address1  Address line 1.
	 * @param string $address2  Address line 2.
	 * @param string $city      City.
	 * @param string $state     State/province.
	 * @param string $postcode  Postal/ZIP code.
	 * @param string $country   Country code.
	 */
	public function __construct(
		public readonly string $firstName,
		public readonly string $lastName,
		public readonly string $address1,
		public readonly string $address2,
		public readonly string $city,
		public readonly string $state,
		public readonly string $postcode,
		public readonly string $country,
	) {
	}

	/**
	 * Create from raw address data.
	 *
	 * @param array $data Raw address data.
	 * @return self
	 */
	public static function fromArray( array $data ): self {
		return new self(
			firstName: isset( $data['first_name'] ) ? (string) $data['first_name'] : '',
			lastName: isset( $data['last_name'] ) ? (string) $data['last_name'] : '',
			address1: isset( $data['address_1'] ) ? (string) $data['address_1'] : '',
			address2: isset( $data['address_2'] ) ? (string) $data['address_2'] : '',
			city: isset( $data['city'] ) ? (string) $data['city'] : '',
			state: isset( $data['state'] ) ? (string) $data['state'] : '',
			postcode: isset( $data['postcode'] ) ? (string) $data['postcode'] : '',
			country: isset( $data['country'] ) ? (string) $data['country'] : '',
		);
	}

	/**
	 * Convert to array format.
	 *
	 * @return array
	 */
	public function toArray(): array {
		return array(
			'first_name' => $this->firstName,
			'last_name'  => $this->lastName,
			'address_1'  => $this->address1,
			'address_2'  => $this->address2,
			'city'       => $this->city,
			'state'      => $this->state,
			'postcode'   => $this->postcode,
			'country'    => $this->country,
		);
	}

	/**
	 * Get full name.
	 *
	 * @return string
	 */
	public function getFullName(): string {
		return trim( $this->firstName . ' ' . $this->lastName );
	}

	/**
	 * Get formatted address string.
	 *
	 * @return string
	 */
	public function getFormattedAddress(): string {
		$parts = array_filter(
			array(
				$this->address1,
				$this->address2,
				$this->city,
				$this->state,
				$this->postcode,
				$this->country,
			)
		);

		return implode( ', ', $parts );
	}

	/**
	 * Check if address is empty.
	 *
	 * @return bool
	 */
	public function isEmpty(): bool {
		return '' === $this->address1 && '' === $this->city && '' === $this->country;
	}
}
