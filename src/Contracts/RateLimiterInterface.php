<?php
/**
 * Rate limiter interface.
 *
 * @package AgentWP\Contracts
 */

namespace AgentWP\Contracts;

/**
 * Contract for rate limiting services.
 */
interface RateLimiterInterface {

	/**
	 * Check if a user is within rate limits.
	 *
	 * @param int $userId The user ID to check.
	 * @return bool True if within limits, false if exceeded.
	 */
	public function check( int $userId ): bool;

	/**
	 * Increment the request count for a user.
	 *
	 * @param int $userId The user ID.
	 * @return void
	 */
	public function increment( int $userId ): void;

	/**
	 * Get the number of seconds until rate limit resets.
	 *
	 * @param int $userId The user ID.
	 * @return int Seconds until reset.
	 */
	public function getRetryAfter( int $userId ): int;

	/**
	 * Get remaining requests for a user.
	 *
	 * @param int $userId The user ID.
	 * @return int Remaining requests in current window.
	 */
	public function getRemaining( int $userId ): int;
}
