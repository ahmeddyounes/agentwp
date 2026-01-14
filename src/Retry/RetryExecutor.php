<?php
/**
 * Retry executor.
 *
 * @package AgentWP\Retry
 */

namespace AgentWP\Retry;

use AgentWP\Contracts\RetryPolicyInterface;
use AgentWP\Contracts\SleeperInterface;
use AgentWP\DTO\HttpResponse;

/**
 * Executes operations with retry logic.
 */
final class RetryExecutor {

	/**
	 * Retry policy.
	 *
	 * @var RetryPolicyInterface
	 */
	private RetryPolicyInterface $policy;

	/**
	 * Sleeper for delays.
	 *
	 * @var SleeperInterface
	 */
	private SleeperInterface $sleeper;

	/**
	 * Callback for retry events.
	 *
	 * @var callable|null
	 */
	private $onRetry;

	/**
	 * Create a new RetryExecutor.
	 *
	 * @param RetryPolicyInterface $policy  Retry policy.
	 * @param SleeperInterface     $sleeper Sleeper for delays.
	 */
	public function __construct( RetryPolicyInterface $policy, SleeperInterface $sleeper ) {
		$this->policy  = $policy;
		$this->sleeper = $sleeper;
		$this->onRetry = null;
	}

	/**
	 * Set callback for retry events.
	 *
	 * @param callable $callback Callback receiving (attempt, delay, result).
	 * @return self
	 */
	public function onRetry( callable $callback ): self {
		$this->onRetry = $callback;
		return $this;
	}

	/**
	 * Execute an operation with retries.
	 *
	 * @param callable $operation Operation to execute.
	 * @return mixed The result of the operation.
	 * @throws RetryExhaustedException If all retries are exhausted.
	 */
	public function execute( callable $operation ): mixed {
		$attempt = 0;
		$lastResult = null;
		$lastException = null;

		while ( $attempt <= $this->policy->getMaxRetries() ) {
			try {
				$result = $operation();

				// If operation succeeded and result indicates success, return.
				if ( $this->isSuccess( $result ) ) {
					return $result;
				}

				$lastResult = $result;

				// Check if we should retry.
				if ( ! $this->policy->shouldRetry( $attempt, $result ) ) {
					return $result;
				}

			} catch ( \Throwable $e ) {
				$lastException = $e;
				$lastResult    = $e;

				// Check if exception is retryable.
				if ( ! $this->policy->shouldRetry( $attempt, $e ) ) {
					throw $e;
				}
			}

			// Get delay before next retry.
			$delayMs = $this->policy->getDelayMs( $attempt, $this->extractRetryAfter( $lastResult ) );

			// Notify retry callback.
			if ( null !== $this->onRetry ) {
				( $this->onRetry )( $attempt, $delayMs, $lastResult );
			}

			// Sleep before retry.
			$this->sleeper->sleepMs( $delayMs );

			++$attempt;
		}

			// All retries exhausted.
			if ( null !== $lastException ) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not output; callers must escape when rendering.
				throw new RetryExhaustedException(
					sprintf(
						'All %d retries exhausted. Last error: %s',
						$this->policy->getMaxRetries(),
						$lastException->getMessage()
					),
					$attempt,
					$lastException
				);
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}

		// Return last result if no exception.
		return $lastResult;
	}

	/**
	 * Execute with custom result check.
	 *
	 * @param callable $operation    Operation to execute.
	 * @param callable $successCheck Check if result is successful (receives result, returns bool).
	 * @return mixed
	 */
	public function executeWithCheck( callable $operation, callable $successCheck ): mixed {
		$attempt = 0;
		$lastResult = null;
		$lastException = null;

		while ( $attempt <= $this->policy->getMaxRetries() ) {
			try {
				$result = $operation();

				if ( $successCheck( $result ) ) {
					return $result;
				}

				$lastResult = $result;

				if ( ! $this->policy->shouldRetry( $attempt, $result ) ) {
					return $result;
				}
			} catch ( \Throwable $e ) {
				$lastException = $e;
				$lastResult    = $e;

				// Check if exception is retryable.
				if ( ! $this->policy->shouldRetry( $attempt, $e ) ) {
					throw $e;
				}
			}

			// Extract retry-after from result for rate limiting.
			$delayMs = $this->policy->getDelayMs( $attempt, $this->extractRetryAfter( $lastResult ) );

			if ( null !== $this->onRetry ) {
				( $this->onRetry )( $attempt, $delayMs, $lastResult );
			}

			$this->sleeper->sleepMs( $delayMs );

			++$attempt;
		}

			// All retries exhausted - throw if we have an exception.
			if ( null !== $lastException ) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not output; callers must escape when rendering.
				throw new RetryExhaustedException(
					sprintf(
						'All %d retries exhausted. Last error: %s',
						$this->policy->getMaxRetries(),
						$lastException->getMessage()
					),
					$attempt,
					$lastException
				);
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}

			return $lastResult;
		}

	/**
	 * Check if result indicates success.
	 *
	 * @param mixed $result The result.
	 * @return bool
	 */
	private function isSuccess( mixed $result ): bool {
		if ( $result instanceof HttpResponse ) {
			return $result->success;
		}

		if ( is_bool( $result ) ) {
			return $result;
		}

		// Non-null result is considered success.
		return null !== $result;
	}

	/**
	 * Extract Retry-After value from result.
	 *
	 * @param mixed $result The result.
	 * @return int|null
	 */
	private function extractRetryAfter( mixed $result ): ?int {
		if ( ! ( $result instanceof HttpResponse ) ) {
			return null;
		}

		$retryAfter = $result->headers['retry-after'] ?? $result->headers['Retry-After'] ?? null;

		if ( null === $retryAfter ) {
			return null;
		}

		// Could be a timestamp or seconds.
		if ( is_numeric( $retryAfter ) ) {
			$value = (int) $retryAfter;

			// If it's a large number (Unix timestamp after Sep 2001), it's a timestamp.
			// Any reasonable Retry-After in seconds would be much smaller (typically <7200).
			if ( $value > 1000000000 ) {
				return max( 0, $value - time() );
			}

			return max( 0, $value );
		}

		// Try to parse as HTTP date.
		// HTTP dates are always in GMT/UTC, so parse with UTC context.
		try {
			$date = new \DateTimeImmutable( $retryAfter, new \DateTimeZone( 'UTC' ) );
			$now = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
			return max( 0, $date->getTimestamp() - $now->getTimestamp() );
		} catch ( \Exception $e ) {
			// Invalid date format.
		}

		return null;
	}
}
