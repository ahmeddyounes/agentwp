<?php
/**
 * Order query DTO.
 *
 * @package AgentWP\DTO
 */

namespace AgentWP\DTO;

/**
 * Immutable order query parameters value object.
 */
final class OrderQuery {

	/**
	 * Default limit for order queries.
	 */
	public const DEFAULT_LIMIT = 10;

	/**
	 * Maximum limit for order queries.
	 */
	public const MAX_LIMIT = 50;

	/**
	 * Create a new OrderQuery.
	 *
	 * @param int|null       $orderId    Specific order ID to find.
	 * @param string|null    $email      Filter by customer email.
	 * @param string|null    $status     Filter by order status.
	 * @param int|null       $customerId Filter by customer ID.
	 * @param DateRange|null $dateRange  Filter by date range.
	 * @param int            $limit      Maximum orders to return (1-50).
	 * @param int            $offset     Number of orders to skip for pagination.
	 * @param string         $orderBy    Field to order by.
	 * @param string         $order      Order direction (ASC or DESC).
	 * @param string|null    $search     Free text search query.
	 */
	public function __construct(
		public readonly ?int $orderId = null,
		public readonly ?string $email = null,
		public readonly ?string $status = null,
		public readonly ?int $customerId = null,
		public readonly ?DateRange $dateRange = null,
		int $limit = self::DEFAULT_LIMIT,
		int $offset = 0,
		public readonly string $orderBy = 'date',
		public readonly string $order = 'DESC',
		public readonly ?string $search = null,
	) {
		// Enforce limit bounds to prevent resource exhaustion.
		$this->limit = min( max( 1, $limit ), self::MAX_LIMIT );
		// Enforce non-negative offset.
		$this->offset = max( 0, $offset );
	}

	/**
	 * Maximum orders to return.
	 *
	 * @var int
	 */
	public readonly int $limit;

	/**
	 * Number of orders to skip for pagination.
	 *
	 * @var int
	 */
	public readonly int $offset;

	/**
	 * Create a query for a specific order ID.
	 *
	 * @param int $orderId The order ID.
	 * @return self
	 */
	public static function byId( int $orderId ): self {
		return new self( orderId: $orderId );
	}

	/**
	 * Create a query for orders by email.
	 *
	 * @param string $email The customer email.
	 * @param int    $limit Maximum orders.
	 * @return self
	 */
	public static function byEmail( string $email, int $limit = self::DEFAULT_LIMIT ): self {
		return new self( email: $email, limit: $limit );
	}

	/**
	 * Create a query for orders by status.
	 *
	 * @param string $status The order status.
	 * @param int    $limit  Maximum orders.
	 * @return self
	 */
	public static function byStatus( string $status, int $limit = self::DEFAULT_LIMIT ): self {
		return new self( status: $status, limit: $limit );
	}

	/**
	 * Create a query for orders in a date range.
	 *
	 * @param DateRange $dateRange The date range.
	 * @param int       $limit     Maximum orders.
	 * @return self
	 */
	public static function inDateRange( DateRange $dateRange, int $limit = self::DEFAULT_LIMIT ): self {
		return new self( dateRange: $dateRange, limit: $limit );
	}

	/**
	 * Create a query for recent orders.
	 *
	 * @param int $limit Maximum orders.
	 * @return self
	 */
	public static function recent( int $limit = self::DEFAULT_LIMIT ): self {
		return new self( limit: $limit, orderBy: 'date', order: 'DESC' );
	}

	/**
	 * Create a new query with modified limit.
	 *
	 * @param int $limit The new limit.
	 * @return self
	 */
	public function withLimit( int $limit ): self {
		$limit = min( max( 1, $limit ), self::MAX_LIMIT );

		return new self(
			orderId: $this->orderId,
			email: $this->email,
			status: $this->status,
			customerId: $this->customerId,
			dateRange: $this->dateRange,
			limit: $limit,
			offset: $this->offset,
			orderBy: $this->orderBy,
			order: $this->order,
			search: $this->search,
		);
	}

	/**
	 * Create a new query with modified offset.
	 *
	 * @param int $offset The new offset.
	 * @return self
	 */
	public function withOffset( int $offset ): self {
		return new self(
			orderId: $this->orderId,
			email: $this->email,
			status: $this->status,
			customerId: $this->customerId,
			dateRange: $this->dateRange,
			limit: $this->limit,
			offset: $offset,
			orderBy: $this->orderBy,
			order: $this->order,
			search: $this->search,
		);
	}

	/**
	 * Create a new query with modified status.
	 *
	 * @param string|null $status The new status.
	 * @return self
	 */
	public function withStatus( ?string $status ): self {
		return new self(
			orderId: $this->orderId,
			email: $this->email,
			status: $status,
			customerId: $this->customerId,
			dateRange: $this->dateRange,
			limit: $this->limit,
			offset: $this->offset,
			orderBy: $this->orderBy,
			order: $this->order,
			search: $this->search,
		);
	}

	/**
	 * Create a new query with modified date range.
	 *
	 * @param DateRange|null $dateRange The new date range.
	 * @return self
	 */
	public function withDateRange( ?DateRange $dateRange ): self {
		return new self(
			orderId: $this->orderId,
			email: $this->email,
			status: $this->status,
			customerId: $this->customerId,
			dateRange: $dateRange,
			limit: $this->limit,
			offset: $this->offset,
			orderBy: $this->orderBy,
			order: $this->order,
			search: $this->search,
		);
	}

	/**
	 * Check if this is a single order query.
	 *
	 * @return bool True if querying by ID.
	 */
	public function isSingleOrderQuery(): bool {
		return null !== $this->orderId;
	}

	/**
	 * Convert to array format for debugging.
	 *
	 * @return array
	 */
	public function toArray(): array {
		return array(
			'order_id'    => $this->orderId,
			'email'       => $this->email,
			'status'      => $this->status,
			'customer_id' => $this->customerId,
			'date_range'  => $this->dateRange?->toArray(),
			'limit'       => $this->limit,
			'offset'      => $this->offset,
			'orderby'     => $this->orderBy,
			'order'       => $this->order,
			'search'      => $this->search,
		);
	}
}
