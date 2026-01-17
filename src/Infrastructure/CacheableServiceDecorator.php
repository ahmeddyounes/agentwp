<?php
/**
 * Cacheable service decorator.
 *
 * @package AgentWP\Infrastructure
 */

namespace AgentWP\Infrastructure;

use AgentWP\Contracts\CacheInterface;

/**
 * Decorator that adds caching capabilities to any service.
 *
 * Usage:
 * ```php
 * $decorator = new CacheableServiceDecorator(
 *     $service,
 *     $cache,
 *     3600
 * );
 * $result = $decorator->cached('my_key', fn() => $service->expensiveOperation());
 * ```
 *
 * Follows the Decorator pattern for DRY principle compliance.
 */
final class CacheableServiceDecorator {

	/**
	 * The decorated service.
	 *
	 * @var object
	 */
	private object $service;

	/**
	 * Cache implementation.
	 *
	 * @var CacheInterface
	 */
	private CacheInterface $cache;

	/**
	 * Default TTL in seconds.
	 *
	 * @var int
	 */
	private int $defaultTtl;

	/**
	 * Cache key prefix.
	 *
	 * @var string
	 */
	private string $keyPrefix;

	/**
	 * Create a new CacheableServiceDecorator.
	 *
	 * @param object         $service    The service to decorate.
	 * @param CacheInterface $cache      Cache implementation.
	 * @param int            $defaultTtl Default TTL in seconds (default: 3600).
	 * @param string         $keyPrefix  Optional prefix for cache keys.
	 */
	public function __construct(
		object $service,
		CacheInterface $cache,
		int $defaultTtl = 3600,
		string $keyPrefix = ''
	) {
		$this->service    = $service;
		$this->cache      = $cache;
		$this->defaultTtl = $defaultTtl;
		$this->keyPrefix  = $keyPrefix;
	}

	/**
	 * Execute a callback with caching.
	 *
	 * @param string   $key      Cache key.
	 * @param callable $callback Callback to execute if cache miss.
	 * @param int|null $ttl      Optional TTL override.
	 * @return mixed Result from callback or cache.
	 */
	public function cached( string $key, callable $callback, ?int $ttl = null ) {
		$fullKey = $this->buildKey( $key );

		$cached = $this->cache->get( $fullKey );
		if ( null !== $cached ) {
			return $cached;
		}

		$result = $callback();
		$this->cache->set( $fullKey, $result, $ttl ?? $this->defaultTtl );

		return $result;
	}

	/**
	 * Get the decorated service.
	 *
	 * @return object
	 */
	public function getService(): object {
		return $this->service;
	}

	/**
	 * Build a full cache key with prefix.
	 *
	 * @param string $key Base cache key.
	 * @return string Full cache key.
	 */
	private function buildKey( string $key ): string {
		if ( '' === $this->keyPrefix ) {
			return $key;
		}

		return $this->keyPrefix . ':' . $key;
	}

	/**
	 * Clear cache for a specific key.
	 *
	 * @param string $key Cache key.
	 * @return void
	 */
	public function clear( string $key ): void {
		$this->cache->delete( $this->buildKey( $key ) );
	}

	/**
	 * Clear all cache with the configured prefix.
	 *
	 * Note: This requires the cache implementation to support prefix-based deletion.
	 *
	 * @return void
	 */
	public function clearAll(): void {
		if ( method_exists( $this->cache, 'deleteByPrefix' ) ) {
			$this->cache->deleteByPrefix( $this->keyPrefix );
		}
	}

	/**
	 * Generate a cache key from parameters.
	 *
	 * Useful for caching method calls with arguments.
	 *
	 * @param string $method Method name.
	 * @param array  $params Method parameters.
	 * @return string Generated cache key.
	 */
	public function keyFrom( string $method, array $params = [] ): string {
		$parts = array_merge( [ $method ], $params );

		$options = defined( 'JSON_INVALID_UTF8_SUBSTITUTE' )
			? JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
			: 0;
		$encoded = wp_json_encode( $parts, $options );

		if ( false === $encoded ) {
			// Fallback: concatenate params.
			$encoded = implode( ':', array_map( 'strval', $parts ) );
		}

		return md5( $encoded );
	}

	/**
	 * Cache a method call on the service.
	 *
	 * @param string   $method Method name.
	 * @param array    $params Method parameters.
	 * @param int|null $ttl    Optional TTL override.
	 * @return mixed Method result.
	 */
	public function cacheMethod( string $method, array $params = [], ?int $ttl = null ) {
		$key = $this->keyFrom( $method, $params );

		return $this->cached( $key, function () use ( $method, $params ) {
			if ( ! method_exists( $this->service, $method ) ) {
				throw new \BadMethodCallException(
					sprintf( 'Method %s does not exist on service.', $method ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Safe in exception message context.
				);
			}

			return call_user_func_array( [ $this->service, $method ], $params );
		}, $ttl );
	}

	/**
	 * Forward all other method calls to the decorated service.
	 *
	 * @param string $method Method name.
	 * @param array  $params Method parameters.
	 * @return mixed Method result.
	 */
	public function __call( string $method, array $params ) {
		if ( ! method_exists( $this->service, $method ) ) {
			throw new \BadMethodCallException(
				sprintf( 'Method %s does not exist on service.', $method ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Safe in exception message context.
			);
		}

		return call_user_func_array( [ $this->service, $method ], $params );
	}

	/**
	 * Forward property access to the decorated service.
	 *
	 * @param string $property Property name.
	 * @return mixed Property value.
	 */
	public function __get( string $property ) {
		if ( ! property_exists( $this->service, $property ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'Property %s does not exist on service.', $property ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Safe in exception message context.
			);
		}

		return $this->service->$property;
	}
}
