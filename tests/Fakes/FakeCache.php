<?php
/**
 * Fake cache for testing.
 *
 * @package AgentWP\Tests\Fakes
 */

namespace AgentWP\Tests\Fakes;

use AgentWP\Contracts\CacheInterface;

/**
 * In-memory cache implementation for testing.
 */
final class FakeCache implements CacheInterface {

	/**
	 * Cached values.
	 *
	 * @var array<string, mixed>
	 */
	private array $store = array();

	/**
	 * Expiration timestamps.
	 *
	 * @var array<string, int>
	 */
	private array $expirations = array();

	/**
	 * Current time for testing.
	 *
	 * @var int|null
	 */
	private ?int $currentTime = null;

	/**
	 * {@inheritDoc}
	 */
	public function get( string $key, mixed $default = null ): mixed {
		if ( ! isset( $this->store[ $key ] ) ) {
			return $default;
		}

		// Check expiration.
		if ( isset( $this->expirations[ $key ] ) ) {
			$now = $this->currentTime ?? time();
			// Use <= so items are expired AT their expiration time, not just after.
			if ( $this->expirations[ $key ] <= $now ) {
				unset( $this->store[ $key ], $this->expirations[ $key ] );
				return $default;
			}
		}

		return $this->store[ $key ];
	}

	/**
	 * {@inheritDoc}
	 */
	public function set( string $key, mixed $value, int $ttl = 0 ): bool {
		$this->store[ $key ] = $value;

		if ( $ttl > 0 ) {
			$now                        = $this->currentTime ?? time();
			$this->expirations[ $key ] = $now + $ttl;
		} else {
			unset( $this->expirations[ $key ] );
		}

		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function delete( string $key ): bool {
		unset( $this->store[ $key ], $this->expirations[ $key ] );
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function has( string $key ): bool {
		return null !== $this->get( $key );
	}

	/**
	 * {@inheritDoc}
	 */
	public function flush(): bool {
		$this->store       = array();
		$this->expirations = array();
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function remember( string $key, callable $callback, int $ttl = 0 ): mixed {
		$value = $this->get( $key );

		if ( null !== $value ) {
			return $value;
		}

		$value = $callback();
		$this->set( $key, $value, $ttl );

		return $value;
	}

	// Test helpers.

	/**
	 * Get all stored values (for assertions).
	 *
	 * @return array<string, mixed>
	 */
	public function getAll(): array {
		return $this->store;
	}

	/**
	 * Get the count of stored items.
	 *
	 * @return int
	 */
	public function count(): int {
		return count( $this->store );
	}

	/**
	 * Set the current time for expiration testing.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return void
	 */
	public function setCurrentTime( int $timestamp ): void {
		$this->currentTime = $timestamp;
	}

	/**
	 * Advance time by seconds (for expiration testing).
	 *
	 * @param int $seconds Seconds to advance.
	 * @return void
	 */
	public function advanceTime( int $seconds ): void {
		if ( null === $this->currentTime ) {
			$this->currentTime = time();
		}
		$this->currentTime += $seconds;
	}

	/**
	 * Reset the cache.
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->store       = array();
		$this->expirations = array();
		$this->currentTime = null;
	}
}
