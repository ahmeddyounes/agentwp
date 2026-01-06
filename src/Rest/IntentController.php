<?php
/**
 * Intent REST controller.
 *
 * @package AgentWP
 */

namespace AgentWP\Rest;

use AgentWP\API\RestController;
use WP_REST_Server;

class IntentController extends RestController {
	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/intent',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_intent' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);
	}

	/**
	 * Handle intent requests.
	 *
	 * @param WP_REST_Request $request Request instance.
	 * @return \WP_REST_Response
	 */
	public function create_intent( $request ) {
		$validation = $this->validate_request( $request, $this->get_intent_schema() );
		if ( is_wp_error( $validation ) ) {
			return $this->response_error( 'agentwp_invalid_request', $validation->get_error_message(), 400 );
		}

		return $this->response_success(
			array(
				'intent_id' => wp_generate_uuid4(),
				'status'    => 'received',
				'message'   => __( 'Intent received for processing.', 'agentwp' ),
			)
		);
	}

	/**
	 * Schema for intent payload.
	 *
	 * @return array
	 */
	private function get_intent_schema() {
		return array(
			'type'     => 'object',
			'required'             => array( 'prompt' ),
			'properties'           => array(
				'prompt'   => array(
					'type'      => 'string',
					'minLength' => 1,
				),
				'context'  => array(
					'type' => 'object',
				),
				'metadata' => array(
					'type' => 'object',
				),
			),
		);
	}
}
