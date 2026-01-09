<?php
/**
 * Real sleeper implementation.
 *
 * @package AgentWP\Infrastructure
 */

namespace AgentWP\Infrastructure;

use AgentWP\Contracts\SleeperInterface;

/**
 * Actual sleep implementation using PHP's usleep/sleep.
 */
final class RealSleeper implements SleeperInterface {

	/**
	 * {@inheritDoc}
	 */
	public function sleepMs( int $milliseconds ): void {
		usleep( $milliseconds * 1000 );
	}

	/**
	 * {@inheritDoc}
	 */
	public function sleepSec( int $seconds ): void {
		sleep( $seconds );
	}
}
