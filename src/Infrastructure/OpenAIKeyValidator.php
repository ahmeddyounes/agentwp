<?php
/**
 * OpenAI key validator implementation.
 *
 * @package AgentWP\Infrastructure
 */

namespace AgentWP\Infrastructure;

use AgentWP\Config\AgentWPConfig;
use AgentWP\Contracts\HttpClientInterface;
use AgentWP\Contracts\OpenAIKeyValidatorInterface;
use WP_Error;

/**
 * Validates OpenAI API keys using the HTTP client interface.
 */
final class OpenAIKeyValidator implements OpenAIKeyValidatorInterface {

	/**
	 * HTTP client.
	 *
	 * @var HttpClientInterface
	 */
	private HttpClientInterface $httpClient;

	/**
	 * Create a new OpenAIKeyValidator.
	 *
	 * @param HttpClientInterface $httpClient HTTP client for making requests.
	 */
	public function __construct( HttpClientInterface $httpClient ) {
		$this->httpClient = $httpClient;
	}

	/**
	 * {@inheritDoc}
	 */
	public function validate( string $api_key ): bool|WP_Error {
		// Get base URL and timeout from centralized config with filter support.
		$base_url = AgentWPConfig::get( 'openai.api_base_url', AgentWPConfig::OPENAI_API_BASE_URL );
		$timeout  = (int) AgentWPConfig::get( 'openai.validation_timeout', AgentWPConfig::OPENAI_VALIDATION_TIMEOUT );

		$response = $this->httpClient->get(
			rtrim( $base_url, '/' ) . '/models',
			array(
				'timeout'     => $timeout,
				'redirection' => 0,
				'sslverify'   => true,
				'headers'     => array(
					'Authorization' => 'Bearer ' . $api_key,
				),
			)
		);

		// Network/connection failure (no HTTP status).
		if ( ! $response->success && 0 === $response->statusCode ) {
			return new WP_Error(
				AgentWPConfig::ERROR_CODE_OPENAI_UNREACHABLE,
				__( 'OpenAI API is unreachable.', 'agentwp' )
			);
		}

		// HTTP error response (4xx/5xx) - key invalid or rejected.
		if ( ! $response->success || 200 !== $response->statusCode ) {
			return new WP_Error(
				AgentWPConfig::ERROR_CODE_OPENAI_INVALID,
				__( 'OpenAI rejected the API key.', 'agentwp' )
			);
		}

		return true;
	}
}
