<?php
/**
 * Base REST controller.
 *
 * @package AgentWP
 */

namespace AgentWP\Rest;

use AgentWP\Config\AgentWPConfig;
use AgentWP\Container\ContainerInterface;
use AgentWP\Contracts\AtomicRateLimiterInterface;
use AgentWP\Contracts\RateLimiterInterface;
use AgentWP\Error\Handler as ErrorHandler;
use AgentWP\Plugin;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;

abstract class RestController extends WP_REST_Controller {
	const REST_NAMESPACE = AgentWPConfig::REST_NAMESPACE;

	/**
	 * Initialize the REST namespace.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->namespace = self::REST_NAMESPACE;
	}

	/**
	 * Get the plugin container instance.
	 *
	 * @return ContainerInterface|null
	 */
	protected function container(): ?ContainerInterface {
		return Plugin::container();
	}

	/**
	 * Resolve a dependency from the container with a null fallback.
	 *
	 * Controllers should prefer resolving interfaces when available.
	 *
	 * @param string $id Service identifier.
	 * @return mixed|null
	 */
	protected function resolve( string $id ) {
		$container = $this->container();
		if ( ! $container || ! $container->has( $id ) ) {
			return null;
		}

		try {
			return $container->get( $id );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Resolve a required dependency from the container.
	 *
	 * Returns an error response if the dependency cannot be resolved.
	 * Controllers should use this for critical domain services that must
	 * be properly configured via the container.
	 *
	 * @param string $id           Service identifier.
	 * @param string $service_name Human-readable name for error messages.
	 * @return mixed|WP_REST_Response Service instance or error response.
	 */
	protected function resolveRequired( string $id, string $service_name = 'Service' ) {
		$container = $this->container();
		if ( ! $container ) {
			return $this->response_error(
				AgentWPConfig::ERROR_CODE_SERVICE_UNAVAILABLE,
				/* translators: %s: service name */
				sprintf( __( '%s unavailable: container not initialized.', 'agentwp' ), $service_name ),
				500
			);
		}

		if ( ! $container->has( $id ) ) {
			return $this->response_error(
				AgentWPConfig::ERROR_CODE_SERVICE_UNAVAILABLE,
				/* translators: %s: service name */
				sprintf( __( '%s unavailable: not registered in container.', 'agentwp' ), $service_name ),
				500
			);
		}

		try {
			$service = $container->get( $id );
			if ( null === $service ) {
				return $this->response_error(
					AgentWPConfig::ERROR_CODE_SERVICE_UNAVAILABLE,
					/* translators: %s: service name */
					sprintf( __( '%s unavailable: resolved to null.', 'agentwp' ), $service_name ),
					500
				);
			}
			return $service;
		} catch ( \Throwable $e ) {
			return $this->response_error(
				AgentWPConfig::ERROR_CODE_SERVICE_UNAVAILABLE,
				/* translators: %s: service name */
				sprintf( __( '%s unavailable: %s', 'agentwp' ), $service_name, $e->getMessage() ),
				500
			);
		}
	}

	/**
	 * Permissions check for REST endpoints.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request instance.
	 * @return true|WP_Error
	 */
	public function permissions_check( $request ) {
		$capability = AgentWPConfig::get( 'rest.capability', AgentWPConfig::REST_CAPABILITY );
		if ( ! current_user_can( $capability ) ) {
			return new WP_Error(
				AgentWPConfig::ERROR_CODE_FORBIDDEN,
				__( 'Sorry, you are not allowed to access AgentWP.', 'agentwp' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		$nonce_error = $this->verify_nonce( $request );
		if ( is_wp_error( $nonce_error ) ) {
			return $nonce_error;
		}

		$rate_error = $this->check_rate_limit_via_service();
		if ( is_wp_error( $rate_error ) ) {
			return $rate_error;
		}

		return true;
	}

	/**
	 * Check rate limit using the injected RateLimiterInterface service.
	 *
	 * Uses atomic checkAndIncrement() when the limiter implements
	 * AtomicRateLimiterInterface, otherwise falls back to check() + increment().
	 *
	 * @return true|WP_Error True if within limits, WP_Error if exceeded.
	 */
	protected function check_rate_limit_via_service() {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return true;
		}

		$rateLimiter = $this->resolve( RateLimiterInterface::class );
		if ( ! $rateLimiter instanceof RateLimiterInterface ) {
			// Fail open if rate limiter is unavailable.
			return true;
		}

		// Prefer atomic check-and-increment to prevent race conditions.
		if ( $rateLimiter instanceof AtomicRateLimiterInterface ) {
			if ( ! $rateLimiter->checkAndIncrement( $user_id ) ) {
				$retryAfter = $rateLimiter->getRetryAfter( $user_id );
				return new WP_Error(
					AgentWPConfig::ERROR_CODE_RATE_LIMITED,
					__( 'Rate limit exceeded. Please retry later.', 'agentwp' ),
					array(
						'status'      => 429,
						'retry_after' => $retryAfter,
					)
				);
			}
			return true;
		}

		// Fallback to non-atomic check + increment.
		if ( ! $rateLimiter->check( $user_id ) ) {
			$retryAfter = $rateLimiter->getRetryAfter( $user_id );
			return new WP_Error(
				AgentWPConfig::ERROR_CODE_RATE_LIMITED,
				__( 'Rate limit exceeded. Please retry later.', 'agentwp' ),
				array(
					'status'      => 429,
					'retry_after' => $retryAfter,
				)
			);
		}

		$rateLimiter->increment( $user_id );
		return true;
	}

	/**
	 * Validate request payload against a JSON schema.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request instance.
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
	 * Verify REST nonce for state-changing requests.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request instance.
	 * @return true|WP_Error
	 */
	protected function verify_nonce( $request ) {
		$method = strtoupper( $request->get_method() );
		if ( in_array( $method, array( 'GET', 'HEAD', 'OPTIONS' ), true ) ) {
			return true;
		}

		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce ) {
			$nonce = $request->get_param( '_wpnonce' );
		}
		if ( ! $nonce ) {
			$nonce = $request->get_param( '_wp_rest_nonce' );
		}

		$nonce = is_string( $nonce ) ? $nonce : '';
		if ( '' === $nonce ) {
			return new WP_Error(
				AgentWPConfig::ERROR_CODE_MISSING_NONCE,
				__( 'Missing security nonce.', 'agentwp' ),
				array( 'status' => 403 )
			);
		}

		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error(
				AgentWPConfig::ERROR_CODE_INVALID_NONCE,
				__( 'Invalid security nonce.', 'agentwp' ),
				array( 'status' => 403 )
			);
		}

		return true;
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
	 * This is the canonical method for returning errors from controller callbacks.
	 * WP_Error should only be used in permission callbacks (WordPress requirement).
	 *
	 * @param string $code Error code.
	 * @param string $message Error message.
	 * @param int    $status HTTP status.
	 * @param array  $meta Optional error metadata (e.g., retry_after for rate limits).
	 * @return WP_REST_Response
	 */
	protected function response_error( $code, $message, $status = 400, array $meta = array() ) {
		$type = class_exists( 'AgentWP\\Error\\Handler' )
			? ErrorHandler::categorize( $code, $status, $message, $meta )
			: 'unknown';

		$response = rest_ensure_response(
			array(
				'success' => false,
				'data'    => array(),
				'error'   => array(
					'code'    => $code,
					'message' => $message,
					'type'    => $type,
					'meta'    => $meta,
				),
			)
		);

		if ( $response instanceof WP_REST_Response ) {
			$response->set_status( $status );

			// Set Retry-After header for rate-limited responses.
			if ( isset( $meta['retry_after'] ) ) {
				$response->header( 'Retry-After', (string) intval( $meta['retry_after'] ) );
			}
		}

		return $response;
	}

	/**
	 * Log REST request metadata for debugging.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request instance.
	 * @param int                                  $status HTTP status.
	 * @param string                               $error_code Optional error code.
	 * @return void
	 */
	public static function log_request( $request, $status, $error_code = '' ) {
		$user_id = get_current_user_id();
		$key     = Plugin::TRANSIENT_PREFIX . AgentWPConfig::CACHE_PREFIX_REST_LOG . ( $user_id ? $user_id : 'guest' );
		$logs    = get_transient( $key );
		$logs    = is_array( $logs ) ? $logs : array();

		$body       = $request->get_json_params();
		$body_keys  = is_array( $body ) ? array_keys( $body ) : array();
		$query      = $request->get_query_params();
		$query_keys = is_array( $query ) ? array_keys( $query ) : array();

		$entry = array(
			'time'       => gmdate( 'c' ),
			'route'      => $request->get_route(),
			'method'     => $request->get_method(),
			'status'     => intval( $status ),
			'error'      => $error_code,
			'user_id'    => intval( $user_id ),
			'query_keys' => $query_keys,
			'body_keys'  => $body_keys,
		);

		$logs[] = $entry;
		$logLimit = (int) AgentWPConfig::get( 'rest.log_limit', AgentWPConfig::REST_LOG_LIMIT );
		if ( count( $logs ) > $logLimit ) {
			$logs = array_slice( $logs, -1 * $logLimit );
		}

		set_transient( $key, $logs, DAY_IN_SECONDS );
	}
}
