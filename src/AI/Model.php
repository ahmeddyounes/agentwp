<?php
/**
 * OpenAI model abstraction.
 *
 * @package AgentWP
 */

namespace AgentWP\AI;

use AgentWP\Config\AgentWPConfig;

class Model {
	const GPT_4O = 'gpt-4o';
	const GPT_4O_MINI = 'gpt-4o-mini';

	/**
	 * List supported models.
	 *
	 * @return string[]
	 */
	public static function all() {
		return array(
			self::GPT_4O,
			self::GPT_4O_MINI,
		);
	}

	/**
	 * Normalize a model value to a supported identifier.
	 *
	 * Falls back to centralized config default model if the provided model is invalid.
	 *
	 * @param string $model Model name.
	 * @return string
	 */
	public static function normalize( $model ) {
		$model = is_string( $model ) ? $model : '';

		if ( in_array( $model, self::all(), true ) ) {
			return $model;
		}

		// Use centralized config for default model with filter support.
		return AgentWPConfig::get( 'openai.default_model', AgentWPConfig::OPENAI_DEFAULT_MODEL );
	}
}
