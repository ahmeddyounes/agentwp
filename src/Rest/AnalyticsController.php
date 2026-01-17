<?php
/**
 * Analytics REST controller.
 *
 * @package AgentWP\Rest
 */

namespace AgentWP\Rest;

use AgentWP\API\RestController;
use AgentWP\Contracts\AnalyticsServiceInterface;
use WP_REST_Server;

class AnalyticsController extends RestController {

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/analytics',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_analytics' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'period' => array(
							'default'           => '7d',
							'validate_callback' => function ( $param ) {
								return in_array( $param, array( '7d', '30d', '90d' ), true );
							},
						),
					),
				),
			)
		);
	}

	/**
	 * Get analytics data.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_analytics( $request ) {
		$period = $request->get_param( 'period' );

		$service = $this->resolveRequired( AnalyticsServiceInterface::class, 'Analytics service' );
		if ( $service instanceof \WP_REST_Response ) {
			return $service;
		}

		$data = $service->get_stats( $period );

		return $this->response_success( $data );
	}
}
