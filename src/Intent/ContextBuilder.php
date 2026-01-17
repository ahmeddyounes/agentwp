<?php
/**
 * Build enriched intent context.
 *
 * @package AgentWP
 */

namespace AgentWP\Intent;

use AgentWP\Contracts\ContextBuilderInterface;
use AgentWP\Intent\ContextProviders\ContextProviderInterface;

class ContextBuilder implements ContextBuilderInterface {
	/**
	 * Context providers keyed by their context key.
	 *
	 * @var array<string, ContextProviderInterface>
	 */
	private array $providers = array();

	/**
	 * Create a new ContextBuilder.
	 *
	 * Providers should be passed as an associative array keyed by context key.
	 * When wired via the container, providers come from the 'intent.context_provider' tag.
	 *
	 * @param array<string, ContextProviderInterface> $providers Context providers keyed by context key.
	 */
	public function __construct( array $providers = array() ) {
		$this->providers = $providers;
	}

	/**
	 * Build context with store and user data.
	 *
	 * Iterates through registered providers in registration order,
	 * calling each provider's provide() method and adding the result
	 * to the enriched context under the provider's context key.
	 *
	 * @param array $context Request context.
	 * @param array $metadata Request metadata.
	 * @return array Enriched context with 'request', 'metadata', and provider data.
	 */
	public function build( array $context = array(), array $metadata = array() ): array {
		$enriched = array(
			'request'  => $context,
			'metadata' => $metadata,
		);

		// Apply all context providers in registration order.
		foreach ( $this->providers as $key => $provider ) {
			if ( $provider instanceof ContextProviderInterface ) {
				$enriched[ $key ] = $provider->provide( $context, $metadata );
			}
		}

		return $enriched;
	}

	/**
	 * Add a context provider at runtime.
	 *
	 * Note: For production use, prefer registering providers via the container
	 * with the 'intent.context_provider' tag. This method is primarily for
	 * testing or dynamic provider registration.
	 *
	 * @param string                   $key      Provider key (used as context key).
	 * @param ContextProviderInterface $provider Provider instance.
	 * @return void
	 * @throws \InvalidArgumentException If key is empty.
	 */
	public function add_provider( string $key, ContextProviderInterface $provider ): void {
		if ( empty( $key ) ) {
			throw new \InvalidArgumentException( 'Provider key cannot be empty' );
		}

		$this->providers[ $key ] = $provider;
	}
}
