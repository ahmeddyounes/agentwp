<?php
/**
 * Legacy function suggestion registry for intent handlers.
 *
 * @package AgentWP
 */

namespace AgentWP\Intent;

use AgentWP\Contracts\ToolRegistryInterface;

class FunctionRegistry {
	/**
	 * @var array<string, Handler>
	 */
	private $map = array();

	/**
	 * @var ToolRegistryInterface|null
	 */
	private ?ToolRegistryInterface $tool_registry = null;

	/**
	 * @var HandlerRegistry|null
	 */
	private ?HandlerRegistry $handler_registry = null;

	/**
	 * @param string  $function_name OpenAI function name.
	 * @param Handler $handler Handler instance.
	 * @return void
	 */
	public function register( $function_name, Handler $handler ) {
		$function_name = is_string( $function_name ) ? trim( $function_name ) : '';
		if ( '' === $function_name ) {
			return;
		}

		$this->map[ $function_name ] = $handler;
	}

	/**
	 * Set the tool registry to validate suggestions against.
	 *
	 * @param ToolRegistryInterface $registry Tool registry.
	 * @return void
	 */
	public function set_tool_registry( ToolRegistryInterface $registry ): void {
		$this->tool_registry = $registry;
	}

	/**
	 * Set the handler registry to derive suggestions from.
	 *
	 * @param HandlerRegistry $registry Handler registry.
	 * @return void
	 */
	public function set_handler_registry( HandlerRegistry $registry ): void {
		$this->handler_registry = $registry;
	}

	/**
	 * @param string $function_name OpenAI function name.
	 * @return Handler|null
	 */
	public function get_handler( $function_name ) {
		$function_name = is_string( $function_name ) ? trim( $function_name ) : '';
		if ( '' === $function_name ) {
			return null;
		}

		return isset( $this->map[ $function_name ] ) ? $this->map[ $function_name ] : null;
	}

	/**
	 * @param string $intent Intent identifier.
	 * @return array
	 */
	public function get_functions_for_intent( $intent ) {
		$intent = Intent::normalize( $intent );
		$names  = array();

		foreach ( $this->map as $function_name => $handler ) {
			if ( $handler->canHandle( $intent ) ) {
				$names[] = $function_name;
			}
		}

		if ( empty( $names ) && $this->handler_registry ) {
			$names = $this->derive_functions_from_handlers( $intent );
		}

		if ( ! empty( $names ) ) {
			$names = $this->filter_known_tools( $names );
		}

		sort( $names );

		return $names;
	}

	/**
	 * @return array
	 */
	public function all() {
		return $this->map;
	}

	/**
	 * Derive suggestions from handler tool lists when no legacy mapping exists.
	 *
	 * @param string $intent Intent identifier.
	 * @return array<string>
	 */
	private function derive_functions_from_handlers( string $intent ): array {
		$handler = $this->handler_registry ? $this->handler_registry->get( $intent ) : null;
		if ( ! $handler ) {
			return array();
		}

		if ( $handler instanceof ToolSuggestionProvider ) {
			$names = $handler->getSuggestedTools();
			return is_array( $names ) ? $names : array();
		}

		return array();
	}

	/**
	 * Filter suggestions against the tool registry if available.
	 *
	 * @param array<string> $names Suggested tool names.
	 * @return array<string>
	 */
	private function filter_known_tools( array $names ): array {
		if ( ! $this->tool_registry ) {
			return $names;
		}

		$filtered = array();
		foreach ( $names as $name ) {
			$name = is_string( $name ) ? trim( $name ) : '';
			if ( '' === $name ) {
				continue;
			}

			if ( $this->tool_registry->get( $name ) ) {
				$filtered[] = $name;
			}
		}

		return $filtered;
	}
}
