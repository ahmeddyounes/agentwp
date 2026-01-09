<?php
/**
 * Date range DTO.
 *
 * @package AgentWP\DTO
 */

namespace AgentWP\DTO;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Immutable date range value object.
 */
final class DateRange {

	/**
	 * Create a new DateRange.
	 *
	 * @param DateTimeImmutable $start Start of the range.
	 * @param DateTimeImmutable $end   End of the range.
	 * @throws InvalidArgumentException If start is after end.
	 */
	public function __construct(
		public readonly DateTimeImmutable $start,
		public readonly DateTimeImmutable $end,
	) {
		if ( $this->start > $this->end ) {
			throw new InvalidArgumentException( 'Start date must be before or equal to end date.' );
		}
	}

	/**
	 * Create a range for today.
	 *
	 * @param DateTimeZone|null $timezone Timezone.
	 * @return self
	 */
	public static function today( ?DateTimeZone $timezone = null ): self {
		$now   = new DateTimeImmutable( 'now', $timezone );
		$start = $now->setTime( 0, 0, 0 );
		$end   = $now->setTime( 23, 59, 59 );

		return new self( $start, $end );
	}

	/**
	 * Create a range for yesterday.
	 *
	 * @param DateTimeZone|null $timezone Timezone.
	 * @return self
	 */
	public static function yesterday( ?DateTimeZone $timezone = null ): self {
		$yesterday = new DateTimeImmutable( 'yesterday', $timezone );
		$start     = $yesterday->setTime( 0, 0, 0 );
		$end       = $yesterday->setTime( 23, 59, 59 );

		return new self( $start, $end );
	}

	/**
	 * Create a range for the last N days.
	 *
	 * @param int               $days     Number of days (must be non-negative).
	 * @param DateTimeZone|null $timezone Timezone.
	 * @return self
	 * @throws InvalidArgumentException If days is negative or date modification fails.
	 */
	public static function lastDays( int $days, ?DateTimeZone $timezone = null ): self {
		if ( $days < 0 ) {
			throw new InvalidArgumentException( 'Days must be non-negative.' );
		}

		$now      = new DateTimeImmutable( 'now', $timezone );
		$modified = $now->modify( sprintf( '-%d days', $days ) );
		// modify() can return false on failure.
		if ( false === $modified ) {
			throw new InvalidArgumentException( 'Failed to calculate start date.' );
		}
		$start = $modified->setTime( 0, 0, 0 );
		$end   = $now->setTime( 23, 59, 59 );

		return new self( $start, $end );
	}

	/**
	 * Create a range for this week.
	 *
	 * @param DateTimeZone|null $timezone Timezone.
	 * @return self
	 * @throws InvalidArgumentException If date modification fails.
	 */
	public static function thisWeek( ?DateTimeZone $timezone = null ): self {
		$now        = new DateTimeImmutable( 'now', $timezone );
		$startMod   = $now->modify( 'monday this week' );
		$endMod     = $now->modify( 'sunday this week' );
		// modify() can return false on failure.
		if ( false === $startMod || false === $endMod ) {
			throw new InvalidArgumentException( 'Failed to calculate week boundaries.' );
		}
		$start = $startMod->setTime( 0, 0, 0 );
		$end   = $endMod->setTime( 23, 59, 59 );

		return new self( $start, $end );
	}

	/**
	 * Create a range for last week.
	 *
	 * @param DateTimeZone|null $timezone Timezone.
	 * @return self
	 * @throws InvalidArgumentException If date modification fails.
	 */
	public static function lastWeek( ?DateTimeZone $timezone = null ): self {
		$now      = new DateTimeImmutable( 'now', $timezone );
		$startMod = $now->modify( 'monday last week' );
		$endMod   = $now->modify( 'sunday last week' );
		// modify() can return false on failure.
		if ( false === $startMod || false === $endMod ) {
			throw new InvalidArgumentException( 'Failed to calculate week boundaries.' );
		}
		$start = $startMod->setTime( 0, 0, 0 );
		$end   = $endMod->setTime( 23, 59, 59 );

		return new self( $start, $end );
	}

	/**
	 * Create a range for this month.
	 *
	 * @param DateTimeZone|null $timezone Timezone.
	 * @return self
	 * @throws InvalidArgumentException If date modification fails.
	 */
	public static function thisMonth( ?DateTimeZone $timezone = null ): self {
		$now      = new DateTimeImmutable( 'now', $timezone );
		$startMod = $now->modify( 'first day of this month' );
		$endMod   = $now->modify( 'last day of this month' );
		// modify() can return false on failure.
		if ( false === $startMod || false === $endMod ) {
			throw new InvalidArgumentException( 'Failed to calculate month boundaries.' );
		}
		$start = $startMod->setTime( 0, 0, 0 );
		$end   = $endMod->setTime( 23, 59, 59 );

		return new self( $start, $end );
	}

	/**
	 * Create a range for last month.
	 *
	 * @param DateTimeZone|null $timezone Timezone.
	 * @return self
	 * @throws InvalidArgumentException If date modification fails.
	 */
	public static function lastMonth( ?DateTimeZone $timezone = null ): self {
		$now      = new DateTimeImmutable( 'now', $timezone );
		$startMod = $now->modify( 'first day of last month' );
		$endMod   = $now->modify( 'last day of last month' );
		// modify() can return false on failure.
		if ( false === $startMod || false === $endMod ) {
			throw new InvalidArgumentException( 'Failed to calculate month boundaries.' );
		}
		$start = $startMod->setTime( 0, 0, 0 );
		$end   = $endMod->setTime( 23, 59, 59 );

		return new self( $start, $end );
	}

	/**
	 * Check if a date is within this range.
	 *
	 * @param DateTimeImmutable $date The date to check.
	 * @return bool True if within range.
	 */
	public function contains( DateTimeImmutable $date ): bool {
		return $date >= $this->start && $date <= $this->end;
	}

	/**
	 * Get the duration in days.
	 *
	 * @return int Number of days (minimum 1).
	 */
	public function getDays(): int {
		$diff = $this->start->diff( $this->end );
		// DateInterval::$days can be false if calculated from relative date string.
		if ( false === $diff->days ) {
			return 1;
		}
		return $diff->days + 1;
	}

	/**
	 * Convert to array format.
	 *
	 * @param string $format Date format string.
	 * @return array{start: string, end: string}
	 */
	public function toArray( string $format = 'Y-m-d H:i:s' ): array {
		return array(
			'start' => $this->start->format( $format ),
			'end'   => $this->end->format( $format ),
		);
	}

	/**
	 * Create from string dates.
	 *
	 * @param string            $start    Start date string.
	 * @param string            $end      End date string.
	 * @param DateTimeZone|null $timezone Timezone.
	 * @return self|null Null if parsing fails.
	 */
	public static function fromStrings(
		string $start,
		string $end,
		?DateTimeZone $timezone = null
	): ?self {
		try {
			$startDate = new DateTimeImmutable( $start, $timezone );
			$endDate   = new DateTimeImmutable( $end, $timezone );

			return new self( $startDate, $endDate );
		} catch ( \Exception $e ) {
			return null;
		}
	}
}
