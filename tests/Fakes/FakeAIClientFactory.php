<?php
/**
 * Fake AI client factory for unit tests.
 */

namespace AgentWP\Tests\Fakes;

use AgentWP\Contracts\AIClientFactoryInterface;
use AgentWP\Contracts\OpenAIClientInterface;

final class FakeAIClientFactory implements AIClientFactoryInterface {
	private OpenAIClientInterface $client;
	private bool $hasApiKey;

	public function __construct( OpenAIClientInterface $client, bool $hasApiKey = true ) {
		$this->client    = $client;
		$this->hasApiKey = $hasApiKey;
	}

	/**
	 * {@inheritDoc}
	 */
	public function create( string $intent, array $options = array() ): OpenAIClientInterface {
		unset( $intent, $options );
		return $this->client;
	}

	/**
	 * {@inheritDoc}
	 */
	public function hasApiKey(): bool {
		return $this->hasApiKey;
	}
}
