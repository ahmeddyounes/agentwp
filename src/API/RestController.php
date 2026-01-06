<?php
/**
 * Base REST controller.
 *
 * @package AgentWP
 */

namespace AgentWP\API;

use AgentWP\Plugin;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Response;

abstract class RestController extends WP_REST_Controller {
	const REST_NAMESPACE = 'agentwp/v1';
	const RATE_LIMIT     = 60;
	const RATE_WINDOW    = 60;
	const LOG_LIMIT      = 50;

	public function __construct() {
		$this->namespace = self::REST_NAMESPACE;
	}

	/**
	 * Permissions check for REST endpoints.
	 *
	 * @param WP_REST_Request $request Request instance.
	 * @return true|WP_Error
	 */
	public function permissions_check( $request ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return new WP_Error(
				'agentwp_forbidden',
				__( 'Sorry, you are not allowed to access AgentWP.', 'agentwp' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		$rate_error = self::check_rate_limit( $request );
		if ( is_wp_error( $rate_error ) ) {
			return $rate_error;
		}

		return true;
	}

	/**
	 * Validate request payload against a JSON schema.
	 *
	 * @param WP_REST_Request $request Request instance.
	 * @param array           $schema JSON schema.
	 * @param string          $source Payload source ("json" or "query").
	 * @return array|WP_Error
	 */
	protected function validate_request( $request, array $schema, $source = 'json' ) {
		$payload = ( 'query' === $source ) ? $request->get_query_params() : $request->get_json_params();
		$payload = is_array( $payload ) ? $payload : array();

		$schema['type'] = isset( $schema['type'] ) ? $schema['type'] : 'object';
		$valid          = rest_validate_value_from_schema( $payload, $schema, 'request' );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		return $payload;
	}

	/**
	 * Build success response.
	 *
	 * @param mixed $data Response data.
	 * @param int   $status HTTP status.
	 * @return WP_REST_Response
	 */
	protected function response_success( $data, $status = 200 ) {
		$response = rest_ensure_response(
			array(
				'success' => true,
				'data'    => $data,
			)
		);

		if ( $response instanceof WP_REST_Response ) {
			$response->set_status( $status );
		}

		return $response;
	}

	/**
	 * Build error response.
	 *
	 * @param string $code Error code.
	 * @param string $message Error message.
	 * @param int    $status HTTP status.
	 * @return WP_REST_Response
	 */
	protected function response_error( $code, $message, $status = 400 ) {
		$response = rest_ensure_response(
			array(
				'success' => false,
				'data'    => array(),
				'error'   => array(
					'code'    => $code,
					'message' => $message,
				),
			)
		);

		if ( $response instanceof WP_REST_Response ) {
			$response->set_status( $status );
		}

		return $response;
	}

	/**
	 * Rate limiter using per-user transients.
	 *
	 * @param WP_REST_Request $request Request instance.
	 * @return true|WP_Error
	 */
	public static function check_rate_limit( $request ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}

		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return true;
		}

		$key    = Plugin::TRANSIENT_PREFIX . 'rate_' . $user_id;
		$bucket = get_transient( $key );
		$now    = time();

		if ( ! is_array( $bucket ) ) {
			$bucket = array(
				'start' => $now,
				'count' => 0,
			);
		}

		if ( $now - intval( $bucket['start'] ) >= self::RATE_WINDOW ) {
			$bucket = array(
				'start' => $now,
				'count' => 0,
			);
		}

		if ( intval( $bucket['count'] ) >= self::RATE_LIMIT ) {
			$retry_after = max( 1, self::RATE_WINDOW - ( $now - intval( $bucket['start'] ) ) );

			return new WP_Error(
				'agentwp_rate_limited',
				__( 'Rate limit exceeded. Please retry later.', 'agentwp' ),
				array(
					'status'      => 429,
					'retry_after' => $retry_after,
				)
			);
		}

		$bucket['count'] = intval( $bucket['count'] ) + 1;
		set_transient( $key, $bucket, self::RATE_WINDOW );

		return true;
	}

	/**
	 * Log REST request metadata for debugging.
	 *
	 * @param WP_REST_Request $request Request instance.
	 * @param int             $status HTTP status.
	 * @param string          $error_code Optional error code.
	 * @return void
	 */
	public static function log_request( $request, $status, $error_code = '' ) {
		$user_id = get_current_user_id();
		$key     = Plugin::TRANSIENT_PREFIX . 'rest_log_' . ( $user_id ? $user_id : 'guest' );
		$logs    = get_transient( $key );
		$logs    = is_array( $logs ) ? $logs : array();

		$body      = $request->get_json_params();
		$body_keys = is_array( $body ) ? array_keys( $body ) : array();

		$entry = array(
			'time'      => gmdate( 'c' ),
			'route'     => $request->get_route(),
			'method'    => $request->get_method(),
			'status'    => intval( $status ),
			'error'     => $error_code,
			'user_id'   => intval( $user_id ),
			'ip'        => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
			'query'     => $request->get_query_params(),
			'body_keys' => $body_keys,
		);

		$logs[] = $entry;
		if ( count( $logs ) > self::LOG_LIMIT ) {
			$logs = array_slice( $logs, -1 * self::LOG_LIMIT );
		}

		set_transient( $key, $logs, DAY_IN_SECONDS );
	}
}
