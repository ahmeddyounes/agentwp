<?php
/**
 * WordPress transient cache adapter.
 *
 * @package AgentWP\Infrastructure
 */

namespace AgentWP\Infrastructure;

use AgentWP\Contracts\TransientCacheInterface;

/**
 * Wraps WordPress transient functions.
 */
final class WordPressTransientCache implements TransientCacheInterface {

	/**
	 * Key prefix.
	 *
	 * @var string
	 */
	private string $prefix;

	/**
	 * Create a new WordPressTransientCache.
	 *
	 * @param string $prefix Key prefix for all transients.
	 */
	public function __construct( string $prefix = 'agentwp_' ) {
		$this->prefix = $prefix;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get( string $key, mixed $default = null ): mixed {
		$raw = get_transient( $this->prefixKey( $key ) );

		// Check if it's our wrapped value.
		if ( is_array( $raw ) && isset( $raw['__wrapped__'] ) ) {
			return $raw['value'];
		}

		// For backward compatibility with non-wrapped values.
		if ( false !== $raw ) {
			return $raw;
		}

		return $default;
	}

	/**
	 * {@inheritDoc}
	 */
	public function set( string $key, mixed $value, int $expiration = 0 ): bool {
		// Wrap value to distinguish false from not-found.
		$wrapped = array(
			'__wrapped__' => true,
			'value'       => $value,
		);

		return set_transient( $this->prefixKey( $key ), $wrapped, $expiration );
	}

	/**
	 * {@inheritDoc}
	 */
	public function delete( string $key ): bool {
		return delete_transient( $this->prefixKey( $key ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function has( string $key ): bool {
		$raw = get_transient( $this->prefixKey( $key ) );

		// Check for wrapped value (new format).
		if ( is_array( $raw ) && isset( $raw['__wrapped__'] ) ) {
			return true;
		}

		// For backward compatibility - non-wrapped truthy values exist.
		return false !== $raw;
	}

	/**
	 * Lock timeout in seconds.
	 */
	private const LOCK_TIMEOUT = 30;

	/**
	 * Maximum lock acquisition attempts.
	 */
	private const MAX_LOCK_ATTEMPTS = 50;

	/**
	 * Delay between lock attempts in microseconds.
	 */
	private const LOCK_RETRY_DELAY_US = 20000;

	/**
	 * {@inheritDoc}
	 */
	public function remember( string $key, callable $callback, int $expiration = 0 ): mixed {
		$prefixed_key = $this->prefixKey( $key );

		// First check - fast path for existing values.
		$raw = get_transient( $prefixed_key );

		// Check for wrapped value (new format).
		if ( is_array( $raw ) && isset( $raw['__wrapped__'] ) ) {
			return $raw['value'];
		}

		// For backward compatibility - non-wrapped truthy values.
		if ( false !== $raw ) {
			return $raw;
		}

		// Cache miss - use locking to prevent stampede.
		$lockKey      = $prefixed_key . '_lock';
		$lockAcquired = false;

		for ( $i = 0; $i < self::MAX_LOCK_ATTEMPTS; $i++ ) {
			if ( $this->add( $key . '_lock', 1, self::LOCK_TIMEOUT ) ) {
				$lockAcquired = true;
				break;
			}

			// Wait and check if another process populated the cache.
			usleep( self::LOCK_RETRY_DELAY_US );
			$raw = get_transient( $prefixed_key );
			if ( is_array( $raw ) && isset( $raw['__wrapped__'] ) ) {
				return $raw['value'];
			}
			if ( false !== $raw ) {
				return $raw;
			}
		}

		// If we couldn't acquire lock, compute value anyway to prevent deadlock.
		// This is a tradeoff: possible duplicate work vs. guaranteed progress.
		try {
			// Double-check after acquiring lock.
			if ( $lockAcquired ) {
				$raw = get_transient( $prefixed_key );
				if ( is_array( $raw ) && isset( $raw['__wrapped__'] ) ) {
					return $raw['value'];
				}
				if ( false !== $raw ) {
					return $raw;
				}
			}

			$value = $callback();
			$this->set( $key, $value, $expiration );

			return $value;
		} finally {
			if ( $lockAcquired ) {
				$this->delete( $key . '_lock' );
			}
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function add( string $key, mixed $value, int $expiration = 0 ): bool {
		$prefixed_key = $this->prefixKey( $key );

		// Wrap value to distinguish false from not-found.
		$wrapped = array(
			'__wrapped__' => true,
			'value'       => $value,
		);

		// If object cache supports atomic add, use it.
		if ( wp_using_ext_object_cache() ) {
			// wp_cache_add returns false if key already exists.
			return wp_cache_add( $prefixed_key, $wrapped, 'transient', $expiration );
		}

		// For database-backed transients, check existence first then set.
		// This has a small race window but is the best we can do without object cache.
		$existing = get_transient( $prefixed_key );
		if ( false !== $existing ) {
			return false;
		}

		return set_transient( $prefixed_key, $wrapped, $expiration );
	}

	/**
	 * Prefix a key.
	 *
	 * @param string $key The key.
	 * @return string
	 */
	private function prefixKey( string $key ): string {
		return $this->prefix . $key;
	}
}
