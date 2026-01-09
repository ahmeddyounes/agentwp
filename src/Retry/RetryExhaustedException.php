<?php
/**
 * Retry exhausted exception.
 *
 * @package AgentWP\Retry
 */

namespace AgentWP\Retry;

use RuntimeException;

/**
 * Thrown when all retry attempts are exhausted.
 */
final class RetryExhaustedException extends RuntimeException {

	/**
	 * Number of attempts made.
	 *
	 * @var int
	 */
	private int $attempts;

	/**
	 * Create a new RetryExhaustedException.
	 *
	 * @param string          $message   Error message.
	 * @param int             $attempts  Number of attempts made.
	 * @param \Throwable|null $previous  Previous exception.
	 */
	public function __construct( string $message, int $attempts, ?\Throwable $previous = null ) {
		parent::__construct( $message, 0, $previous );
		$this->attempts = $attempts;
	}

	/**
	 * Get number of attempts made.
	 *
	 * @return int
	 */
	public function getAttempts(): int {
		return $this->attempts;
	}
}
