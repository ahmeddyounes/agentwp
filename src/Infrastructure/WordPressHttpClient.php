<?php
/**
 * WordPress HTTP client adapter.
 *
 * @package AgentWP\Infrastructure
 */

namespace AgentWP\Infrastructure;

use AgentWP\Contracts\HttpClientInterface;
use AgentWP\DTO\HttpResponse;
use WP_Error;

/**
 * Wraps WordPress HTTP API functions.
 */
final class WordPressHttpClient implements HttpClientInterface {

	/**
	 * Default timeout in seconds.
	 *
	 * @var int
	 */
	private int $defaultTimeout;

	/**
	 * Create a new WordPressHttpClient.
	 *
	 * @param int $defaultTimeout Default request timeout.
	 */
	public function __construct( int $defaultTimeout = 30 ) {
		$this->defaultTimeout = $defaultTimeout;
	}

	/**
	 * {@inheritDoc}
	 */
	public function post( string $url, array $options = array() ): HttpResponse {
		$args = $this->buildArgs( $options );

		$response = wp_remote_post( $url, $args );

		return $this->parseResponse( $response );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get( string $url, array $options = array() ): HttpResponse {
		$args = $this->buildArgs( $options );

		if ( function_exists( 'vip_safe_wp_remote_get' ) ) {
			$response = vip_safe_wp_remote_get( $url, $args );
		} else {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get -- Fallback when VIP helper is unavailable.
			$response = wp_remote_get( $url, $args );
		}

		return $this->parseResponse( $response );
	}

	/**
	 * Build request arguments.
	 *
	 * @param array $options Request options.
	 * @return array
	 */
	private function buildArgs( array $options ): array {
		$args = array(
			'timeout' => $options['timeout'] ?? $this->defaultTimeout,
		);

		if ( isset( $options['headers'] ) ) {
			$args['headers'] = $options['headers'];
		}

		if ( isset( $options['body'] ) ) {
			$args['body'] = $options['body'];
		}

		if ( isset( $options['sslverify'] ) ) {
			$args['sslverify'] = $options['sslverify'];
		}

		if ( isset( $options['redirection'] ) ) {
			$args['redirection'] = $options['redirection'];
		}

		if ( isset( $options['blocking'] ) ) {
			$args['blocking'] = $options['blocking'];
		}

		return $args;
	}

	/**
	 * Parse WordPress HTTP response.
	 *
	 * @param array|WP_Error $response WordPress response.
	 * @return HttpResponse
	 */
	private function parseResponse( array|WP_Error $response ): HttpResponse {
		if ( is_wp_error( $response ) ) {
			// Cast error code to string since WP_Error::get_error_code() returns string|int.
			$errorCode = $response->get_error_code();
			return HttpResponse::error(
				$response->get_error_message(),
				is_int( $errorCode ) ? (string) $errorCode : $errorCode
			);
		}

		$statusCode = (int) wp_remote_retrieve_response_code( $response );
		$body       = wp_remote_retrieve_body( $response );
		$headers    = wp_remote_retrieve_headers( $response );

		// Convert headers to array if needed.
		if ( $headers instanceof \Requests_Utility_CaseInsensitiveDictionary ) {
			$headers = $headers->getAll();
		} elseif ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			$headers = $headers->getAll();
		} elseif ( ! is_array( $headers ) ) {
			$headers = array();
		}

		// Check for HTTP error status codes.
		if ( $statusCode >= 400 ) {
			return HttpResponse::error(
				$body ?: 'HTTP error',
				'http_' . $statusCode,
				$statusCode
			);
		}

		return HttpResponse::success( $body, $statusCode, $headers );
	}
}
