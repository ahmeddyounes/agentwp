<?php
/**
 * Search service interface.
 *
 * @package AgentWP\Contracts
 */

namespace AgentWP\Contracts;

/**
 * Contract for search index operations.
 */
interface SearchServiceInterface {

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
	 * @return array<string, array> Search results grouped by type.
	 */
	public function search( string $query, array $types, int $limit = self::DEFAULT_LIMIT ): array;
}
