<?php
/**
 * Order search argument normalizer.
 *
 * @package AgentWP\Services\OrderSearch
 */

namespace AgentWP\Services\OrderSearch;

use AgentWP\Config\AgentWPConfig;
use AgentWP\DTO\OrderQuery;

/**
 * Normalizes and sanitizes order search arguments.
 */
final class ArgumentNormalizer {

	/**
	 * Maximum query string length.
	 */
	public const MAX_QUERY_LENGTH = 500;

	/**
	 * Maximum email length.
	 */
	public const MAX_EMAIL_LENGTH = 254;

	/**
	 * Get default limit.
	 *
	 * Configurable via 'agentwp_config_order_search_default_limit' filter.
	 *
	 * @return int
	 */
	private static function getDefaultLimit(): int {
		return (int) AgentWPConfig::get( 'order_search.default_limit', AgentWPConfig::ORDER_SEARCH_DEFAULT_LIMIT );
	}

	/**
	 * Get max limit.
	 *
	 * Configurable via 'agentwp_config_order_search_max_limit' filter.
	 *
	 * @return int
	 */
	private static function getMaxLimit(): int {
		return (int) AgentWPConfig::get( 'order_search.max_limit', AgentWPConfig::ORDER_SEARCH_MAX_LIMIT );
	}

	/**
	 * Order search parser.
	 *
	 * @var OrderSearchParser
	 */
	private OrderSearchParser $parser;

	/**
	 * Date range parser.
	 *
	 * @var DateRangeParser
	 */
	private DateRangeParser $dateParser;

	/**
	 * Create a new ArgumentNormalizer.
	 *
	 * @param OrderSearchParser $parser     Order search parser.
	 * @param DateRangeParser   $dateParser Date range parser.
	 */
	public function __construct( OrderSearchParser $parser, DateRangeParser $dateParser ) {
		$this->parser     = $parser;
		$this->dateParser = $dateParser;
	}

	/**
	 * Normalize search arguments into an OrderQuery.
	 *
	 * @param array $args Raw search arguments.
	 * @return OrderQuery
	 */
	public function normalize( array $args ): OrderQuery {
		$normalized = $this->sanitizeArgs( $args );

		// If there's a query string, parse it for hints.
		if ( '' !== $normalized['query'] ) {
			return $this->parser->parse( $normalized['query'], $normalized );
		}

		// Build query directly from normalized args.
		return new OrderQuery(
			orderId: $normalized['order_id'] > 0 ? $normalized['order_id'] : null,
			email: '' !== $normalized['email'] ? $normalized['email'] : null,
			status: '' !== $normalized['status'] ? $normalized['status'] : null,
			dateRange: $normalized['date_range'],
			limit: $normalized['limit'],
			orderBy: $normalized['orderby'],
			order: $normalized['order']
		);
	}

	/**
	 * Sanitize raw arguments.
	 *
	 * @param array $args Raw arguments.
	 * @return array
	 */
	private function sanitizeArgs( array $args ): array {
		$query    = isset( $args['query'] ) ? sanitize_text_field( $args['query'] ) : '';
		$orderId  = isset( $args['order_id'] ) ? absint( $args['order_id'] ) : 0;
		$email    = isset( $args['email'] ) ? sanitize_email( $args['email'] ) : '';
		$status   = isset( $args['status'] ) ? $this->normalizeStatus( $args['status'] ) : '';
		$limit    = $this->normalizeLimit( $args['limit'] ?? 0 );
		$orderBy  = $this->normalizeOrderBy( $args['orderby'] ?? '' );
		$order    = $this->normalizeOrder( $args['order'] ?? '' );

		// Enforce maximum length limits to prevent memory exhaustion and DoS.
		$query = $this->truncateString( $query, self::MAX_QUERY_LENGTH );
		$email = $this->truncateString( $email, self::MAX_EMAIL_LENGTH );

		$dateRange = null;
		if ( isset( $args['date_range'] ) ) {
			$dateRange = $this->dateParser->parseFromArray( $args['date_range'] );
		}

		return array(
			'query'      => $query,
			'order_id'   => $orderId,
			'email'      => $email,
			'status'     => $status,
			'limit'      => $limit,
			'orderby'    => $orderBy,
			'order'      => $order,
			'date_range' => $dateRange,
		);
	}

	/**
	 * Truncate a string to a maximum length.
	 *
	 * Uses mb_substr when available to avoid breaking multibyte UTF-8 characters.
	 *
	 * @param string $value     The string to truncate.
	 * @param int    $maxLength Maximum length.
	 * @return string
	 */
	private function truncateString( string $value, int $maxLength ): string {
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );

		if ( $length <= $maxLength ) {
			return $value;
		}

		return function_exists( 'mb_substr' )
			? mb_substr( $value, 0, $maxLength, 'UTF-8' )
			: substr( $value, 0, $maxLength );
	}

	/**
	 * Normalize status value.
	 *
	 * @param mixed $status Raw status.
	 * @return string
	 */
	private function normalizeStatus( $status ): string {
		$status = strtolower( trim( sanitize_text_field( (string) $status ) ) );

		// Remove 'wc-' prefix.
		if ( 0 === strpos( $status, 'wc-' ) ) {
			$status = substr( $status, 3 );
		}

		return $status;
	}

	/**
	 * Normalize limit value.
	 *
	 * @param mixed $limit Raw limit.
	 * @return int
	 */
	private function normalizeLimit( $limit ): int {
		$limit = absint( $limit );

		$defaultLimit = self::getDefaultLimit();
		$maxLimit     = self::getMaxLimit();

		if ( 0 === $limit ) {
			return $defaultLimit;
		}

		if ( $limit > $maxLimit ) {
			return $maxLimit;
		}

		return $limit;
	}

	/**
	 * Normalize orderby value.
	 *
	 * @param mixed $orderBy Raw orderby.
	 * @return string
	 */
	private function normalizeOrderBy( $orderBy ): string {
		$orderBy = strtolower( trim( sanitize_text_field( (string) $orderBy ) ) );

		$allowed = array( 'date', 'id', 'total', 'status' );

		if ( in_array( $orderBy, $allowed, true ) ) {
			return $orderBy;
		}

		return 'date';
	}

	/**
	 * Normalize order direction.
	 *
	 * @param mixed $order Raw order direction.
	 * @return string
	 */
	private function normalizeOrder( $order ): string {
		$order = strtoupper( trim( sanitize_text_field( (string) $order ) ) );

		if ( in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
			return $order;
		}

		return 'DESC';
	}
}
