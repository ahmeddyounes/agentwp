<?php
/**
 * Base class for tool definitions.
 *
 * @package AgentWP
 */

namespace AgentWP\AI\Functions;

abstract class AbstractFunction implements FunctionSchema {
	/**
	 * @return array
	 */
	public function to_tool_definition() {
		return array(
			'type'     => 'function',
			'function' => array(
				'name'        => $this->get_name(),
				'description' => $this->get_description(),
				'parameters'  => $this->get_parameters(),
				'strict'      => true,
			),
		);
	}
}
