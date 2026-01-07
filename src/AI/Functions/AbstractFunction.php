<?php
/**
 * Base class for tool definitions.
 *
 * @package AgentWP
 */

namespace AgentWP\AI\Functions;

abstract class AbstractFunction implements FunctionSchema {
	/**
	 * @var array<string, array>
	 */
	private static $definition_cache = array();

	/**
	 * @return array
	 */
	public function to_tool_definition() {
		$class_name = static::class;
		if ( isset( self::$definition_cache[ $class_name ] ) ) {
			return self::$definition_cache[ $class_name ];
		}

		$definition = array(
			'type'     => 'function',
			'function' => array(
				'name'        => $this->get_name(),
				'description' => $this->get_description(),
				'parameters'  => $this->get_parameters(),
				'strict'      => true,
			),
		);

		self::$definition_cache[ $class_name ] = $definition;

		return $definition;
	}
}
