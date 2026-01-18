<?php
/**
 * Analytics REST controller.
 *
 * @package AgentWP\Rest
 */

namespace AgentWP\Rest;

use AgentWP\API\RestController;
use AgentWP\Config\AgentWPConfig;
use AgentWP\Contracts\AnalyticsServiceInterface;
use AgentWP\DTO\AnalyticsQueryDTO;
use AgentWP\DTO\ServiceResult;
use WP_REST_Response;
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
				),
			)
		);
	}

	/**
	 * Get analytics data.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_analytics( $request ) {
		$dto = new AnalyticsQueryDTO( $request );

		if ( ! $dto->isValid() ) {
			$error = $dto->getError();
			return $this->response_error(
				AgentWPConfig::ERROR_CODE_INVALID_REQUEST,
				$error ? $error->get_error_message() : __( 'Invalid request.', 'agentwp' ),
				400
			);
		}

		$period = $dto->getPeriod();

		$service = $this->resolveRequired( AnalyticsServiceInterface::class, 'Analytics service' );
		if ( $service instanceof WP_REST_Response ) {
			return $service;
		}

		$result = $service->get_stats( $period );

		return $this->response_from_service_result( $result );
	}

	/**
	 * Convert a ServiceResult to a WP_REST_Response.
	 *
	 * @param ServiceResult $result Service result.
	 * @return WP_REST_Response
	 */
	protected function response_from_service_result( ServiceResult $result ): WP_REST_Response {
		if ( $result->isSuccess() ) {
			return $this->response_success( $result->data, $result->httpStatus );
		}

		return $this->response_error(
			$result->code,
			$result->message,
			$result->httpStatus,
			$result->data
		);
	}
}
