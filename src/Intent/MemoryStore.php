<?php
/**
 * Conversation memory stored in session.
 *
 * @package AgentWP
 */

namespace AgentWP\Intent;

class MemoryStore {
	const SESSION_KEY = 'agentwp_intent_memory';

	/**
	 * @var int
	 */
	private $limit;

	/**
	 * @param int $limit Max memory entries.
	 */
	public function __construct( $limit = 5 ) {
		$this->limit = max( 1, (int) $limit );
	}

	/**
	 * @return array
	 */
	public function get() {
		$this->ensure_session();

		if ( ! isset( $_SESSION[ self::SESSION_KEY ] ) || ! is_array( $_SESSION[ self::SESSION_KEY ] ) ) {
			return array();
		}

		return array_values( $_SESSION[ self::SESSION_KEY ] );
	}

	/**
	 * @param array $entry Exchange entry.
	 * @return void
	 */
	public function add_exchange( array $entry ) {
		$this->ensure_session();

		$memory   = $this->get();
		$memory[] = $entry;

		if ( count( $memory ) > $this->limit ) {
			$memory = array_slice( $memory, -1 * $this->limit );
		}

		$_SESSION[ self::SESSION_KEY ] = $memory;
	}

	/**
	 * @return void
	 */
	private function ensure_session() {
		if ( headers_sent() ) {
			return;
		}

		if ( function_exists( 'session_status' ) ) {
			if ( PHP_SESSION_NONE === session_status() ) {
				session_start();
			}

			return;
		}

		if ( ! isset( $_SESSION ) ) {
			session_start();
		}
	}
}
