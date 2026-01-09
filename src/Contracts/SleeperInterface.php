<?php
/**
 * Sleeper interface.
 *
 * @package AgentWP\Contracts
 */

namespace AgentWP\Contracts;

/**
 * Contract for sleep/delay services.
 *
 * Abstraction allows testing without actual delays.
 */
interface SleeperInterface {

	/**
	 * Sleep for specified milliseconds.
	 *
	 * @param int $milliseconds Number of milliseconds to sleep.
	 * @return void
	 */
	public function sleepMs( int $milliseconds ): void;

	/**
	 * Sleep for specified seconds.
	 *
	 * @param int $seconds Number of seconds to sleep.
	 * @return void
	 */
	public function sleepSec( int $seconds ): void;
}
