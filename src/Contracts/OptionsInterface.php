<?php
/**
 * Options interface.
 *
 * @package AgentWP\Contracts
 */

namespace AgentWP\Contracts;

/**
 * Contract for WordPress options-style storage.
 */
interface OptionsInterface {

	/**
	 * Retrieve an option value.
	 *
	 * @param string $key     Option key.
	 * @param mixed  $default Default value if option doesn't exist.
	 * @return mixed Option value or default.
	 */
	public function get( string $key, mixed $default = null ): mixed;

	/**
	 * Store an option value.
	 *
	 * @param string $key      Option key.
	 * @param mixed  $value    Value to store.
	 * @param bool   $autoload Whether to autoload the option.
	 * @return bool True on success, false on failure.
	 */
	public function set( string $key, mixed $value, bool $autoload = true ): bool;

	/**
	 * Delete an option.
	 *
	 * @param string $key Option key.
	 * @return bool True on success, false on failure.
	 */
	public function delete( string $key ): bool;

	/**
	 * Check if an option exists.
	 *
	 * @param string $key Option key.
	 * @return bool True if exists, false otherwise.
	 */
	public function has( string $key ): bool;
}
