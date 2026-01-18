<?php
/**
 * Rate limiter implementation.
 *
 * @package AgentWP\Infrastructure\RateLimiting
 */

namespace AgentWP\Infrastructure\RateLimiting;

use AgentWP\Config\AgentWPConfig;
use AgentWP\Contracts\ClockInterface;
use AgentWP\Contracts\RateLimiterInterface;
use AgentWP\Contracts\TransientCacheInterface;

/**
 * Transient-based rate limiter for API requests.
 */
final class RateLimiter implements RateLimiterInterface {

	/**
	 * Default rate limit per window.
	 */
	public const DEFAULT_LIMIT = 30;

	/**
	 * Default window duration in seconds.
	 */
	public const DEFAULT_WINDOW = 60;

	/**
	 * Lock timeout in seconds.
	 *
	 * @var int
	 */
	private int $lockTimeout;

	/**
	 * Maximum lock acquisition attempts.
	 *
	 * @var int
	 */
	private int $maxLockAttempts;

	/**
	 * Delay between lock attempts in microseconds.
	 *
	 * @var int
	 */
	private int $lockRetryDelayUs;

	/**
	 * Transient cache.
	 *
	 * @var TransientCacheInterface
	 */
	private TransientCacheInterface $cache;

	/**
	 * Clock for time operations.
	 *
	 * @var ClockInterface
	 */
	private ClockInterface $clock;

	/**
	 * Maximum requests per window.
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
	 * Key prefix.
	 *
	 * @var string
	 */
	private string $prefix;

	/**
	 * Create a new RateLimiter.
	 *
	 * Lock settings are read from AgentWPConfig with filter support.
	 * Operators can tune via filters:
	 * - 'agentwp_config_rate_limit_lock_timeout' (int): Lock timeout in seconds (default 5)
	 * - 'agentwp_config_rate_limit_lock_attempts' (int): Max lock attempts (default 10)
	 * - 'agentwp_config_rate_limit_lock_delay_us' (int): Delay between attempts in μs (default 10000)
	 *
	 * @param TransientCacheInterface $cache  Transient cache.
	 * @param ClockInterface          $clock  Clock for time operations.
	 * @param int                     $limit  Maximum requests per window.
	 * @param int                     $window Window duration in seconds.
	 * @param string                  $prefix Key prefix.
	 */
	public function __construct(
		TransientCacheInterface $cache,
		ClockInterface $clock,
		int $limit = self::DEFAULT_LIMIT,
		int $window = self::DEFAULT_WINDOW,
		string $prefix = 'rate_'
	) {
		$this->cache  = $cache;
		$this->clock  = $clock;
		$this->limit  = $limit;
		$this->window = $window;
		$this->prefix = $prefix;

		// Load lock settings from centralized config with filter support.
		$this->lockTimeout      = (int) AgentWPConfig::get( 'rate_limit.lock_timeout', AgentWPConfig::RATE_LIMIT_LOCK_TIMEOUT );
		$this->maxLockAttempts  = (int) AgentWPConfig::get( 'rate_limit.lock_attempts', AgentWPConfig::RATE_LIMIT_LOCK_ATTEMPTS );
		$this->lockRetryDelayUs = (int) AgentWPConfig::get( 'rate_limit.lock_delay_us', AgentWPConfig::RATE_LIMIT_LOCK_DELAY_US );
	}

	/**
	 * {@inheritDoc}
	 */
	public function check( int $userId ): bool {
		$bucket = $this->getBucket( $userId );

		return $bucket['count'] < $this->limit;
	}

	/**
	 * {@inheritDoc}
	 */
	public function increment( int $userId ): void {
		$bucket = $this->getBucket( $userId );

		$bucket['count'] = $bucket['count'] + 1;

		$this->cache->set(
			$this->getKey( $userId ),
			$bucket,
			$this->window
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function getRetryAfter( int $userId ): int {
		$bucket = $this->getBucket( $userId );
		$now    = $this->clock->timestamp();
		$elapsed = $now - $bucket['start'];

		return max( 1, $this->window - $elapsed );
	}

	/**
	 * {@inheritDoc}
	 */
	public function getRemaining( int $userId ): int {
		$bucket = $this->getBucket( $userId );

		return max( 0, $this->limit - $bucket['count'] );
	}

	/**
	 * Check rate limit and increment if within limits (atomic operation).
	 *
	 * This method uses a lock to perform an atomic check-and-increment to prevent
	 * race conditions where multiple concurrent requests could exceed the rate limit.
	 *
	 * Fail-open design: If storage is unavailable or lock cannot be acquired,
	 * the request is allowed to proceed to avoid blocking legitimate traffic.
	 *
	 * @param int $userId The user ID.
	 * @return bool True if within limits (and incremented), false if exceeded.
	 */
	public function checkAndIncrement( int $userId ): bool {
		$key     = $this->getKey( $userId );
		$lockKey = $key . '_lock';

		// Try to acquire lock with retry.
		$lockAcquired = false;
		try {
			for ( $i = 0; $i < $this->maxLockAttempts; $i++ ) {
				if ( $this->cache->add( $lockKey, 1, $this->lockTimeout ) ) {
					$lockAcquired = true;
					break;
				}
				usleep( $this->lockRetryDelayUs );
			}
		} catch ( \Throwable $e ) {
			// Storage unavailable - fail open to avoid blocking legitimate traffic.
			return true;
		}

		// If we couldn't acquire lock after retries, fail open.
		// Under high contention, it's better to occasionally exceed limits
		// than to incorrectly deny legitimate requests.
		if ( ! $lockAcquired ) {
			return true;
		}

		try {
			$now    = $this->clock->timestamp();
			$bucket = $this->cache->get( $key );

			// Initialize or reset expired bucket.
			if ( ! is_array( $bucket ) || $now - (int) ( $bucket['start'] ?? 0 ) >= $this->window ) {
				$bucket = array(
					'start' => $now,
					'count' => 0,
				);
			}

			// Check if already at limit.
			if ( (int) ( $bucket['count'] ?? 0 ) >= $this->limit ) {
				return false;
			}

			// Increment and store.
			$bucket['count'] = (int) ( $bucket['count'] ?? 0 ) + 1;
			$this->cache->set( $key, $bucket, $this->window );

			return true;
		} catch ( \Throwable $e ) {
			// Storage unavailable during read/write - fail open.
			return true;
		} finally {
			// Always release the lock if we acquired it.
			if ( $lockAcquired ) {
				try {
					$this->cache->delete( $lockKey );
				} catch ( \Throwable $e ) {
					// Ignore lock release failures - lock will expire via timeout.
				}
			}
		}
	}

	/**
	 * Reset the rate limit for a user.
	 *
	 * @param int $userId The user ID.
	 * @return void
	 */
	public function reset( int $userId ): void {
		$this->cache->delete( $this->getKey( $userId ) );
	}

	/**
	 * Get the rate limit bucket for a user.
	 *
	 * @param int $userId The user ID.
	 * @return array{start: int, count: int}
	 */
	private function getBucket( int $userId ): array {
		$key    = $this->getKey( $userId );
		$bucket = $this->cache->get( $key );
		$now    = $this->clock->timestamp();

		if ( ! is_array( $bucket ) ) {
			return array(
				'start' => $now,
				'count' => 0,
			);
		}

		// Reset if window has expired.
		if ( $now - (int) $bucket['start'] >= $this->window ) {
			return array(
				'start' => $now,
				'count' => 0,
			);
		}

		return array(
			'start' => (int) $bucket['start'],
			'count' => (int) $bucket['count'],
		);
	}

	/**
	 * Get the cache key for a user.
	 *
	 * @param int $userId The user ID.
	 * @return string
	 */
	private function getKey( int $userId ): string {
		return $this->prefix . $userId;
	}

	/**
	 * Get the configured limit.
	 *
	 * @return int
	 */
	public function getLimit(): int {
		return $this->limit;
	}

	/**
	 * Get the configured window.
	 *
	 * @return int
	 */
	public function getWindow(): int {
		return $this->window;
	}
}
