<?php
/**
 * PHP session handler adapter.
 *
 * @package AgentWP\Infrastructure
 */

namespace AgentWP\Infrastructure;

use AgentWP\Contracts\SessionHandlerInterface;

/**
 * Wraps PHP session functions.
 */
final class PhpSessionHandler implements SessionHandlerInterface {

	/**
	 * Session key prefix.
	 *
	 * @var string
	 */
	private string $prefix;

	/**
	 * Create a new PhpSessionHandler.
	 *
	 * @param string $prefix Session key prefix.
	 */
	public function __construct( string $prefix = 'agentwp_' ) {
		$this->prefix = $prefix;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get( string $key, mixed $default = null ): mixed {
		$this->ensureStarted();

		$prefixedKey = $this->prefixKey( $key );

		if ( ! isset( $_SESSION[ $prefixedKey ] ) ) {
			return $default;
		}

		return $_SESSION[ $prefixedKey ];
	}

	/**
	 * {@inheritDoc}
	 */
	public function set( string $key, mixed $value ): void {
		$this->ensureStarted();

		$_SESSION[ $this->prefixKey( $key ) ] = $value;
	}

	/**
	 * {@inheritDoc}
	 */
	public function has( string $key ): bool {
		$this->ensureStarted();

		return isset( $_SESSION[ $this->prefixKey( $key ) ] );
	}

	/**
	 * {@inheritDoc}
	 */
	public function delete( string $key ): void {
		$this->ensureStarted();

		unset( $_SESSION[ $this->prefixKey( $key ) ] );
	}

	/**
	 * {@inheritDoc}
	 */
	public function clear(): void {
		$this->ensureStarted();

		foreach ( array_keys( $_SESSION ) as $key ) {
			if ( str_starts_with( $key, $this->prefix ) ) {
				unset( $_SESSION[ $key ] );
			}
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function destroy(): void {
		if ( PHP_SESSION_ACTIVE === session_status() ) {
			session_destroy();
		}
	}

	/**
	 * Ensure session is started.
	 *
	 * @return void
	 */
	private function ensureStarted(): void {
		if ( PHP_SESSION_NONE === session_status() ) {
			session_start();
		}
	}

	/**
	 * Prefix a session key.
	 *
	 * @param string $key The key.
	 * @return string
	 */
	private function prefixKey( string $key ): string {
		return $this->prefix . $key;
	}
}
