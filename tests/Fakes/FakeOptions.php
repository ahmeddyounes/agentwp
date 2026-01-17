<?php
/**
 * Fake options storage for testing.
 *
 * @package AgentWP\Tests\Fakes
 */

namespace AgentWP\Tests\Fakes;

use AgentWP\Contracts\OptionsInterface;

/**
 * In-memory options storage for testing.
 */
final class FakeOptions implements OptionsInterface {

	/**
	 * Options storage.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * Create a new FakeOptions with optional initial values.
	 *
	 * @param array<string, mixed> $initial Initial options.
	 */
	public function __construct( array $initial = array() ) {
		$this->options = $initial;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get( string $key, mixed $default = null ): mixed {
		return $this->options[ $key ] ?? $default;
	}

	/**
	 * {@inheritDoc}
	 */
	public function set( string $key, mixed $value, bool $autoload = true ): bool {
		$this->options[ $key ] = $value;
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function delete( string $key ): bool {
		if ( ! isset( $this->options[ $key ] ) ) {
			return false;
		}

		unset( $this->options[ $key ] );
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function has( string $key ): bool {
		return isset( $this->options[ $key ] );
	}

	/**
	 * Get all options.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		return $this->options;
	}

	/**
	 * Clear all options.
	 *
	 * @return void
	 */
	public function clear(): void {
		$this->options = array();
	}
}
