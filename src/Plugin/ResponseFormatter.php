<?php
/**
 * REST response formatter.
 *
 * @package AgentWP\Plugin
 */

namespace AgentWP\Plugin;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Normalizes REST API responses for AgentWP endpoints.
 */
final class ResponseFormatter {

	/**
	 * REST namespace to match.
	 */
	public const REST_NAMESPACE = 'agentwp/v1';

	/**
	 * Error categorizer callback.
	 *
	 * @var callable|null
	 */
	private $errorCategorizer;

	/**
	 * Request logger callback.
	 *
	 * @var callable|null
	 */
	private $requestLogger;

	/**
	 * Whether the filter has been registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Create a new ResponseFormatter.
	 *
	 * @param callable|null $errorCategorizer Callback to categorize errors.
	 * @param callable|null $requestLogger    Callback to log requests.
	 */
	public function __construct( ?callable $errorCategorizer = null, ?callable $requestLogger = null ) {
		$this->errorCategorizer = $errorCategorizer;
		$this->requestLogger    = $requestLogger;
	}

	/**
	 * Register the response filter.
	 *
	 * Prevents duplicate registration if called multiple times.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( $this->registered ) {
			return;
		}

		add_filter( 'rest_post_dispatch', array( $this, 'formatResponse' ), 10, 3 );
		$this->registered = true;
	}

	/**
	 * Format REST responses for AgentWP routes.
	 *
	 * @param mixed           $result  Response value.
	 * @param WP_REST_Server  $server  REST server instance.
	 * @param WP_REST_Request $request Request instance.
	 * @return mixed
	 */
	public function formatResponse( mixed $result, WP_REST_Server $server, WP_REST_Request $request ): mixed {
		if ( ! $this->isAgentWPRoute( $request ) ) {
			return $result;
		}

		$status    = 200;
		$errorCode = '';

		if ( is_wp_error( $result ) ) {
			$formatted = $this->formatWpError( $result );
			$result    = $formatted['response'];
			$status    = $formatted['status'];
			$errorCode = $formatted['error_code'];
		} elseif ( $result instanceof WP_REST_Response ) {
			$formatted = $this->formatRestResponse( $result );
			$result    = $formatted['response'];
			$status    = $formatted['status'];
			$errorCode = $formatted['error_code'];
		} else {
			$result = $this->formatSuccessResponse( $result );

			if ( $result instanceof WP_REST_Response ) {
				$status = $result->get_status();
			}
		}

		// Log the request.
		if ( null !== $this->requestLogger ) {
			( $this->requestLogger )( $request, $status, $errorCode );
		}

		// Add no-cache headers.
		if ( $result instanceof WP_REST_Response ) {
			$this->addNoCacheHeaders( $result );
		}

		return $result;
	}

	/**
	 * Format a WP_Error into a normalized response.
	 *
	 * @param WP_Error $error The error.
	 * @return array{response: WP_REST_Response, status: int, error_code: string}
	 */
	private function formatWpError( WP_Error $error ): array {
		$errorCode = $error->get_error_code();
		$message   = $error->get_error_message();
		$data      = $error->get_error_data();
		$status    = ( is_array( $data ) && isset( $data['status'] ) ) ? intval( $data['status'] ) : 500;
		$type      = $this->categorizeError( $errorCode, $status, $message, is_array( $data ) ? $data : array() );

		$errorMeta = array();
		if ( is_array( $data ) && isset( $data['retry_after'] ) ) {
			$errorMeta['retry_after'] = intval( $data['retry_after'] );
		}

		$response = rest_ensure_response(
			array(
				'success' => false,
				'data'    => array(),
				'error'   => array(
					'code'    => $errorCode,
					'message' => $message,
					'type'    => $type,
					'meta'    => $errorMeta,
				),
			)
		);

		if ( $response instanceof WP_REST_Response ) {
			$response->set_status( $status );

			if ( is_array( $data ) && isset( $data['retry_after'] ) ) {
				$response->header( 'Retry-After', (string) intval( $data['retry_after'] ) );
			}
		}

		return array(
			'response'   => $response,
			'status'     => $status,
			'error_code' => $errorCode,
		);
	}

	/**
	 * Format a WP_REST_Response that may contain an error.
	 *
	 * @param WP_REST_Response $response The response.
	 * @return array{response: WP_REST_Response, status: int, error_code: string}
	 */
	private function formatRestResponse( WP_REST_Response $response ): array {
		$status    = $response->get_status();
		$body      = $response->get_data();
		$errorCode = '';

		// Check for error format.
		if ( is_array( $body ) && isset( $body['code'], $body['message'] ) ) {
			$errorCode = (string) $body['code'];
			$message   = (string) $body['message'];
			$data      = isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : array();
			$status    = isset( $data['status'] ) ? intval( $data['status'] ) : $status;
			$type      = $this->categorizeError( $errorCode, $status, $message, $data );

			$errorMeta = array();
			if ( isset( $data['retry_after'] ) ) {
				$errorMeta['retry_after'] = intval( $data['retry_after'] );
			}

			$formatted = rest_ensure_response(
				array(
					'success' => false,
					'data'    => array(),
					'error'   => array(
						'code'    => $errorCode,
						'message' => $message,
						'type'    => $type,
						'meta'    => $errorMeta,
					),
				)
			);

			if ( $formatted instanceof WP_REST_Response ) {
				$formatted->set_status( $status );

				if ( isset( $data['retry_after'] ) ) {
					$formatted->header( 'Retry-After', (string) intval( $data['retry_after'] ) );
				}
			}

			return array(
				'response'   => $formatted,
				'status'     => $status,
				'error_code' => $errorCode,
			);
		}

		// Check for already formatted error.
		if ( is_array( $body ) && isset( $body['error']['code'] ) ) {
			$errorCode = (string) $body['error']['code'];
		}

		return array(
			'response'   => $response,
			'status'     => $status,
			'error_code' => $errorCode,
		);
	}

	/**
	 * Format a success response.
	 *
	 * @param mixed $data Response data.
	 * @return WP_REST_Response
	 */
	private function formatSuccessResponse( mixed $data ): WP_REST_Response {
		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $data,
			)
		);
	}

	/**
	 * Check if request is for an AgentWP route.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return bool
	 */
	private function isAgentWPRoute( WP_REST_Request $request ): bool {
		$route = $request->get_route();
		$route = is_string( $route ) ? $route : '';

		return 0 === strpos( $route, '/' . self::REST_NAMESPACE );
	}

	/**
	 * Categorize an error.
	 *
	 * @param string $code    Error code.
	 * @param int    $status  HTTP status.
	 * @param string $message Error message.
	 * @param array  $data    Error data.
	 * @return string
	 */
	private function categorizeError( string $code, int $status, string $message, array $data ): string {
		if ( null !== $this->errorCategorizer ) {
			return ( $this->errorCategorizer )( $code, $status, $message, $data );
		}

		return 'unknown';
	}

	/**
	 * Add no-cache headers to response.
	 *
	 * @param WP_REST_Response $response The response.
	 * @return void
	 */
	private function addNoCacheHeaders( WP_REST_Response $response ): void {
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'Expires', 'Wed, 11 Jan 1984 05:00:00 GMT' );
	}

	/**
	 * Set error categorizer callback.
	 *
	 * @param callable $callback Callback receiving (code, status, message, data).
	 * @return void
	 */
	public function setErrorCategorizer( callable $callback ): void {
		$this->errorCategorizer = $callback;
	}

	/**
	 * Set request logger callback.
	 *
	 * @param callable $callback Callback receiving (request, status, errorCode).
	 * @return void
	 */
	public function setRequestLogger( callable $callback ): void {
		$this->requestLogger = $callback;
	}
}
