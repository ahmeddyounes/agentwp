<?php
/**
 * Tool normalizer for OpenAI function calling.
 *
 * @package AgentWP\AI\Client
 */

namespace AgentWP\AI\Client;

use AgentWP\AI\Functions\FunctionSchema;

/**
 * Normalizes function definitions into OpenAI tool format.
 */
final class ToolNormalizer {

	/**
	 * Normalize function definitions to tool format.
	 *
	 * @param array $functions Function definitions (FunctionSchema instances or arrays).
	 * @return array Normalized tool definitions.
	 */
	public function normalize( array $functions ): array {
		$tools = array();

		foreach ( $functions as $function ) {
			$tool = $this->normalizeFunction( $function );

			if ( null !== $tool ) {
				$tools[] = $tool;
			}
		}

		return $tools;
	}

	/**
	 * Normalize a single function definition.
	 *
	 * @param mixed $function Function definition.
	 * @return array|null Normalized tool or null if invalid.
	 */
	private function normalizeFunction( $function ): ?array {
		// Handle FunctionSchema instances.
		if ( $function instanceof FunctionSchema ) {
			return $function->to_tool_definition();
		}

		// Handle objects with to_tool_definition method.
		if ( is_object( $function ) && method_exists( $function, 'to_tool_definition' ) ) {
			return $function->to_tool_definition();
		}

		// Skip non-arrays.
		if ( ! is_array( $function ) ) {
			return null;
		}

		// Already in tool format.
		if ( isset( $function['type'] ) && 'function' === $function['type'] ) {
			return $this->ensureStrict( $function );
		}

		// Legacy function format (just the function definition).
		if ( isset( $function['name'] ) ) {
			return $this->wrapAsToolWithStrict( $function );
		}

		return null;
	}

	/**
	 * Ensure a tool has strict mode enabled.
	 *
	 * @param array $tool The tool definition.
	 * @return array Tool with strict mode.
	 */
	private function ensureStrict( array $tool ): array {
		if ( isset( $tool['function'] ) && is_array( $tool['function'] ) ) {
			$tool['function']['strict'] = true;
		}

		return $tool;
	}

	/**
	 * Wrap a function definition as a tool with strict mode.
	 *
	 * @param array $function The function definition.
	 * @return array Tool definition.
	 */
	private function wrapAsToolWithStrict( array $function ): array {
		$function['strict'] = true;

		return array(
			'type'     => 'function',
			'function' => $function,
		);
	}
}
