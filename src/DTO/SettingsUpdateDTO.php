<?php
/**
 * Settings Update Request DTO.
 *
 * @package AgentWP\DTO
 */

namespace AgentWP\DTO;

/**
 * DTO for settings update requests.
 */
final class SettingsUpdateDTO extends RequestDTO {

	/**
	 * Valid model options.
	 */
	private const VALID_MODELS = array( 'gpt-4o', 'gpt-4o-mini' );

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
			'additionalProperties' => false,
			'properties'           => array(
				'model'             => array(
					'type' => 'string',
					'enum' => self::VALID_MODELS,
				),
				'budget_limit'      => array(
					'type'    => 'number',
					'minimum' => 0,
					'maximum' => 100000,
				),
				'draft_ttl_minutes' => array(
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => 10080, // Max 7 days.
				),
				'hotkey'            => array(
					'type'      => 'string',
					'maxLength' => 50,
				),
				'theme'             => array(
					'type' => 'string',
					'enum' => self::VALID_THEMES,
				),
				'dark_mode'         => array(
					'type' => 'boolean',
				),
				'demo_mode'         => array(
					'type' => 'boolean',
				),
			),
		);
	}

	/**
	 * Apply updates to existing settings array.
	 *
	 * @param array $settings Existing settings.
	 * @return array Updated settings.
	 */
	public function applyTo( array $settings ): array {
		if ( $this->has( 'model' ) ) {
			$model = sanitize_text_field( wp_unslash( $this->getString( 'model' ) ) );
			if ( in_array( $model, self::VALID_MODELS, true ) ) {
				$settings['model'] = $model;
			}
		}

		if ( $this->has( 'budget_limit' ) ) {
			$budget = $this->getFloat( 'budget_limit' );
			if ( $budget >= 0 ) {
				$settings['budget_limit'] = $budget;
			}
		}

		if ( $this->has( 'draft_ttl_minutes' ) ) {
			$ttl = $this->getInt( 'draft_ttl_minutes' );
			if ( $ttl >= 0 ) {
				$settings['draft_ttl_minutes'] = $ttl;
			}
		}

		if ( $this->has( 'hotkey' ) ) {
			$hotkey = sanitize_text_field( wp_unslash( $this->getString( 'hotkey' ) ) );
			if ( '' !== $hotkey ) {
				$settings['hotkey'] = $hotkey;
			}
		}

		if ( $this->has( 'theme' ) ) {
			$theme = sanitize_text_field( wp_unslash( $this->getString( 'theme' ) ) );
			if ( in_array( $theme, self::VALID_THEMES, true ) ) {
				$settings['theme'] = $theme;
			}
		}

		if ( $this->has( 'dark_mode' ) ) {
			$settings['theme'] = $this->getBool( 'dark_mode' ) ? 'dark' : 'light';
		}

		if ( $this->has( 'demo_mode' ) ) {
			$settings['demo_mode'] = $this->getBool( 'demo_mode' );
		}

		return $settings;
	}
}
