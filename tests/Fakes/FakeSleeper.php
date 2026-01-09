<?php
/**
 * Fake sleeper for testing.
 *
 * @package AgentWP\Tests\Fakes
 */

namespace AgentWP\Tests\Fakes;

use AgentWP\Contracts\SleeperInterface;

/**
 * Non-blocking sleeper for testing.
 */
final class FakeSleeper implements SleeperInterface {

	/**
	 * Log of sleep calls in milliseconds.
	 *
	 * @var int[]
	 */
	private array $sleepLog = array();

	/**
	 * {@inheritDoc}
	 */
	public function sleepMs( int $milliseconds ): void {
		$this->sleepLog[] = $milliseconds;
	}

	/**
	 * {@inheritDoc}
	 */
	public function sleepSec( int $seconds ): void {
		$this->sleepLog[] = $seconds * 1000;
	}

	// Test helpers.

	/**
	 * Get the sleep log.
	 *
	 * @return int[] Array of milliseconds slept.
	 */
	public function getSleepLog(): array {
		return $this->sleepLog;
	}

	/**
	 * Get total time slept in milliseconds.
	 *
	 * @return int
	 */
	public function getTotalSleptMs(): int {
		return array_sum( $this->sleepLog );
	}

	/**
	 * Get number of sleep calls.
	 *
	 * @return int
	 */
	public function getSleepCount(): int {
		return count( $this->sleepLog );
	}

	/**
	 * Get the last sleep duration.
	 *
	 * @return int|null Milliseconds, or null if no sleeps.
	 */
	public function getLastSleep(): ?int {
		if ( empty( $this->sleepLog ) ) {
			return null;
		}

		return $this->sleepLog[ count( $this->sleepLog ) - 1 ];
	}

	/**
	 * Reset the sleeper.
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->sleepLog = array();
	}
}
