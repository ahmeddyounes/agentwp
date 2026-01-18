<?php
/**
 * Fake transient cache for testing.
 *
 * @package AgentWP\Tests\Fakes
 */

namespace AgentWP\Tests\Fakes;

use AgentWP\Contracts\TransientCacheInterface;

/**
 * In-memory transient cache implementation for testing.
 */
final class FakeTransientCache implements TransientCacheInterface {

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
	 * Whether to simulate storage failures.
	 *
	 * @var bool
	 */
	private bool $simulateFailure = false;

	/**
	 * Whether to simulate lock contention (add() always fails).
	 *
	 * @var bool
	 */
	private bool $simulateLockContention = false;

	/**
	 * {@inheritDoc}
	 */
	public function get( string $key, mixed $default = null ): mixed {
		if ( $this->simulateFailure ) {
			throw new \RuntimeException( 'Simulated cache failure' );
		}

		if ( ! isset( $this->store[ $key ] ) ) {
			return $default;
		}

		// Check expiration.
		if ( isset( $this->expirations[ $key ] ) ) {
			$now = $this->currentTime ?? time();
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
	public function set( string $key, mixed $value, int $expiration = 0 ): bool {
		if ( $this->simulateFailure ) {
			throw new \RuntimeException( 'Simulated cache failure' );
		}

		$this->store[ $key ] = $value;

		if ( $expiration > 0 ) {
			$now                        = $this->currentTime ?? time();
			$this->expirations[ $key ] = $now + $expiration;
		} else {
			unset( $this->expirations[ $key ] );
		}

		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function delete( string $key ): bool {
		if ( $this->simulateFailure ) {
			throw new \RuntimeException( 'Simulated cache failure' );
		}

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
	public function remember( string $key, callable $callback, int $expiration = 0 ): mixed {
		$value = $this->get( $key );

		if ( null !== $value ) {
			return $value;
		}

		$value = $callback();
		$this->set( $key, $value, $expiration );

		return $value;
	}

	/**
	 * {@inheritDoc}
	 */
	public function add( string $key, mixed $value, int $expiration = 0 ): bool {
		if ( $this->simulateFailure ) {
			throw new \RuntimeException( 'Simulated cache failure' );
		}

		if ( $this->simulateLockContention ) {
			return false;
		}

		// Check if key already exists and is not expired.
		if ( isset( $this->store[ $key ] ) ) {
			// Check expiration.
			if ( isset( $this->expirations[ $key ] ) ) {
				$now = $this->currentTime ?? time();
				if ( $this->expirations[ $key ] > $now ) {
					return false; // Key exists and not expired.
				}
				// Key expired, clean it up.
				unset( $this->store[ $key ], $this->expirations[ $key ] );
			} else {
				return false; // Key exists with no expiration.
			}
		}

		return $this->set( $key, $value, $expiration );
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
		$this->store                 = array();
		$this->expirations           = array();
		$this->currentTime           = null;
		$this->simulateFailure       = false;
		$this->simulateLockContention = false;
	}

	/**
	 * Enable or disable failure simulation.
	 *
	 * @param bool $fail Whether to simulate failures.
	 * @return void
	 */
	public function setSimulateFailure( bool $fail ): void {
		$this->simulateFailure = $fail;
	}

	/**
	 * Enable or disable lock contention simulation.
	 *
	 * @param bool $contention Whether to simulate lock contention.
	 * @return void
	 */
	public function setSimulateLockContention( bool $contention ): void {
		$this->simulateLockContention = $contention;
	}
}
