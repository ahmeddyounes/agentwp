<?php
/**
 * Search service implementation.
 *
 * @package AgentWP\Services
 */

namespace AgentWP\Services;

use AgentWP\Contracts\SearchServiceInterface;
use AgentWP\Search\Index;

/**
 * Search service that wraps the Index for DI-based access.
 */
final class SearchService implements SearchServiceInterface {

	/**
	 * Search indexed data.
	 *
	 * @param string   $query Search query.
	 * @param string[] $types Types to search (products, orders, customers).
	 * @param int      $limit Result limit.
	 * @return array<string, array> Search results grouped by type.
	 */
	public function search( string $query, array $types, int $limit = self::DEFAULT_LIMIT ): array {
		return Index::search( $query, $types, $limit );
	}
}
