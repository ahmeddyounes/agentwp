<?php
/**
 * OpenAI client interface.
 *
 * @package AgentWP\Contracts
 */

namespace AgentWP\Contracts;

use AgentWP\AI\Response;

/**
 * Contract for OpenAI API client services.
 */
interface OpenAIClientInterface {

	/**
	 * Send a chat completion request.
	 *
	 * @param array $messages  The messages array.
	 * @param array $functions The available functions/tools.
	 * @return Response The API response.
	 */
	public function chat( array $messages, array $functions ): Response;

	/**
	 * Validate an API key.
	 *
	 * @param string $key The API key to validate.
	 * @return bool True if valid, false otherwise.
	 */
	public function validateKey( string $key ): bool;
}
