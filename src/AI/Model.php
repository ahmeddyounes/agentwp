<?php
/**
 * OpenAI model abstraction.
 *
 * @package AgentWP
 */

namespace AgentWP\AI;

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
	 * @param string $model Model name.
	 * @return string
	 */
	public static function normalize( $model ) {
		$model = is_string( $model ) ? $model : '';

		if ( in_array( $model, self::all(), true ) ) {
			return $model;
		}

		return self::GPT_4O_MINI;
	}
}
