<?php
/**
 * Session handler adapter backed by WordPress transients.
 *
 * @package AgentWP\Infrastructure
 */

namespace AgentWP\Infrastructure;

use AgentWP\Contracts\SessionHandlerInterface;

/**
 * Provides session-like key/value storage without using PHP sessions.
 */
final class PhpSessionHandler implements SessionHandlerInterface {
	/**
	 * Default TTL for stored values (in seconds).
	 */
	private const DEFAULT_TTL = 1800;

	/**
	 * Key prefix.
	 *
	 * @var string
	 */
	private string $prefix;

	/**
	 * TTL in seconds.
	 *
	 * @var int
	 */
	private int $ttl;

	/**
	 * Whether storage is ready.
	 *
	 * @var bool
	 */
	private bool $started = false;

	/**
	 * Request-local fallback store for non-WordPress contexts.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $fallback = array();

	/**
	 * Create a new handler.
	 *
	 * @param string $prefix Key prefix.
	 * @param int    $ttl    TTL in seconds.
	 */
	public function __construct( string $prefix = 'agentwp_', int $ttl = self::DEFAULT_TTL ) {
		$this->prefix = $prefix;
		$this->ttl    = max( 60, $ttl );
	}

	/**
	 * {@inheritDoc}
	 */
	public function ensureStarted(): void {
		$this->started = true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function isStarted(): bool {
		return $this->started;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get( string $key ): mixed {
		$this->ensureStarted();

		$store       = $this->loadStore();
		$prefixedKey = $this->prefixKey( $key );

		return $store[ $prefixedKey ] ?? null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function set( string $key, mixed $value ): void {
		$this->ensureStarted();

		$store = $this->loadStore();
		$store[ $this->prefixKey( $key ) ] = $value;
		$this->persistStore( $store );
	}

	/**
	 * {@inheritDoc}
	 */
	public function has( string $key ): bool {
		$this->ensureStarted();

		$store = $this->loadStore();
		return array_key_exists( $this->prefixKey( $key ), $store );
	}

	/**
	 * {@inheritDoc}
	 */
	public function delete( string $key ): void {
		$this->ensureStarted();

		$store       = $this->loadStore();
		$prefixedKey = $this->prefixKey( $key );

		if ( ! array_key_exists( $prefixedKey, $store ) ) {
			return;
		}

		unset( $store[ $prefixedKey ] );
		$this->persistStore( $store );
	}

	/**
	 * Clear all keys for this prefix.
	 *
	 * @return void
	 */
	public function clear(): void {
		$this->ensureStarted();

		$this->persistStore( array() );
	}

	/**
	 * Destroy all stored keys.
	 *
	 * @return void
	 */
	public function destroy(): void {
		$storeKey = $this->getStoreKey();
		if ( '' !== $storeKey && function_exists( 'delete_transient' ) ) {
			delete_transient( $storeKey );
			return;
		}

		unset( self::$fallback[ $this->getFallbackKey() ] );
	}

	/**
	 * Load store values.
	 *
	 * @return array<string, mixed>
	 */
	private function loadStore(): array {
		$storeKey = $this->getStoreKey();
		if ( '' !== $storeKey && function_exists( 'get_transient' ) ) {
			$value = get_transient( $storeKey );
			return is_array( $value ) ? $value : array();
		}

		$fallbackKey = $this->getFallbackKey();
		if ( ! isset( self::$fallback[ $fallbackKey ] ) || ! is_array( self::$fallback[ $fallbackKey ] ) ) {
			self::$fallback[ $fallbackKey ] = array();
		}

		return self::$fallback[ $fallbackKey ];
	}

	/**
	 * Persist store values.
	 *
	 * @param array<string, mixed> $store Store values.
	 * @return void
	 */
	private function persistStore( array $store ): void {
		$storeKey = $this->getStoreKey();
		if ( '' !== $storeKey && function_exists( 'set_transient' ) ) {
			set_transient( $storeKey, $store, $this->ttl );
			return;
		}

		self::$fallback[ $this->getFallbackKey() ] = $store;
	}

	/**
	 * Resolve transient key used for persistence.
	 *
	 * @return string Transient key, or empty string when unavailable.
	 */
	private function getStoreKey(): string {
		if ( ! function_exists( 'get_current_user_id' ) ) {
			return '';
		}

		$user_id = (int) get_current_user_id();
		if ( $user_id <= 0 ) {
			return '';
		}

		return $this->prefix . 'session_' . $user_id;
	}

	/**
	 * Resolve fallback key for non-WordPress contexts.
	 *
	 * @return string
	 */
	private function getFallbackKey(): string {
		$storeKey = $this->getStoreKey();
		if ( '' !== $storeKey ) {
			return $storeKey;
		}

		return $this->prefix . 'session_request';
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
