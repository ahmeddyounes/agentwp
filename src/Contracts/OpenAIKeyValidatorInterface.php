<?php
/**
 * OpenAI key validator interface.
 *
 * @package AgentWP\Contracts
 */

namespace AgentWP\Contracts;

/**
 * Contract for validating OpenAI API keys.
 */
interface OpenAIKeyValidatorInterface {

	/**
	 * Validate an OpenAI API key by making a test request.
	 *
	 * @param string $api_key The API key to validate.
	 * @return true|\WP_Error True if valid, WP_Error on failure.
	 */
	public function validate( string $api_key ): bool|\WP_Error;
}
