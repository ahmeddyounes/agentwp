<?php
/**
 * Date range parser for order search.
 *
 * @package AgentWP\Services\OrderSearch
 */

namespace AgentWP\Services\OrderSearch;

use AgentWP\Contracts\ClockInterface;
use AgentWP\DTO\DateRange;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Parses date ranges from natural language and structured input.
 */
final class DateRangeParser {

	/**
	 * Clock for time operations.
	 *
	 * @var ClockInterface
	 */
	private ClockInterface $clock;

	/**
	 * Timezone for date operations.
	 *
	 * @var DateTimeZone
	 */
	private DateTimeZone $timezone;

	/**
	 * Create a new DateRangeParser.
	 *
	 * @param ClockInterface    $clock    Clock for time operations.
	 * @param DateTimeZone|null $timezone Timezone (defaults to UTC).
	 */
	public function __construct( ClockInterface $clock, ?DateTimeZone $timezone = null ) {
		$this->clock    = $clock;
		$this->timezone = $timezone ?? new DateTimeZone( 'UTC' );
	}

	/**
	 * Parse a date range from a natural language query.
	 *
	 * @param string $query The query string.
	 * @return DateRange|null
	 */
	public function parseFromQuery( string $query ): ?DateRange {
		$query = strtolower( $query );

		// Check for relative date phrases using match expression for safety.
		// Order matters: more specific phrases ("last week") must come before
		// less specific ones ("last") to avoid partial matches.
		$relativePhrases = array( 'today', 'yesterday', 'last week', 'this week', 'this month', 'last month' );

		foreach ( $relativePhrases as $phrase ) {
			if ( false !== strpos( $query, $phrase ) ) {
					$result = match ( $phrase ) {
						'today'      => $this->today(),
						'yesterday'  => $this->yesterday(),
						'last week'  => $this->lastWeek(),
						'this week'  => $this->thisWeek(),
						'this month' => $this->thisMonth(),
						'last month' => $this->lastMonth(),
					};

				if ( null !== $result ) {
					return $result;
				}
			}
		}

		// Try explicit date range.
		return $this->parseExplicitRange( $query );
	}

	/**
	 * Parse from structured array input.
	 *
	 * @param array|null $input Input array with 'start' and 'end' keys.
	 * @return DateRange|null
	 */
	public function parseFromArray( ?array $input ): ?DateRange {
		if ( null === $input || ! is_array( $input ) ) {
			return null;
		}

		$start = $input['start'] ?? '';
		$end   = $input['end'] ?? '';

		if ( '' === $start || '' === $end ) {
			return null;
		}

		$startDate = $this->parseDateString( $start, false );
		$endDate   = $this->parseDateString( $end, true );

		if ( null === $startDate || null === $endDate ) {
			return null;
		}

		// Swap if needed.
		if ( $endDate < $startDate ) {
			$temp      = $startDate;
			$startDate = $endDate;
			$endDate   = $temp;
		}

		return new DateRange( $startDate, $endDate );
	}

	/**
	 * Get today's date range.
	 *
	 * @return DateRange
	 */
	public function today(): DateRange {
		$now   = $this->clock->now( $this->timezone );
		$start = $now->setTime( 0, 0, 0 );
		$end   = $now->setTime( 23, 59, 59 );

		return new DateRange( $start, $end );
	}

	/**
	 * Get yesterday's date range.
	 *
	 * @return DateRange
	 */
	public function yesterday(): DateRange {
		$now   = $this->clock->now( $this->timezone );
		$start = $now->modify( '-1 day' )->setTime( 0, 0, 0 );
		$end   = $now->modify( '-1 day' )->setTime( 23, 59, 59 );

		return new DateRange( $start, $end );
	}

	/**
	 * Get last week's date range.
	 *
	 * @return DateRange
	 */
	public function lastWeek(): DateRange {
		$now   = $this->clock->now( $this->timezone );
		$start = $now->modify( '-7 days' )->setTime( 0, 0, 0 );
		$end   = $now->modify( '-1 day' )->setTime( 23, 59, 59 );

		return new DateRange( $start, $end );
	}

	/**
	 * Get this week's date range.
	 *
	 * @return DateRange
	 */
	public function thisWeek(): DateRange {
		$now   = $this->clock->now( $this->timezone );
		$start = $now->modify( 'monday this week' )->setTime( 0, 0, 0 );
		$end   = $now->setTime( 23, 59, 59 );

		return new DateRange( $start, $end );
	}

	/**
	 * Get this month's date range.
	 *
	 * @return DateRange
	 */
	public function thisMonth(): DateRange {
		$now   = $this->clock->now( $this->timezone );
		$start = $now->modify( 'first day of this month' )->setTime( 0, 0, 0 );
		$end   = $now->setTime( 23, 59, 59 );

		return new DateRange( $start, $end );
	}

	/**
	 * Get last month's date range.
	 *
	 * @return DateRange
	 */
	public function lastMonth(): DateRange {
		$now   = $this->clock->now( $this->timezone );
		$start = $now->modify( 'first day of last month' )->setTime( 0, 0, 0 );
		$end   = $now->modify( 'last day of last month' )->setTime( 23, 59, 59 );

		return new DateRange( $start, $end );
	}

	/**
	 * Get a custom date range.
	 *
	 * @param string $startStr Start date string.
	 * @param string $endStr   End date string.
	 * @return DateRange|null
	 */
	public function custom( string $startStr, string $endStr ): ?DateRange {
		$start = $this->parseDateString( $startStr, false );
		$end   = $this->parseDateString( $endStr, true );

		if ( null === $start || null === $end ) {
			return null;
		}

		return new DateRange( $start, $end );
	}

	/**
	 * Parse explicit date range from query.
	 *
	 * @param string $query The query string.
	 * @return DateRange|null
	 */
	private function parseExplicitRange( string $query ): ?DateRange {
		$patterns = array(
			'/\bfrom\s+([a-z0-9,\/\-\s]+?)\s+to\s+([a-z0-9,\/\-\s]+)\b/i',
			'/\bbetween\s+([a-z0-9,\/\-\s]+?)\s+and\s+([a-z0-9,\/\-\s]+)\b/i',
		);

		foreach ( $patterns as $pattern ) {
			if ( ! preg_match( $pattern, $query, $matches ) ) {
				continue;
			}

			$startStr = trim( $matches[1] );
			$endStr   = trim( $matches[2] );

			// Skip if looks like email.
			if ( false !== strpos( $startStr, '@' ) || false !== strpos( $endStr, '@' ) ) {
				continue;
			}

			$range = $this->custom( $startStr, $endStr );
			if ( null !== $range ) {
				return $range;
			}
		}

		return null;
	}

	/**
	 * Parse a date string into a DateTimeImmutable.
	 *
	 * @param string $dateString The date string.
	 * @param bool   $endOfDay   Whether to set to end of day.
	 * @return DateTimeImmutable|null
	 */
	private function parseDateString( string $dateString, bool $endOfDay ): ?DateTimeImmutable {
		$dateString = trim( $dateString );

		if ( '' === $dateString ) {
			return null;
		}

		$baseTimestamp = $this->clock->timestamp();
		$timestamp     = strtotime( $dateString, $baseTimestamp );

		if ( false === $timestamp ) {
			return null;
		}

		try {
			$date = ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $this->timezone );

			if ( $endOfDay ) {
				$date = $date->setTime( 23, 59, 59 );
			} else {
				$date = $date->setTime( 0, 0, 0 );
			}

			return $date;
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/**
	 * Set the timezone.
	 *
	 * @param DateTimeZone $timezone The timezone.
	 * @return void
	 */
	public function setTimezone( DateTimeZone $timezone ): void {
		$this->timezone = $timezone;
	}
}
