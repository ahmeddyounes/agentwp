<?php
/**
 * Intent REST controller.
 *
 * @package AgentWP
 */

namespace AgentWP\Rest;

use AgentWP\Rest\RestController;
use AgentWP\Config\AgentWPConfig;
use AgentWP\DTO\IntentRequestDTO;
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
		$dto = new IntentRequestDTO( $request );

		if ( ! $dto->isValid() ) {
			$error = $dto->getError();
			return $this->response_error(
				AgentWPConfig::ERROR_CODE_INVALID_REQUEST,
				$error ? $error->get_error_message() : __( 'Invalid request.', 'agentwp' ),
				400
			);
		}

		if ( ! $dto->hasPrompt() ) {
			return $this->response_error( AgentWPConfig::ERROR_CODE_MISSING_PROMPT, __( 'Please provide a prompt.', 'agentwp' ), 400 );
		}

		$engine = $this->resolveRequired( Engine::class, 'Intent engine' );
		if ( $engine instanceof \WP_REST_Response ) {
			return $engine;
		}

		$response = $engine->handle(
			$dto->getPrompt(),
			$dto->getContext(),
			$dto->getMetadata()
		);

		if ( ! $response->is_success() ) {
			return $this->response_error(
				AgentWPConfig::ERROR_CODE_INTENT_FAILED,
				$response->get_message(),
				$response->get_status(),
				$response->get_meta()
			);
		}

		$data              = $response->get_data();
		$data['intent_id'] = wp_generate_uuid4();
		$data['status']    = 'handled';

		return $this->response_success( $data );
	}
}
