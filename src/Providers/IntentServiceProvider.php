<?php
/**
 * Intent service provider.
 *
 * @package AgentWP\Providers
 */

namespace AgentWP\Providers;

use AgentWP\Container\ServiceProvider;
use AgentWP\Contracts\ContextBuilderInterface;
use AgentWP\Contracts\IntentClassifierInterface;
use AgentWP\Contracts\MemoryStoreInterface;

/**
 * Registers intent-related services.
 */
final class IntentServiceProvider extends ServiceProvider {

	/**
	 * Register intent services.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->registerMemoryStore();
		$this->registerContextBuilder();
		$this->registerIntentClassifier();
		$this->registerEngine();
		$this->registerHandlerFactory();
	}

	/**
	 * Register memory store.
	 *
	 * @return void
	 */
	private function registerMemoryStore(): void {
		// Only register if class exists.
		// Don't register null - let has() return false and get() throw NotFoundException.
		if ( ! class_exists( 'AgentWP\\Context\\MemoryStore' ) ) {
			return;
		}

		$this->container->singleton(
			MemoryStoreInterface::class,
			fn() => new \AgentWP\Context\MemoryStore()
		);
	}

	/**
	 * Register context builder.
	 *
	 * @return void
	 */
	private function registerContextBuilder(): void {
		// Only register if class exists.
		// Don't register null - let has() return false and get() throw NotFoundException.
		if ( ! class_exists( 'AgentWP\\Context\\ContextBuilder' ) ) {
			return;
		}

		$this->container->singleton(
			ContextBuilderInterface::class,
			fn() => new \AgentWP\Context\ContextBuilder()
		);
	}

	/**
	 * Register intent classifier.
	 *
	 * @return void
	 */
	private function registerIntentClassifier(): void {
		// Only register if class exists.
		// Don't register null - let has() return false and get() throw NotFoundException.
		if ( ! class_exists( 'AgentWP\\Intent\\IntentClassifier' ) ) {
			return;
		}

		$this->container->singleton(
			IntentClassifierInterface::class,
			fn() => new \AgentWP\Intent\IntentClassifier()
		);
	}

	/**
	 * Register intent engine.
	 *
	 * @return void
	 */
	private function registerEngine(): void {
		if ( ! class_exists( 'AgentWP\\Intent\\Engine' ) ) {
			return;
		}

		$this->container->singleton(
			'AgentWP\\Intent\\Engine',
			function () {
				return new \AgentWP\Intent\Engine();
			}
		);
	}

	/**
	 * Register handler factory.
	 *
	 * @return void
	 */
	private function registerHandlerFactory(): void {
		$this->container->singleton(
			'intent.handler_factory',
			function () {
				return function ( string $intent ) {
					$handlers = $this->container->tagged( 'intent.handler' );

					foreach ( $handlers as $handler ) {
						if ( method_exists( $handler, 'supports' ) && $handler->supports( $intent ) ) {
							return $handler;
						}
					}

					return null;
				};
			}
		);
	}
}
