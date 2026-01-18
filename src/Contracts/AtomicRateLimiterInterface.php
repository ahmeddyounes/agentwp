<?php
/**
 * Atomic rate limiter interface.
 *
 * @package AgentWP\Contracts
 */

namespace AgentWP\Contracts;

/**
 * Contract for rate limiting services with atomic check-and-increment.
 *
 * Extends RateLimiterInterface with an atomic operation that combines
 * checking and incrementing to prevent race conditions.
 */
interface AtomicRateLimiterInterface extends RateLimiterInterface {

	/**
	 * Check rate limit and increment if within limits (atomic operation).
	 *
	 * This method atomically checks whether the user is within rate limits
	 * and increments the counter if they are. This prevents race conditions
	 * where multiple concurrent requests could exceed the rate limit.
	 *
	 * @param int $userId The user ID.
	 * @return bool True if within limits (and incremented), false if exceeded.
	 */
	public function checkAndIncrement( int $userId ): bool;
}
