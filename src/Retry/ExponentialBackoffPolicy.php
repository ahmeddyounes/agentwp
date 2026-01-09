<?php
/**
 * Exponential backoff retry policy.
 *
 * @package AgentWP\Retry
 */

namespace AgentWP\Retry;

use AgentWP\Contracts\RetryPolicyInterface;
use AgentWP\DTO\HttpResponse;

/**
 * Implements exponential backoff with jitter for retry delays.
 */
final class ExponentialBackoffPolicy implements RetryPolicyInterface {

	/**
	 * Maximum number of retries.
	 *
	 * @var int
	 */
	private int $maxRetries;

	/**
	 * Base delay in milliseconds.
	 *
	 * @var int
	 */
	private int $baseDelayMs;

	/**
	 * Maximum delay in milliseconds.
	 *
	 * @var int
	 */
	private int $maxDelayMs;

	/**
	 * Jitter factor (0.0 to 1.0).
	 *
	 * @var float
	 */
	private float $jitterFactor;

	/**
	 * HTTP status codes that should trigger a retry.
	 *
	 * @var int[]
	 */
	private array $retryableStatusCodes;

	/**
	 * Create a new ExponentialBackoffPolicy.
	 *
	 * @param int   $maxRetries           Maximum number of retries.
	 * @param int   $baseDelayMs          Base delay in milliseconds.
	 * @param int   $maxDelayMs           Maximum delay in milliseconds.
	 * @param float $jitterFactor         Jitter factor (0.0 to 1.0).
	 * @param int[] $retryableStatusCodes HTTP status codes to retry.
	 */
	public function __construct(
		int $maxRetries = 3,
		int $baseDelayMs = 1000,
		int $maxDelayMs = 30000,
		float $jitterFactor = 0.25,
		array $retryableStatusCodes = array( 429, 500, 502, 503, 504 )
	) {
		$this->maxRetries           = $maxRetries;
		$this->baseDelayMs          = $baseDelayMs;
		$this->maxDelayMs           = $maxDelayMs;
		$this->jitterFactor         = max( 0.0, min( 1.0, $jitterFactor ) );
		$this->retryableStatusCodes = $retryableStatusCodes;
	}

	/**
	 * {@inheritDoc}
	 */
	public function shouldRetry( int $attempt, mixed $result ): bool {
		// Don't retry if we've exceeded max retries.
		if ( $attempt >= $this->maxRetries ) {
			return false;
		}

		// Handle HttpResponse.
		if ( $result instanceof HttpResponse ) {
			return $result->isRetryable()
				|| in_array( $result->statusCode, $this->retryableStatusCodes, true );
		}

		// Handle raw status code.
		if ( is_int( $result ) ) {
			return in_array( $result, $this->retryableStatusCodes, true );
		}

		// Handle exceptions.
		if ( $result instanceof \Throwable ) {
			return $this->isRetryableException( $result );
		}

		// Handle boolean (false = failed, should retry).
		if ( is_bool( $result ) ) {
			return ! $result;
		}

		// Default: don't retry.
		return false;
	}

	/**
	 * {@inheritDoc}
	 */
	public function getDelayMs( int $attempt, ?int $retryAfterHeader = null ): int {
		// If Retry-After header is provided, use it.
		if ( null !== $retryAfterHeader && $retryAfterHeader > 0 ) {
			return min( $retryAfterHeader * 1000, $this->maxDelayMs );
		}

		// Calculate exponential delay: base * 2^attempt.
		// Cap at reasonable exponent to prevent integer overflow (2^30 is safe, ~1 billion).
		$safeAttempt = min( $attempt, 30 );
		$delay       = $this->baseDelayMs * ( 2 ** $safeAttempt );

		// Also cap at maxDelayMs early to avoid overflow in jitter calculation.
		$delay = min( $delay, $this->maxDelayMs );

		// Apply jitter.
		$jitterRange = (int) ( $delay * $this->jitterFactor );
		$jitter      = $jitterRange > 0 ? random_int( -$jitterRange, $jitterRange ) : 0;
		$delay       = $delay + $jitter;

		// Clamp to max delay.
		return (int) min( max( 0, $delay ), $this->maxDelayMs );
	}

	/**
	 * {@inheritDoc}
	 */
	public function getMaxRetries(): int {
		return $this->maxRetries;
	}

	/**
	 * Check if an exception is retryable.
	 *
	 * @param \Throwable $exception The exception.
	 * @return bool
	 */
	private function isRetryableException( \Throwable $exception ): bool {
		$message = strtolower( $exception->getMessage() );

		// Network errors are typically retryable.
		$retryablePatterns = array(
			'timeout',
			'timed out',
			'connection refused',
			'connection reset',
			'network unreachable',
			'temporary failure',
			'service unavailable',
			'bad gateway',
			'gateway timeout',
		);

		foreach ( $retryablePatterns as $pattern ) {
			if ( str_contains( $message, $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Create a policy for rate-limited APIs.
	 *
	 * @return self
	 */
	public static function forRateLimiting(): self {
		return new self(
			maxRetries: 5,
			baseDelayMs: 2000,
			maxDelayMs: 60000,
			jitterFactor: 0.3,
			retryableStatusCodes: array( 429 )
		);
	}

	/**
	 * Create a policy for OpenAI API calls.
	 *
	 * @return self
	 */
	public static function forOpenAI(): self {
		return new self(
			maxRetries: 3,
			baseDelayMs: 1000,
			maxDelayMs: 30000,
			jitterFactor: 0.25,
			retryableStatusCodes: array( 429, 500, 502, 503, 504, 520, 521, 522, 524 )
		);
	}

	/**
	 * Create an aggressive retry policy.
	 *
	 * @return self
	 */
	public static function aggressive(): self {
		return new self(
			maxRetries: 5,
			baseDelayMs: 500,
			maxDelayMs: 10000,
			jitterFactor: 0.2
		);
	}

	/**
	 * Create a conservative retry policy.
	 *
	 * @return self
	 */
	public static function conservative(): self {
		return new self(
			maxRetries: 2,
			baseDelayMs: 2000,
			maxDelayMs: 60000,
			jitterFactor: 0.3
		);
	}
}
