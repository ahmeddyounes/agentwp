<?php
/**
 * Analytics Query Request DTO.
 *
 * @package AgentWP\DTO
 */

namespace AgentWP\DTO;

/**
 * DTO for analytics query requests.
 */
final class AnalyticsQueryDTO extends RequestDTO {

	/**
	 * Valid period options.
	 */
	private const VALID_PERIODS = array( '7d', '30d', '90d' );

	/**
	 * Default period.
	 */
	private const DEFAULT_PERIOD = '7d';

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
					'type'    => 'string',
					'enum'    => self::VALID_PERIODS,
					'default' => self::DEFAULT_PERIOD,
				),
			),
		);
	}

	/**
	 * Get the analytics period.
	 *
	 * @return string One of '7d', '30d', or '90d'.
	 */
	public function getPeriod(): string {
		$period = sanitize_text_field( $this->getString( 'period', self::DEFAULT_PERIOD ) );

		if ( ! in_array( $period, self::VALID_PERIODS, true ) ) {
			return self::DEFAULT_PERIOD;
		}

		return $period;
	}
}
