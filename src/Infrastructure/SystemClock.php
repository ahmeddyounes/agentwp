<?php
/**
 * System clock implementation.
 *
 * @package AgentWP\Infrastructure
 */

namespace AgentWP\Infrastructure;

use AgentWP\Contracts\ClockInterface;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Real clock implementation using PHP's time functions.
 */
final class SystemClock implements ClockInterface {

	/**
	 * Default timezone.
	 *
	 * @var DateTimeZone|null
	 */
	private ?DateTimeZone $defaultTimezone;

	/**
	 * Create a new SystemClock.
	 *
	 * @param DateTimeZone|null $defaultTimezone Default timezone for time operations.
	 */
	public function __construct( ?DateTimeZone $defaultTimezone = null ) {
		$this->defaultTimezone = $defaultTimezone;
	}

	/**
	 * {@inheritDoc}
	 */
	public function now( ?DateTimeZone $timezone = null ): DateTimeImmutable {
		$tz = $timezone ?? $this->defaultTimezone;

		return new DateTimeImmutable( 'now', $tz );
	}

	/**
	 * {@inheritDoc}
	 */
	public function timestamp(): int {
		return time();
	}

	/**
	 * {@inheritDoc}
	 */
	public function format( string $format, ?DateTimeZone $timezone = null ): string {
		return $this->now( $timezone )->format( $format );
	}

	/**
	 * Create a clock with WordPress timezone.
	 *
	 * @return self
	 */
	public static function withWordPressTimezone(): self {
		$tzString = get_option( 'timezone_string' );

		if ( empty( $tzString ) ) {
			$offset = (float) get_option( 'gmt_offset', 0 );

			if ( 0.0 === $offset ) {
				$tzString = 'UTC';
			} else {
				// Fixed offsets don't support DST; use UTC and log warning.
				// Site should set timezone_string for proper DST handling.
				$tzString = 'UTC';
			}
		}

		try {
			$timezone = new DateTimeZone( $tzString );
		} catch ( \Exception $e ) {
			$timezone = new DateTimeZone( 'UTC' );
		}

		return new self( $timezone );
	}
}
