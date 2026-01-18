<?php
/**
 * Fake rate limiter for testing.
 *
 * @package AgentWP\Tests\Fakes
 */

namespace AgentWP\Tests\Fakes;

use AgentWP\Contracts\AtomicRateLimiterInterface;

/**
 * In-memory rate limiter for testing.
 */
final class FakeRateLimiter implements AtomicRateLimiterInterface {

	/**
	 * Request counts per user.
	 *
	 * @var array<int, int>
	 */
	private array $counts = array();

	/**
	 * Start times per user.
	 *
	 * @var array<int, int>
	 */
	private array $starts = array();

	/**
	 * Rate limit.
	 *
	 * @var int
	 */
	private int $limit;

	/**
	 * Window duration in seconds.
	 *
	 * @var int
	 */
	private int $window;

	/**
	 * Current time (for testing).
	 *
	 * @var int
	 */
	private int $currentTime;

	/**
	 * Whether rate limiting is enabled.
	 *
	 * @var bool
	 */
	private bool $enabled = true;

	/**
	 * Create a new FakeRateLimiter.
	 *
	 * @param int $limit  Rate limit.
	 * @param int $window Window duration in seconds.
	 */
	public function __construct( int $limit = 30, int $window = 60 ) {
		$this->limit       = $limit;
		$this->window      = $window;
		$this->currentTime = time();
	}

	/**
	 * {@inheritDoc}
	 */
	public function check( int $userId ): bool {
		if ( ! $this->enabled ) {
			return true;
		}

		$this->maybeResetWindow( $userId );

		return ( $this->counts[ $userId ] ?? 0 ) < $this->limit;
	}

	/**
	 * {@inheritDoc}
	 */
	public function increment( int $userId ): void {
		$this->maybeResetWindow( $userId );

		if ( ! isset( $this->counts[ $userId ] ) ) {
			$this->counts[ $userId ] = 0;
			$this->starts[ $userId ] = $this->currentTime;
		}

		$this->counts[ $userId ]++;
	}

	/**
	 * {@inheritDoc}
	 */
	public function checkAndIncrement( int $userId ): bool {
		if ( ! $this->enabled ) {
			return true;
		}

		$this->maybeResetWindow( $userId );

		// Check if already at limit.
		if ( ( $this->counts[ $userId ] ?? 0 ) >= $this->limit ) {
			return false;
		}

		// Increment and allow.
		if ( ! isset( $this->counts[ $userId ] ) ) {
			$this->counts[ $userId ] = 0;
			$this->starts[ $userId ] = $this->currentTime;
		}

		$this->counts[ $userId ]++;

		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function getRetryAfter( int $userId ): int {
		$start   = $this->starts[ $userId ] ?? $this->currentTime;
		$elapsed = $this->currentTime - $start;

		return max( 1, $this->window - $elapsed );
	}

	/**
	 * {@inheritDoc}
	 */
	public function getRemaining( int $userId ): int {
		$this->maybeResetWindow( $userId );

		$count = $this->counts[ $userId ] ?? 0;

		return max( 0, $this->limit - $count );
	}

	/**
	 * Maybe reset the window if expired.
	 *
	 * @param int $userId The user ID.
	 * @return void
	 */
	private function maybeResetWindow( int $userId ): void {
		if ( ! isset( $this->starts[ $userId ] ) ) {
			return;
		}

		$elapsed = $this->currentTime - $this->starts[ $userId ];

		if ( $elapsed >= $this->window ) {
			$this->counts[ $userId ] = 0;
			$this->starts[ $userId ] = $this->currentTime;
		}
	}

	// Test helpers.

	/**
	 * Set the current time.
	 *
	 * @param int $time Unix timestamp.
	 * @return void
	 */
	public function setCurrentTime( int $time ): void {
		$this->currentTime = $time;
	}

	/**
	 * Advance time by seconds.
	 *
	 * @param int $seconds Seconds to advance.
	 * @return void
	 */
	public function advanceTime( int $seconds ): void {
		$this->currentTime += $seconds;
	}

	/**
	 * Get the count for a user.
	 *
	 * @param int $userId The user ID.
	 * @return int
	 */
	public function getCount( int $userId ): int {
		return $this->counts[ $userId ] ?? 0;
	}

	/**
	 * Reset a user's rate limit.
	 *
	 * @param int $userId The user ID.
	 * @return void
	 */
	public function reset( int $userId ): void {
		unset( $this->counts[ $userId ] );
		unset( $this->starts[ $userId ] );
	}

	/**
	 * Reset all rate limits.
	 *
	 * @return void
	 */
	public function resetAll(): void {
		$this->counts = array();
		$this->starts = array();
	}

	/**
	 * Disable rate limiting.
	 *
	 * @return void
	 */
	public function disable(): void {
		$this->enabled = false;
	}

	/**
	 * Enable rate limiting.
	 *
	 * @return void
	 */
	public function enable(): void {
		$this->enabled = true;
	}

	/**
	 * Set the limit for a user to a specific value.
	 *
	 * @param int $userId The user ID.
	 * @param int $count  Request count.
	 * @return void
	 */
	public function setCount( int $userId, int $count ): void {
		$this->counts[ $userId ] = $count;

		if ( ! isset( $this->starts[ $userId ] ) ) {
			$this->starts[ $userId ] = $this->currentTime;
		}
	}

	/**
	 * Exhaust the rate limit for a user.
	 *
	 * @param int $userId The user ID.
	 * @return void
	 */
	public function exhaust( int $userId ): void {
		$this->setCount( $userId, $this->limit );
	}
}
