<?php
/**
 * Intent REST controller.
 *
 * @package AgentWP
 */

namespace AgentWP\Rest;

use AgentWP\API\RestController;
use AgentWP\Config\AgentWPConfig;
use AgentWP\Intent\Engine;
use WP_REST_Request;
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
	 * @openapi POST /agentwp/v1/intent
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request instance.
	 * @return \WP_REST_Response
	 */
	public function create_intent( $request ) {
		$validation = $this->validate_request( $request, $this->get_intent_schema() );
		if ( is_wp_error( $validation ) ) {
			return $this->response_error( AgentWPConfig::ERROR_CODE_INVALID_REQUEST, $validation->get_error_message(), 400 );
		}

		$prompt = '';
		if ( isset( $validation['prompt'] ) ) {
			$prompt = (string) $validation['prompt'];
		}

		if ( '' === $prompt && isset( $validation['input'] ) ) {
			$prompt = (string) $validation['input'];
		}

		$prompt = trim( $prompt );
		if ( '' === $prompt ) {
			return $this->response_error( AgentWPConfig::ERROR_CODE_MISSING_PROMPT, __( 'Please provide a prompt.', 'agentwp' ), 400 );
		}

		$context  = isset( $validation['context'] ) && is_array( $validation['context'] )
			? $validation['context']
			: array();
		$metadata = isset( $validation['metadata'] ) && is_array( $validation['metadata'] )
			? $validation['metadata']
			: array();

		$engine = $this->resolveRequired( Engine::class, 'Intent engine' );
		if ( $engine instanceof \WP_REST_Response ) {
			return $engine;
		}
		$response = $engine->handle( $prompt, $context, $metadata );

		if ( ! $response->is_success() ) {
			return $this->response_error(
				AgentWPConfig::ERROR_CODE_INTENT_FAILED,
				$response->get_message(),
				$response->get_status(),
				$response->get_meta()
			);
		}

		$data               = $response->get_data();
		$data['intent_id']  = wp_generate_uuid4();
		$data['status']     = 'handled';

		return $this->response_success( $data );
	}

	/**
	 * Maximum allowed prompt length to prevent DoS via excessive input.
	 */
	private const MAX_PROMPT_LENGTH = 10000;

	/**
	 * Schema for intent payload.
	 *
	 * @return array
	 */
	private function get_intent_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'prompt'   => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => self::MAX_PROMPT_LENGTH,
				),
				'input'    => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => self::MAX_PROMPT_LENGTH,
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
