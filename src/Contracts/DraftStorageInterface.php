<?php
/**
 * Draft storage interface.
 *
 * @package AgentWP\Contracts
 */

namespace AgentWP\Contracts;

/**
 * Interface for draft storage operations.
 */
interface DraftStorageInterface {

	/**
	 * Generate a unique draft ID.
	 *
	 * @param string $prefix Prefix for the draft ID.
	 * @return string Generated draft ID.
	 */
	public function generate_id( string $prefix = 'draft' ): string;

	/**
	 * Store a draft.
	 *
	 * @param string $type    Draft type identifier (e.g., 'refund', 'status', 'stock').
	 * @param string $id      Draft ID.
	 * @param array  $data    Draft data.
	 * @param int    $ttl     Time to live in seconds.
	 * @return bool True on success, false on failure.
	 */
	public function store( string $type, string $id, array $data, int $ttl = 3600 ): bool;

	/**
	 * Retrieve a draft without deleting it.
	 *
	 * @param string $type Draft type identifier.
	 * @param string $id   Draft ID.
	 * @return array|null Draft data or null if not found/expired.
	 */
	public function get( string $type, string $id ): ?array;

	/**
	 * Claim and delete a draft (atomic get + delete).
	 *
	 * @param string $type Draft type identifier.
	 * @param string $id   Draft ID.
	 * @return array|null Draft data or null if not found/expired.
	 */
	public function claim( string $type, string $id ): ?array;

	/**
	 * Delete a draft.
	 *
	 * @param string $type Draft type identifier.
	 * @param string $id   Draft ID.
	 * @return bool True on success, false on failure.
	 */
	public function delete( string $type, string $id ): bool;
}
