<?php
/**
 * Search index interface.
 *
 * @package AgentWP\Contracts
 */

namespace AgentWP\Contracts;

/**
 * Contract for low-level search index access.
 *
 * This interface abstracts the static Index class, enabling
 * dependency injection and testability in consumers.
 */
interface SearchIndexInterface {

	/**
	 * Default result limit.
	 */
	public const DEFAULT_LIMIT = 5;

	/**
	 * Search indexed data.
	 *
	 * @param string   $query Search query.
	 * @param string[] $types Types to search (products, orders, customers).
	 * @param int      $limit Result limit.
	 * @return array<string, array<int, array{type: string, object_id: int, primary_text: string, secondary_text: string}>> Raw search results grouped by type.
	 */
	public function search( string $query, array $types, int $limit = self::DEFAULT_LIMIT ): array;
}
