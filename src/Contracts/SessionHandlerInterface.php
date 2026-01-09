<?php
/**
 * Session handler interface.
 *
 * @package AgentWP\Contracts
 */

namespace AgentWP\Contracts;

/**
 * Contract for session handling services.
 */
interface SessionHandlerInterface {

	/**
	 * Ensure session is started.
	 *
	 * @return void
	 */
	public function ensureStarted(): void;

	/**
	 * Check if session is started.
	 *
	 * @return bool True if session is active.
	 */
	public function isStarted(): bool;

	/**
	 * Get a session value.
	 *
	 * @param string $key Session key.
	 * @return mixed|null Session value or null.
	 */
	public function get( string $key ): mixed;

	/**
	 * Set a session value.
	 *
	 * @param string $key   Session key.
	 * @param mixed  $value Value to store.
	 * @return void
	 */
	public function set( string $key, mixed $value ): void;

	/**
	 * Delete a session value.
	 *
	 * @param string $key Session key.
	 * @return void
	 */
	public function delete( string $key ): void;

	/**
	 * Check if a session key exists.
	 *
	 * @param string $key Session key.
	 * @return bool True if exists.
	 */
	public function has( string $key ): bool;
}
