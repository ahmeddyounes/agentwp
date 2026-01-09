<?php
/**
 * Fake clock for testing.
 *
 * @package AgentWP\Tests\Fakes
 */

namespace AgentWP\Tests\Fakes;

use AgentWP\Contracts\ClockInterface;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Controllable clock for testing.
 */
final class FakeClock implements ClockInterface {

	/**
	 * Current time.
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $currentTime;

	/**
	 * Create a new FakeClock.
	 *
	 * @param DateTimeImmutable|null $initialTime Initial time (defaults to now).
	 */
	public function __construct( ?DateTimeImmutable $initialTime = null ) {
		$this->currentTime = $initialTime ?? new DateTimeImmutable();
	}

	/**
	 * {@inheritDoc}
	 */
	public function now( ?DateTimeZone $timezone = null ): DateTimeImmutable {
		if ( null !== $timezone ) {
			return $this->currentTime->setTimezone( $timezone );
		}

		return $this->currentTime;
	}

	/**
	 * {@inheritDoc}
	 */
	public function timestamp(): int {
		return $this->currentTime->getTimestamp();
	}

	/**
	 * {@inheritDoc}
	 */
	public function format( string $format, ?DateTimeZone $timezone = null ): string {
		$time = $this->now( $timezone );
		return $time->format( $format );
	}

	// Test helpers.

	/**
	 * Set the current time.
	 *
	 * @param DateTimeImmutable $time The time to set.
	 * @return void
	 */
	public function setTime( DateTimeImmutable $time ): void {
		$this->currentTime = $time;
	}

	/**
	 * Set time from a string.
	 *
	 * @param string            $time     Time string.
	 * @param DateTimeZone|null $timezone Optional timezone.
	 * @return void
	 */
	public function setTimeFromString( string $time, ?DateTimeZone $timezone = null ): void {
		$this->currentTime = new DateTimeImmutable( $time, $timezone );
	}

	/**
	 * Advance time by an interval.
	 *
	 * @param string $interval Relative date/time string (e.g., '+1 hour', '+30 minutes').
	 * @return void
	 */
	public function advanceBy( string $interval ): void {
		$this->currentTime = $this->currentTime->modify( $interval );
	}

	/**
	 * Advance time by seconds.
	 *
	 * @param int $seconds Seconds to advance (can be negative to go back).
	 * @return void
	 */
	public function advanceSeconds( int $seconds ): void {
		// Use %+d format to handle negative values correctly (produces "+5" or "-5").
		$this->advanceBy( sprintf( '%+d seconds', $seconds ) );
	}

	/**
	 * Advance time by minutes.
	 *
	 * @param int $minutes Minutes to advance.
	 * @return void
	 */
	public function advanceMinutes( int $minutes ): void {
		$this->advanceBy( sprintf( '%+d minutes', $minutes ) );
	}

	/**
	 * Advance time by hours.
	 *
	 * @param int $hours Hours to advance.
	 * @return void
	 */
	public function advanceHours( int $hours ): void {
		$this->advanceBy( sprintf( '%+d hours', $hours ) );
	}

	/**
	 * Advance time by days.
	 *
	 * @param int $days Days to advance.
	 * @return void
	 */
	public function advanceDays( int $days ): void {
		$this->advanceBy( sprintf( '%+d days', $days ) );
	}

	/**
	 * Create a clock frozen at a specific time.
	 *
	 * @param string            $time     Time string.
	 * @param DateTimeZone|null $timezone Optional timezone.
	 * @return self
	 */
	public static function frozenAt( string $time, ?DateTimeZone $timezone = null ): self {
		return new self( new DateTimeImmutable( $time, $timezone ) );
	}
}
