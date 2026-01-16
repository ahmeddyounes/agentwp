<?php
/**
 * AI client factory interface.
 *
 * @package AgentWP\Contracts
 */

namespace AgentWP\Contracts;

/**
 * Interface for creating AI clients.
 */
interface AIClientFactoryInterface {

	/**
	 * Create an AI client for the given intent.
	 *
	 * @param string $intent Intent identifier for usage tracking.
	 * @param array  $options Optional configuration overrides.
	 * @return OpenAIClientInterface
	 */
	public function create( string $intent, array $options = array() ): OpenAIClientInterface;

	/**
	 * Check if an API key is configured.
	 *
	 * @return bool True if API key is set, false otherwise.
	 */
	public function hasApiKey(): bool;
}
