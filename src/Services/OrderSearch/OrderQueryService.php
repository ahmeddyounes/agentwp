<?php
/**
 * Order query service.
 *
 * @package AgentWP\Services\OrderSearch
 */

namespace AgentWP\Services\OrderSearch;

use AgentWP\Contracts\CacheInterface;
use AgentWP\Contracts\OrderRepositoryInterface;
use AgentWP\Contracts\TransientCacheInterface;
use AgentWP\DTO\OrderDTO;
use AgentWP\DTO\OrderQuery;

/**
 * Executes order queries with caching.
 */
final class OrderQueryService {

	/**
	 * Default result cache TTL in seconds.
	 */
	public const DEFAULT_CACHE_TTL = 3600;

	/**
	 * Order object cache TTL in seconds.
	 */
	public const ORDER_CACHE_TTL = 300;

	/**
	 * Cache key prefix.
	 */
	public const CACHE_PREFIX = 'order_search_';

	/**
	 * Option key for cache version.
	 */
	private const VERSION_OPTION = 'agentwp_order_cache_version';

	/**
	 * Order repository.
	 *
	 * @var OrderRepositoryInterface
	 */
	private OrderRepositoryInterface $repository;

	/**
	 * Transient cache for results.
	 *
	 * @var TransientCacheInterface
	 */
	private TransientCacheInterface $resultCache;

	/**
	 * Object cache for individual orders.
	 *
	 * @var CacheInterface
	 */
	private CacheInterface $orderCache;

	/**
	 * Order formatter.
	 *
	 * @var OrderFormatter
	 */
	private OrderFormatter $formatter;

	/**
	 * Create a new OrderQueryService.
	 *
	 * @param OrderRepositoryInterface $repository  Order repository.
	 * @param TransientCacheInterface  $resultCache Transient cache for results.
	 * @param CacheInterface           $orderCache  Object cache for orders.
	 * @param OrderFormatter           $formatter   Order formatter.
	 */
	public function __construct(
		OrderRepositoryInterface $repository,
		TransientCacheInterface $resultCache,
		CacheInterface $orderCache,
		OrderFormatter $formatter
	) {
		$this->repository  = $repository;
		$this->resultCache = $resultCache;
		$this->orderCache  = $orderCache;
		$this->formatter   = $formatter;
	}

	/**
	 * Search orders.
	 *
	 * @param OrderQuery $query The query parameters.
	 * @return array{orders: array, count: int, cached: bool, query: array}
	 */
	public function search( OrderQuery $query ): array {
		$cacheKey = $this->buildCacheKey( $query );
		$cached   = $this->resultCache->get( $cacheKey );

		if ( null !== $cached && is_array( $cached ) ) {
			return array(
				'orders' => $cached,
				'count'  => count( $cached ),
				'cached' => true,
				'query'  => $this->formatQuerySummary( $query ),
			);
		}

		$orders = $this->executeQuery( $query );
		$this->resultCache->set( $cacheKey, $orders, self::DEFAULT_CACHE_TTL );

		return array(
			'orders' => $orders,
			'count'  => count( $orders ),
			'cached' => false,
			'query'  => $this->formatQuerySummary( $query ),
		);
	}

	/**
	 * Execute the query.
	 *
	 * @param OrderQuery $query The query parameters.
	 * @return array
	 */
	private function executeQuery( OrderQuery $query ): array {
		// Handle single order lookup.
		if ( null !== $query->orderId ) {
			return $this->findById( $query->orderId );
		}

		// Query multiple orders.
		$orderDTOs = $this->repository->query( $query );

		return $this->formatOrders( $orderDTOs );
	}

	/**
	 * Find a single order by ID.
	 *
	 * @param int $orderId The order ID.
	 * @return array
	 */
	private function findById( int $orderId ): array {
		// Check object cache first.
		$cached = $this->orderCache->get( (string) $orderId );

		if ( null !== $cached && is_array( $cached ) ) {
			return array( $cached );
		}

		$order = $this->repository->find( $orderId );

		if ( null === $order ) {
			return array();
		}

		$formatted = $this->formatter->formatDTO( $order );
		$this->orderCache->set( (string) $orderId, $formatted, self::ORDER_CACHE_TTL );

		return array( $formatted );
	}

	/**
	 * Format order DTOs for response.
	 *
	 * @param OrderDTO[] $orders The orders.
	 * @return array
	 */
	private function formatOrders( array $orders ): array {
		$results = array();

		foreach ( $orders as $order ) {
			// Check object cache.
			$cached = $this->orderCache->get( (string) $order->id );

			if ( null !== $cached && is_array( $cached ) ) {
				$results[] = $cached;
				continue;
			}

			$formatted = $this->formatter->formatDTO( $order );
			$this->orderCache->set( (string) $order->id, $formatted, self::ORDER_CACHE_TTL );
			$results[] = $formatted;
		}

		return $results;
	}

	/**
	 * Build cache key for query.
	 *
	 * @param OrderQuery $query The query.
	 * @return string
	 */
	private function buildCacheKey( OrderQuery $query ): string {
		$parts = array(
			'version'  => $this->getCacheVersion(),
			'id'       => $query->orderId,
			'email'    => $query->email,
			'status'   => $query->status,
			'customer' => $query->customerId,
			'limit'    => $query->limit,
			'offset'   => $query->offset,
			'search'   => $query->search,
			'order'    => $query->order,
			'by'       => $query->orderBy,
		);

		if ( null !== $query->dateRange ) {
			// Include full datetime to avoid cache collisions for time-specific queries.
			$parts['date_start'] = $query->dateRange->start->format( 'Y-m-d H:i:s' );
			$parts['date_end']   = $query->dateRange->end->format( 'Y-m-d H:i:s' );
		}

		$options = defined( 'JSON_INVALID_UTF8_SUBSTITUTE' )
			? JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
			: 0;
		$encoded = wp_json_encode( $parts, $options );

		if ( false === $encoded ) {
			$fallback = '';
			foreach ( $parts as $key => $value ) {
				$fallback .= (string) $key . ':' . ( is_scalar( $value ) ? (string) $value : '' ) . ';';
			}
			$encoded = $fallback;
		}

		$hash = md5( $encoded );

		return self::CACHE_PREFIX . $hash;
	}

	/**
	 * Get current cache version.
	 *
	 * @return int
	 */
	private function getCacheVersion(): int {
		if ( ! function_exists( 'get_option' ) ) {
			return 1;
		}

		$version = get_option( self::VERSION_OPTION, 1 );

		return is_numeric( $version ) ? (int) $version : 1;
	}

	/**
	 * Increment cache version to invalidate all result caches.
	 *
	 * @return void
	 */
	private function incrementCacheVersion(): void {
		if ( ! function_exists( 'update_option' ) ) {
			return;
		}

		$current = $this->getCacheVersion();
		update_option( self::VERSION_OPTION, $current + 1, false );
	}

	/**
	 * Format query summary.
	 *
	 * @param OrderQuery $query The query.
	 * @return array
	 */
	private function formatQuerySummary( OrderQuery $query ): array {
		$dateRange = null;

		if ( null !== $query->dateRange ) {
			$dateRange = array(
				'start' => $query->dateRange->start->format( 'Y-m-d H:i:s' ),
				'end'   => $query->dateRange->end->format( 'Y-m-d H:i:s' ),
			);
		}

		return array(
			'order_id'   => $query->orderId ?? 0,
			'email'      => $query->email ?? '',
			'status'     => $query->status ?? '',
			'limit'      => $query->limit,
			'offset'     => $query->offset,
			'date_range' => $dateRange,
		);
	}

	/**
	 * Get the count for a query.
	 *
	 * @param OrderQuery $query The query.
	 * @return int
	 */
	public function count( OrderQuery $query ): int {
		return $this->repository->count( $query );
	}

	/**
	 * Invalidate cache for an order.
	 *
	 * This clears the individual order cache and increments the cache version
	 * to invalidate all result caches that may contain this order's data.
	 *
	 * @param int $orderId The order ID.
	 * @return void
	 */
	public function invalidateOrder( int $orderId ): void {
		// Clear individual order cache.
		$this->orderCache->delete( (string) $orderId );

		// Increment version to invalidate all result caches.
		// This ensures search results don't serve stale order data.
		$this->incrementCacheVersion();
	}

	/**
	 * Invalidate all order caches.
	 *
	 * Use this when bulk operations affect multiple orders.
	 *
	 * @return void
	 */
	public function invalidateAll(): void {
		$this->incrementCacheVersion();
	}
}
