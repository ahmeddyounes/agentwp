<?php
/**
 * Transient cache interface.
 *
 * @package AgentWP\Contracts
 */

namespace AgentWP\Contracts;

/**
 * Contract for WordPress transient-style cache services.
 */
interface TransientCacheInterface {

	/**
	 * Retrieve a transient value.
	 *
	 * @param string $key Transient key.
	 * @return mixed|null Transient value or null if not found/expired.
	 */
	public function get( string $key ): mixed;

	/**
	 * Store a transient value.
	 *
	 * @param string $key        Transient key.
	 * @param mixed  $value      Value to store.
	 * @param int    $expiration Time until expiration in seconds.
	 * @return bool True on success, false on failure.
	 */
	public function set( string $key, mixed $value, int $expiration = 0 ): bool;

	/**
	 * Delete a transient.
	 *
	 * @param string $key Transient key.
	 * @return bool True on success, false on failure.
	 */
	public function delete( string $key ): bool;

	/**
	 * Check if a transient exists.
	 *
	 * @param string $key Transient key.
	 * @return bool True if exists and not expired, false otherwise.
	 */
	public function has( string $key ): bool;

	/**
	 * Get a transient value or compute and store it.
	 *
	 * @param string   $key        Transient key.
	 * @param callable $callback   Callback to compute value if not cached.
	 * @param int      $expiration Time until expiration in seconds.
	 * @return mixed The cached or computed value.
	 */
	public function remember( string $key, callable $callback, int $expiration = 0 ): mixed;

	/**
	 * Add a transient only if it doesn't already exist (atomic).
	 *
	 * This is crucial for implementing locks and preventing race conditions.
	 * Unlike set(), this will fail if the key already exists.
	 *
	 * @param string $key        Transient key.
	 * @param mixed  $value      Value to store.
	 * @param int    $expiration Time until expiration in seconds.
	 * @return bool True if added, false if key already exists.
	 */
	public function add( string $key, mixed $value, int $expiration = 0 ): bool;
}
