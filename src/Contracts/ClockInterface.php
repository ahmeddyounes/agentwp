<?php
/**
 * Clock interface.
 *
 * @package AgentWP\Contracts
 */

namespace AgentWP\Contracts;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Contract for clock/time services.
 *
 * Abstraction allows testing with controlled time.
 */
interface ClockInterface {

	/**
	 * Get the current time.
	 *
	 * @param DateTimeZone|null $timezone Optional timezone.
	 * @return DateTimeImmutable Current time.
	 */
	public function now( ?DateTimeZone $timezone = null ): DateTimeImmutable;

	/**
	 * Get the current Unix timestamp.
	 *
	 * @return int Unix timestamp.
	 */
	public function timestamp(): int;

	/**
	 * Get the current time as a formatted string.
	 *
	 * @param string            $format   Date format string.
	 * @param DateTimeZone|null $timezone Optional timezone.
	 * @return string Formatted time string.
	 */
	public function format( string $format, ?DateTimeZone $timezone = null ): string;
}
