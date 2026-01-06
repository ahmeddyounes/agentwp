<?php
/**
 * Function schema interface.
 *
 * @package AgentWP
 */

namespace AgentWP\AI\Functions;

interface FunctionSchema {
	/**
	 * @return string
	 */
	public function get_name();

	/**
	 * @return string
	 */
	public function get_description();

	/**
	 * @return array
	 */
	public function get_parameters();

	/**
	 * @return array
	 */
	public function to_tool_definition();
}
