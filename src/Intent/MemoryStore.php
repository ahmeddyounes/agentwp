<?php
/**
 * Conversation memory stored in WordPress transients.
 *
 * @package AgentWP
 */

namespace AgentWP\Intent;

use AgentWP\Contracts\MemoryStoreInterface;

final class MemoryStore implements MemoryStoreInterface {
	const TRANSIENT_KEY_PREFIX = 'agentwp_intent_memory_';

	/**
	 * @var int Max memory entries.
	 */
	private int $limit;

	/**
	 * @var int TTL in seconds.
	 */
	private int $ttl;

	/**
	 * @param int $limit Max memory entries.
	 * @param int $ttl   TTL in seconds.
	 */
	public function __construct( $limit = 5, $ttl = 1800 ) {
		$this->limit = max( 1, (int) $limit );
		$this->ttl   = max( 60, (int) $ttl );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get(): array {
		$key = $this->get_transient_key();
		if ( '' === $key || ! function_exists( 'get_transient' ) ) {
			return array();
		}

		$value = get_transient( $key );
		if ( false === $value || ! is_array( $value ) ) {
			return array();
		}

		return array_values( $value );
	}

	/**
	 * {@inheritDoc}
	 */
	public function addExchange( array $entry ): void {
		$memory   = $this->get();
		$memory[] = $entry;

		if ( count( $memory ) > $this->limit ) {
			$memory = array_slice( $memory, -$this->limit );
		}

		$key = $this->get_transient_key();
		if ( '' === $key || ! function_exists( 'set_transient' ) ) {
			return;
		}

		set_transient( $key, $memory, $this->ttl );
	}

	/**
	 * Alias for backward compatibility.
	 *
	 * @param array{time: string, input: string, intent: string, message: string} $entry Exchange entry.
	 * @return void
	 */
	public function add_exchange( array $entry ) {
		$this->addExchange( $entry );
	}

	/**
	 * {@inheritDoc}
	 */
	public function clear(): void {
		$key = $this->get_transient_key();
		if ( '' === $key || ! function_exists( 'delete_transient' ) ) {
			return;
		}

		delete_transient( $key );
	}

	/**
	 * @return string Transient key, or empty string when unavailable.
	 */
	private function get_transient_key(): string {
		if ( ! function_exists( 'get_current_user_id' ) ) {
			return '';
		}

		$user_id = (int) get_current_user_id();
		if ( $user_id <= 0 ) {
			return '';
		}

		return self::TRANSIENT_KEY_PREFIX . $user_id;
	}
}
