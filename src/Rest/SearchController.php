<?php
/**
 * Search REST controller.
 *
 * @package AgentWP
 */

namespace AgentWP\Rest;

use AgentWP\API\RestController;
use AgentWP\Search\Index;
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
		$validation = $this->validate_request( $request, $this->get_search_schema(), 'query' );
		if ( is_wp_error( $validation ) ) {
			return $this->response_error( 'agentwp_invalid_request', $validation->get_error_message(), 400 );
		}

		$params = $request->get_query_params();
		$query  = isset( $params['q'] ) ? sanitize_text_field( (string) $params['q'] ) : '';
		$types  = array();

		if ( isset( $params['types'] ) ) {
			if ( is_array( $params['types'] ) ) {
				$types = $params['types'];
			} else {
				$types = explode( ',', (string) $params['types'] );
			}
		}

		$types   = array_map( 'trim', $types );
		$results = Index::search( $query, $types, Index::DEFAULT_LIMIT );

		return $this->response_success(
			array(
				'query'   => $query,
				'results' => $results,
			)
		);
	}

	/**
	 * Schema for search query params.
	 *
	 * @return array
	 */
	private function get_search_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'q'     => array(
					'type'      => 'string',
					'minLength' => 1,
				),
				'types' => array(
					'type' => array( 'string', 'array' ),
				),
			),
			'required'   => array( 'q' ),
		);
	}
}
