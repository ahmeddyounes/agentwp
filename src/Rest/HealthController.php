<?php
/**
 * Health REST controller.
 *
 * @package AgentWP
 */

namespace AgentWP\Rest;

use AgentWP\API\RestController;
use WP_REST_Server;

class HealthController extends RestController {
	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/health',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_health' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);
	}

	/**
	 * Return health status.
	 *
	 * @param WP_REST_Request $request Request instance.
	 * @return \WP_REST_Response
	 */
	public function get_health( $request ) {
		return $this->response_success(
			array(
				'status'  => 'ok',
				'time'    => gmdate( 'c' ),
				'version' => defined( 'AGENTWP_VERSION' ) ? AGENTWP_VERSION : '',
			)
		);
	}
}
