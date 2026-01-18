<?php
/**
 * Search service implementation.
 *
 * @package AgentWP\Services
 */

namespace AgentWP\Services;

use AgentWP\Contracts\SearchServiceInterface;
use AgentWP\DTO\SearchResultsDTO;
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
		$rawResults = Index::search( $query, $types, $limit );

		// Validate structure via DTO (for internal consistency).
		$resultsDTO = SearchResultsDTO::fromArray( $query, $rawResults );

		// Return in the original format for backward compatibility.
		return $resultsDTO->toArray()['results'];
	}

	/**
	 * Search indexed data and return as DTO.
	 *
	 * @param string   $query Search query.
	 * @param string[] $types Types to search (products, orders, customers).
	 * @param int      $limit Result limit.
	 * @return SearchResultsDTO Search results DTO.
	 */
	public function searchAsDTO( string $query, array $types, int $limit = self::DEFAULT_LIMIT ): SearchResultsDTO {
		$rawResults = Index::search( $query, $types, $limit );

		return SearchResultsDTO::fromArray( $query, $rawResults );
	}
}
