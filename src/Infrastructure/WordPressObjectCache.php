<?php
/**
 * WordPress object cache adapter.
 *
 * @package AgentWP\Infrastructure
 */

namespace AgentWP\Infrastructure;

use AgentWP\Contracts\CacheInterface;

/**
 * Wraps WordPress object cache functions.
 */
final class WordPressObjectCache implements CacheInterface {

	/**
	 * Cache group.
	 *
	 * @var string
	 */
	private string $group;

	/**
	 * Create a new WordPressObjectCache.
	 *
	 * @param string $group Cache group prefix.
	 */
	public function __construct( string $group = 'agentwp' ) {
		$this->group = $group;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get( string $key, mixed $default = null ): mixed {
		$value = wp_cache_get( $key, $this->group );

		if ( false === $value ) {
			return $default;
		}

		return $value;
	}

	/**
	 * {@inheritDoc}
	 */
	public function set( string $key, mixed $value, int $ttl = 0 ): bool {
		if ( $ttl > 0 && $ttl < 300 ) {
			$ttl = 300;
		}

		// phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- TTL is validated to be >= 300 seconds (or 0).
		return wp_cache_set( $key, $value, $this->group, $ttl );
	}

	/**
	 * {@inheritDoc}
	 */
	public function has( string $key ): bool {
		$found = false;
		wp_cache_get( $key, $this->group, false, $found );
		return $found;
	}

	/**
	 * {@inheritDoc}
	 */
	public function delete( string $key ): bool {
		return wp_cache_delete( $key, $this->group );
	}

	/**
	 * {@inheritDoc}
	 */
	public function flush(): bool {
		return wp_cache_flush();
	}

	/**
	 * {@inheritDoc}
	 */
	public function remember( string $key, callable $callback, int $ttl = 0 ): mixed {
		// Use atomic get with $found parameter to avoid TOCTOU race.
		$found = false;
		$value = wp_cache_get( $key, $this->group, false, $found );

		if ( $found ) {
			return $value;
		}

		$value = $callback();
		$this->set( $key, $value, $ttl );

		return $value;
	}
}
