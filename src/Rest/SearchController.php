<?php
/**
 * Search REST controller.
 *
 * @package AgentWP
 */

namespace AgentWP\Rest;

use AgentWP\API\RestController;
use AgentWP\Config\AgentWPConfig;
use AgentWP\Contracts\SearchServiceInterface;
use AgentWP\DTO\SearchQueryDTO;
use WP_REST_Server;

class SearchController extends RestController {
	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/search',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'search' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);
	}

	/**
	 * Handle search requests.
	 *
	 * @openapi GET /agentwp/v1/search
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request instance.
	 * @return \WP_REST_Response
	 */
	public function search( $request ) {
		$dto = new SearchQueryDTO( $request );

		if ( ! $dto->isValid() ) {
			$error = $dto->getError();
			return $this->response_error(
				AgentWPConfig::ERROR_CODE_INVALID_REQUEST,
				$error ? $error->get_error_message() : __( 'Invalid request.', 'agentwp' ),
				400
			);
		}

		$query = $dto->getQuery();
		$types = $dto->getTypes();

		$searchService = $this->resolveRequired( SearchServiceInterface::class, 'Search service' );
		if ( $searchService instanceof \WP_REST_Response ) {
			return $searchService;
		}

		$results = $searchService->search( $query, $types, SearchServiceInterface::DEFAULT_LIMIT );

		return $this->response_success(
			array(
				'query'   => $query,
				'results' => $results,
			)
		);
	}
}
