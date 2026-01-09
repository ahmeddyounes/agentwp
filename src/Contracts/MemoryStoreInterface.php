<?php
/**
 * Memory store interface.
 *
 * @package AgentWP\Contracts
 */

namespace AgentWP\Contracts;

/**
 * Contract for conversation memory storage.
 */
interface MemoryStoreInterface {

	/**
	 * Get all stored memory entries.
	 *
	 * @return array<array{time: string, input: string, intent: string, message: string}>
	 */
	public function get(): array;

	/**
	 * Add an exchange to memory.
	 *
	 * @param array{time: string, input: string, intent: string, message: string} $entry The exchange entry.
	 * @return void
	 */
	public function addExchange( array $entry ): void;

	/**
	 * Clear all memory entries.
	 *
	 * @return void
	 */
	public function clear(): void;
}
