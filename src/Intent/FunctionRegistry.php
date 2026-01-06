<?php
/**
 * OpenAI function registry for intent handlers.
 *
 * @package AgentWP
 */

namespace AgentWP\Intent;

class FunctionRegistry {
	/**
	 * @var array<string, Handler>
	 */
	private $map = array();

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

		sort( $names );

		return $names;
	}

	/**
	 * @return array
	 */
	public function all() {
		return $this->map;
	}
}
