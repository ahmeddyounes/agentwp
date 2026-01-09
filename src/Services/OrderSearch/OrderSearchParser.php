<?php
/**
 * Order search query parser.
 *
 * @package AgentWP\Services\OrderSearch
 */

namespace AgentWP\Services\OrderSearch;

use AgentWP\DTO\OrderQuery;

/**
 * Parses natural language order search queries.
 */
final class OrderSearchParser {

	/**
	 * Date range parser.
	 *
	 * @var DateRangeParser
	 */
	private DateRangeParser $dateParser;

	/**
	 * Status mapping.
	 *
	 * @var array<string, string[]>
	 */
	private const STATUS_MAP = array(
		'pending'    => array( 'pending', 'awaiting payment' ),
		'processing' => array( 'processing', 'in progress' ),
		'completed'  => array( 'completed', 'complete', 'fulfilled' ),
		'on-hold'    => array( 'on hold', 'on-hold', 'hold' ),
		'cancelled'  => array( 'cancelled', 'canceled' ),
		'refunded'   => array( 'refunded', 'refund' ),
		'failed'     => array( 'failed', 'declined' ),
	);

	/**
	 * Create a new OrderSearchParser.
	 *
	 * @param DateRangeParser $dateParser Date range parser.
	 */
	public function __construct( DateRangeParser $dateParser ) {
		$this->dateParser = $dateParser;
	}

	/**
	 * Parse a natural language query into an OrderQuery.
	 *
	 * @param string $query    The natural language query.
	 * @param array  $defaults Default values to merge.
	 * @return OrderQuery
	 */
	public function parse( string $query, array $defaults = array() ): OrderQuery {
		$lowered = strtolower( trim( $query ) );

		$orderId    = $defaults['order_id'] ?? null;
		$email      = $defaults['email'] ?? null;
		$status     = $defaults['status'] ?? null;
		$limit      = $defaults['limit'] ?? null;
		$orderBy    = $defaults['orderby'] ?? 'date';
		$order      = $defaults['order'] ?? 'DESC';
		$dateRange  = $defaults['date_range'] ?? null;

		// Extract order ID if not provided.
		if ( null === $orderId || 0 === $orderId ) {
			$orderId = $this->extractOrderId( $lowered );
		}

		// Extract email if not provided.
		if ( null === $email || '' === $email ) {
			$email = $this->extractEmail( $lowered );
		}

		// Detect status if not provided.
		if ( null === $status || '' === $status ) {
			$status = $this->detectStatus( $lowered );
		}

		// Parse date range if not provided.
		if ( null === $dateRange ) {
			$dateRange = $this->dateParser->parseFromQuery( $lowered );
		}

		// Detect "last order" phrase.
		if ( ( null === $limit || 0 === $limit ) && $this->containsLastOrderPhrase( $lowered ) ) {
			$limit   = 1;
			$orderBy = 'date';
			$order   = 'DESC';
		}

		// Convert date_range array to DateRange object if needed.
		if ( is_array( $dateRange ) ) {
			$dateRange = $this->dateParser->parseFromArray( $dateRange );
		}

		return new OrderQuery(
			orderId: $orderId > 0 ? $orderId : null,
			email: '' !== $email ? $email : null,
			status: '' !== $status ? $status : null,
			dateRange: $dateRange,
			search: $query,
			limit: $limit ?? OrderQuery::DEFAULT_LIMIT,
			orderBy: $orderBy,
			order: $order
		);
	}

	/**
	 * Extract order ID from query.
	 *
	 * @param string $query The query string.
	 * @return int
	 */
	public function extractOrderId( string $query ): int {
		// Match "order #123" or "order 123".
		if ( preg_match( '/\border\s*#?\s*(\d+)\b/i', $query, $matches ) ) {
			return (int) $matches[1];
		}

		// Match "#123".
		if ( preg_match( '/#(\d+)/', $query, $matches ) ) {
			return (int) $matches[1];
		}

		return 0;
	}

	/**
	 * Extract email from query.
	 *
	 * @param string $query The query string.
	 * @return string
	 */
	public function extractEmail( string $query ): string {
		if ( preg_match( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $query, $matches ) ) {
			return strtolower( $matches[0] );
		}

		return '';
	}

	/**
	 * Detect order status from query.
	 *
	 * @param string $query The query string.
	 * @return string
	 */
	public function detectStatus( string $query ): string {
		foreach ( self::STATUS_MAP as $status => $terms ) {
			foreach ( $terms as $term ) {
				if ( false !== strpos( $query, $term ) ) {
					return $status;
				}
			}
		}

		return '';
	}

	/**
	 * Check if query contains "last order" phrase.
	 *
	 * @param string $query The query string.
	 * @return bool
	 */
	public function containsLastOrderPhrase( string $query ): bool {
		return (bool) preg_match( '/\b(last|latest|most recent)\s+order\b/i', $query );
	}

	/**
	 * Normalize a status string.
	 *
	 * @param string $status The status string.
	 * @return string
	 */
	public function normalizeStatus( string $status ): string {
		$status = strtolower( trim( $status ) );

		// Remove 'wc-' prefix if present.
		if ( 0 === strpos( $status, 'wc-' ) ) {
			$status = substr( $status, 3 );
		}

		return $status;
	}
}
