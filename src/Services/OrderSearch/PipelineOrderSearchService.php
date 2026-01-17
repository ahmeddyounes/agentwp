<?php
/**
 * Pipeline-based order search service adapter.
 *
 * @package AgentWP\Services\OrderSearch
 */

namespace AgentWP\Services\OrderSearch;

use AgentWP\Contracts\OrderSearchServiceInterface;

/**
 * Adapts the pipeline-based order search to the legacy interface.
 *
 * This adapter implements OrderSearchServiceInterface by delegating to
 * the decomposed pipeline components (ArgumentNormalizer, OrderQueryService).
 */
final class PipelineOrderSearchService implements OrderSearchServiceInterface {

	/**
	 * Argument normalizer.
	 *
	 * @var ArgumentNormalizer
	 */
	private ArgumentNormalizer $normalizer;

	/**
	 * Order query service.
	 *
	 * @var OrderQueryService
	 */
	private OrderQueryService $queryService;

	/**
	 * Create a new PipelineOrderSearchService.
	 *
	 * @param ArgumentNormalizer $normalizer    Argument normalizer.
	 * @param OrderQueryService  $queryService  Order query service.
	 */
	public function __construct(
		ArgumentNormalizer $normalizer,
		OrderQueryService $queryService
	) {
		$this->normalizer   = $normalizer;
		$this->queryService = $queryService;
	}

	/**
	 * {@inheritDoc}
	 */
	public function handle( array $args ) {
		$query = $this->normalizer->normalize( $args );

		return $this->queryService->search( $query );
	}
}
