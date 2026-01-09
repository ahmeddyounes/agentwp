<?php
/**
 * Fake memory store for testing.
 *
 * @package AgentWP\Tests\Fakes
 */

namespace AgentWP\Tests\Fakes;

use AgentWP\Contracts\MemoryStoreInterface;

/**
 * In-memory conversation memory store for testing.
 */
final class FakeMemoryStore implements MemoryStoreInterface {

	/**
	 * Memory entries.
	 *
	 * @var array<array{time: string, input: string, intent: string, message: string}>
	 */
	private array $memory = array();

	/**
	 * Maximum entries to keep.
	 *
	 * @var int
	 */
	private int $limit;

	/**
	 * Create a new FakeMemoryStore.
	 *
	 * @param int $limit Maximum entries to keep.
	 */
	public function __construct( int $limit = 5 ) {
		$this->limit = $limit;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get(): array {
		return $this->memory;
	}

	/**
	 * {@inheritDoc}
	 */
	public function addExchange( array $entry ): void {
		$this->memory[] = $entry;

		// Trim to limit.
		if ( count( $this->memory ) > $this->limit ) {
			$this->memory = array_slice( $this->memory, -$this->limit );
		}
	}

	/**
	 * Alias for addExchange for backward compatibility.
	 *
	 * @param array $entry Exchange entry.
	 * @return void
	 */
	public function add_exchange( array $entry ): void {
		$this->addExchange( $entry );
	}

	/**
	 * {@inheritDoc}
	 */
	public function clear(): void {
		$this->memory = array();
	}

	// Test helpers.

	/**
	 * Get the entry count.
	 *
	 * @return int
	 */
	public function count(): int {
		return count( $this->memory );
	}

	/**
	 * Get the last entry.
	 *
	 * @return array|null
	 */
	public function getLastEntry(): ?array {
		if ( empty( $this->memory ) ) {
			return null;
		}

		return $this->memory[ count( $this->memory ) - 1 ];
	}

	/**
	 * Get the first entry.
	 *
	 * @return array|null
	 */
	public function getFirstEntry(): ?array {
		if ( empty( $this->memory ) ) {
			return null;
		}

		return $this->memory[0];
	}

	/**
	 * Check if an intent was recorded.
	 *
	 * @param string $intent The intent to check.
	 * @return bool
	 */
	public function hasIntent( string $intent ): bool {
		foreach ( $this->memory as $entry ) {
			if ( ( $entry['intent'] ?? '' ) === $intent ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Set the limit.
	 *
	 * @param int $limit New limit.
	 * @return void
	 */
	public function setLimit( int $limit ): void {
		$this->limit = $limit;

		// Trim if needed.
		if ( count( $this->memory ) > $this->limit ) {
			$this->memory = array_slice( $this->memory, -$this->limit );
		}
	}
}
