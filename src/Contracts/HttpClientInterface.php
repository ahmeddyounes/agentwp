<?php
/**
 * HTTP client interface.
 *
 * @package AgentWP\Contracts
 */

namespace AgentWP\Contracts;

use AgentWP\DTO\HttpResponse;

/**
 * Contract for HTTP client services.
 */
interface HttpClientInterface {

	/**
	 * Send a POST request.
	 *
	 * @param string $url     The URL to request.
	 * @param array  $options Request options (headers, body, timeout, etc.).
	 * @return HttpResponse The response.
	 */
	public function post( string $url, array $options = array() ): HttpResponse;

	/**
	 * Send a GET request.
	 *
	 * @param string $url     The URL to request.
	 * @param array  $options Request options (headers, timeout, etc.).
	 * @return HttpResponse The response.
	 */
	public function get( string $url, array $options = array() ): HttpResponse;
}
