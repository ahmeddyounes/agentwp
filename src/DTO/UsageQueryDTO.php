<?php
/**
 * Usage Query Request DTO.
 *
 * @package AgentWP\DTO
 */

namespace AgentWP\DTO;

/**
 * DTO for usage query requests.
 */
final class UsageQueryDTO extends RequestDTO {

	/**
	 * Valid period options.
	 */
	private const VALID_PERIODS = array( 'day', 'week', 'month' );

	/**
	 * Default period.
	 */
	private const DEFAULT_PERIOD = 'month';

	/**
	 * {@inheritDoc}
	 */
	protected function getSource(): string {
		return 'query';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function getSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'period' => array(
					'type' => 'string',
					'enum' => self::VALID_PERIODS,
				),
			),
		);
	}

	/**
	 * Get the usage period.
	 *
	 * @return string One of 'day', 'week', or 'month'.
	 */
	public function getPeriod(): string {
		$period = sanitize_text_field( $this->getString( 'period', self::DEFAULT_PERIOD ) );

		if ( ! in_array( $period, self::VALID_PERIODS, true ) ) {
			return self::DEFAULT_PERIOD;
		}

		return $period;
	}

	/**
	 * Check if period is valid.
	 *
	 * @return bool
	 */
	public function hasValidPeriod(): bool {
		$period = $this->getString( 'period', self::DEFAULT_PERIOD );
		return in_array( $period, self::VALID_PERIODS, true );
	}
}
