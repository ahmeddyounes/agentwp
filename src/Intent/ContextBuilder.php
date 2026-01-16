<?php
/**
 * Build enriched intent context.
 *
 * @package AgentWP
 */

namespace AgentWP\Intent;

use AgentWP\Contracts\ContextBuilderInterface;
use AgentWP\Intent\ContextProviders\ContextProviderInterface;
use AgentWP\Intent\ContextProviders\UserContextProvider;
use AgentWP\Intent\ContextProviders\OrderContextProvider;
use AgentWP\Intent\ContextProviders\StoreContextProvider;

class ContextBuilder implements ContextBuilderInterface {
	/**
	 * @var ContextProviderInterface[]
	 */
	private array $providers = array();

	/**
	 * Create a new ContextBuilder.
	 *
	 * @param ContextProviderInterface[] $providers Optional context providers.
	 */
	public function __construct( array $providers = array() ) {
		if ( empty( $providers ) ) {
			$providers = $this->default_providers();
		}

		$this->providers = $providers;
	}

	/**
	 * Build context with store and user data.
	 *
	 * @param array $context Request context.
	 * @param array $metadata Request metadata.
	 * @return array
	 */
	public function build( array $context = array(), array $metadata = array() ): array {
		$enriched = array(
			'request'  => $context,
			'metadata' => $metadata,
		);

		// Apply all context providers.
		foreach ( $this->providers as $key => $provider ) {
			if ( $provider instanceof ContextProviderInterface ) {
				$provider_context = $provider->provide( $context, $metadata );

				// Use provider's class name as key if not set, or use specified key.
				$context_key = is_string( $key ) ? $key : $this->get_provider_key( $provider );
				$enriched[ $context_key ] = $provider_context;
			}
		}

		return $enriched;
	}

	/**
	 * Add a context provider.
	 *
	 * @param string                    $key Provider key.
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

	/**
	 * Get the default context providers.
	 *
	 * @return ContextProviderInterface[]
	 */
	private function default_providers(): array {
		return array(
			'user'          => new UserContextProvider(),
			'recent_orders' => new OrderContextProvider(),
			'store'         => new StoreContextProvider(),
		);
	}

	/**
	 * Get the context key for a provider based on its class name.
	 *
	 * @param ContextProviderInterface $provider Provider instance.
	 * @return string Context key.
	 */
	private function get_provider_key( ContextProviderInterface $provider ): string {
		$class_name = get_class( $provider );

		// Convert class name to key (e.g., UserContextProvider -> user).
		$short_name = basename( str_replace( '\\', '/', $class_name ) );
		$key        = strtolower( preg_replace( '/([a-z])([A-Z])/', '$1_$2', $short_name ) );
		$key        = str_replace( '_context_provider', '', $key );

		return $key;
	}
}
