<?php
/**
 * Order Query Summary DTO.
 *
 * @package AgentWP\DTO
 */

namespace AgentWP\DTO;

/**
 * Immutable order query summary value object.
 *
 * Describes the query parameters used for an order search.
 */
final class OrderQuerySummaryDTO {

	/**
	 * Create a new OrderQuerySummaryDTO.
	 *
	 * @param int         $orderId   Specific order ID searched.
	 * @param string      $email     Email filter.
	 * @param string      $status    Status filter.
	 * @param int         $limit     Result limit.
	 * @param int         $offset    Result offset.
	 * @param array|null  $dateRange Date range filter.
	 */
	public function __construct(
		public readonly int $orderId,
		public readonly string $email,
		public readonly string $status,
		public readonly int $limit,
		public readonly int $offset,
		public readonly ?array $dateRange,
	) {
	}

	/**
	 * Create from raw query summary data.
	 *
	 * @param array $data Raw query summary data.
	 * @return self
	 */
	public static function fromArray( array $data ): self {
		$dateRange = null;
		if ( isset( $data['date_range'] ) && is_array( $data['date_range'] ) ) {
			$dateRange = array(
				'start' => isset( $data['date_range']['start'] ) ? (string) $data['date_range']['start'] : '',
				'end'   => isset( $data['date_range']['end'] ) ? (string) $data['date_range']['end'] : '',
			);
		}

		return new self(
			orderId: isset( $data['order_id'] ) ? (int) $data['order_id'] : 0,
			email: isset( $data['email'] ) ? (string) $data['email'] : '',
			status: isset( $data['status'] ) ? (string) $data['status'] : '',
			limit: isset( $data['limit'] ) ? (int) $data['limit'] : 10,
			offset: isset( $data['offset'] ) ? (int) $data['offset'] : 0,
			dateRange: $dateRange,
		);
	}

	/**
	 * Convert to array format.
	 *
	 * @return array
	 */
	public function toArray(): array {
		return array(
			'order_id'   => $this->orderId,
			'email'      => $this->email,
			'status'     => $this->status,
			'limit'      => $this->limit,
			'offset'     => $this->offset,
			'date_range' => $this->dateRange,
		);
	}

	/**
	 * Check if this is a single order lookup.
	 *
	 * @return bool
	 */
	public function isSingleOrderLookup(): bool {
		return $this->orderId > 0;
	}

	/**
	 * Check if query has any filters.
	 *
	 * @return bool
	 */
	public function hasFilters(): bool {
		return $this->orderId > 0
			|| '' !== $this->email
			|| '' !== $this->status
			|| null !== $this->dateRange;
	}

	/**
	 * Get human-readable description.
	 *
	 * @return string
	 */
	public function getDescription(): string {
		if ( $this->isSingleOrderLookup() ) {
			return sprintf( 'Order #%d', $this->orderId );
		}

		$parts = array();

		if ( '' !== $this->email ) {
			$parts[] = sprintf( 'email: %s', $this->email );
		}

		if ( '' !== $this->status ) {
			$parts[] = sprintf( 'status: %s', $this->status );
		}

		if ( null !== $this->dateRange ) {
			$parts[] = sprintf(
				'date: %s to %s',
				$this->dateRange['start'] ?? '',
				$this->dateRange['end'] ?? ''
			);
		}

		if ( empty( $parts ) ) {
			return 'All orders';
		}

		return implode( ', ', $parts );
	}
}
