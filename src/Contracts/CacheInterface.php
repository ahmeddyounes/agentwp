<?php
/**
 * Cache interface.
 *
 * @package AgentWP\Contracts
 */

namespace AgentWP\Contracts;

/**
 * Contract for object cache services.
 */
interface CacheInterface {

	/**
	 * Retrieve a cached value.
	 *
	 * @param string $key     Cache key.
	 * @param mixed  $default Default value if not found.
	 * @return mixed Cached value or default if not found.
	 */
	public function get( string $key, mixed $default = null ): mixed;

	/**
	 * Store a value in cache.
	 *
	 * @param string $key   Cache key.
	 * @param mixed  $value Value to cache.
	 * @param int    $ttl   Time to live in seconds (0 = no expiration).
	 * @return bool True on success, false on failure.
	 */
	public function set( string $key, mixed $value, int $ttl = 0 ): bool;

	/**
	 * Delete a cached value.
	 *
	 * @param string $key Cache key.
	 * @return bool True on success, false on failure.
	 */
	public function delete( string $key ): bool;

	/**
	 * Check if a key exists in cache.
	 *
	 * @param string $key Cache key.
	 * @return bool True if exists, false otherwise.
	 */
	public function has( string $key ): bool;

	/**
	 * Clear all cached values.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function flush(): bool;

	/**
	 * Get a cached value or compute and store it.
	 *
	 * @param string   $key      Cache key.
	 * @param callable $callback Callback to compute value if not cached.
	 * @param int      $ttl      Time to live in seconds (0 = no expiration).
	 * @return mixed The cached or computed value.
	 */
	public function remember( string $key, callable $callback, int $ttl = 0 ): mixed;
}
