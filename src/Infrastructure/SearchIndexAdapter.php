<?php
/**
 * Search index adapter.
 *
 * @package AgentWP\Infrastructure
 */

namespace AgentWP\Infrastructure;

use AgentWP\Contracts\SearchIndexInterface;
use AgentWP\Search\Index;

/**
 * Adapter wrapping the static Index class for DI-based access.
 */
final class SearchIndexAdapter implements SearchIndexInterface {

	/**
	 * Search indexed data.
	 *
	 * @param string   $query Search query.
	 * @param string[] $types Types to search (products, orders, customers).
	 * @param int      $limit Result limit.
	 * @return array<string, array<int, array{type: string, object_id: int, primary_text: string, secondary_text: string}>> Raw search results grouped by type.
	 */
	public function search( string $query, array $types, int $limit = self::DEFAULT_LIMIT ): array {
		return Index::search( $query, $types, $limit );
	}
}
