<?php
/**
 * WordPress options adapter.
 *
 * @package AgentWP\Infrastructure
 */

namespace AgentWP\Infrastructure;

use AgentWP\Contracts\OptionsInterface;

/**
 * Wraps WordPress options functions.
 */
final class WordPressOptions implements OptionsInterface {

	/**
	 * Option name prefix.
	 *
	 * @var string
	 */
	private string $prefix;

	/**
	 * Create a new WordPressOptions.
	 *
	 * @param string $prefix Option name prefix.
	 */
	public function __construct( string $prefix = 'agentwp_' ) {
		$this->prefix = $prefix;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get( string $key, mixed $default = null ): mixed {
		$value = get_option( $this->prefixKey( $key ), $default );

		return $value;
	}

	/**
	 * {@inheritDoc}
	 */
	public function set( string $key, mixed $value, bool $autoload = true ): bool {
		return update_option( $this->prefixKey( $key ), $value, $autoload );
	}

	/**
	 * {@inheritDoc}
	 */
	public function delete( string $key ): bool {
		return delete_option( $this->prefixKey( $key ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function has( string $key ): bool {
		$value = get_option( $this->prefixKey( $key ), '__agentwp_not_found__' );

		return '__agentwp_not_found__' !== $value;
	}

	/**
	 * Prefix an option key.
	 *
	 * @param string $key The key.
	 * @return string
	 */
	private function prefixKey( string $key ): string {
		return $this->prefix . $key;
	}
}
