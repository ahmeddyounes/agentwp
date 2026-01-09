<?php
/**
 * Retry policy interface.
 *
 * @package AgentWP\Contracts
 */

namespace AgentWP\Contracts;

/**
 * Contract for retry policy implementations.
 */
interface RetryPolicyInterface {

	/**
	 * Determine if the operation should be retried.
	 *
	 * @param int   $attempt Current attempt number (0-indexed).
	 * @param mixed $result  The result of the operation.
	 * @return bool True if should retry, false otherwise.
	 */
	public function shouldRetry( int $attempt, mixed $result ): bool;

	/**
	 * Get the delay before the next retry in milliseconds.
	 *
	 * @param int      $attempt          Current attempt number.
	 * @param int|null $retryAfterHeader Optional Retry-After header value in seconds.
	 * @return int Delay in milliseconds.
	 */
	public function getDelayMs( int $attempt, ?int $retryAfterHeader = null ): int;

	/**
	 * Get the maximum number of retries.
	 *
	 * @return int Maximum retries.
	 */
	public function getMaxRetries(): int;
}
