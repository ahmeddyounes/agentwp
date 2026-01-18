<?php
/**
 * Theme Request DTO.
 *
 * @package AgentWP\DTO
 */

namespace AgentWP\DTO;

/**
 * DTO for theme preference requests.
 */
final class ThemeRequestDTO extends RequestDTO {

	/**
	 * Valid theme options.
	 */
	private const VALID_THEMES = array( 'light', 'dark' );

	/**
	 * {@inheritDoc}
	 */
	protected function getSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'theme' => array(
					'type' => 'string',
					'enum' => self::VALID_THEMES,
				),
			),
			'required'             => array( 'theme' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Get the theme value.
	 *
	 * @return string One of 'light' or 'dark'.
	 */
	public function getTheme(): string {
		return sanitize_text_field( $this->getString( 'theme' ) );
	}
}
