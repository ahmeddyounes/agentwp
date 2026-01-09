<?php
/**
 * Service provider base class.
 *
 * @package AgentWP\Container
 */

namespace AgentWP\Container;

/**
 * Abstract base class for service providers.
 *
 * Service providers are responsible for registering bindings
 * in the container and optionally performing boot actions
 * after all providers have been registered.
 */
abstract class ServiceProvider {

	/**
	 * The container instance.
	 *
	 * @var ContainerInterface
	 */
	protected ContainerInterface $container;

	/**
	 * Create a new service provider.
	 *
	 * @param ContainerInterface $container The container instance.
	 */
	public function __construct( ContainerInterface $container ) {
		$this->container = $container;
	}

	/**
	 * Register bindings in the container.
	 *
	 * This method is called before any other provider's boot method.
	 * Use this to register all bindings, singletons, and instances.
	 *
	 * @return void
	 */
	abstract public function register(): void;

	/**
	 * Bootstrap any application services.
	 *
	 * This method is called after all providers have been registered.
	 * Use this to register WordPress hooks, perform initialization,
	 * or interact with other registered services.
	 *
	 * @return void
	 */
	public function boot(): void {
		// Optional: Override in subclass.
	}

	/**
	 * Helper to bind a service.
	 *
	 * @param string          $id       Service identifier.
	 * @param callable|string $resolver Resolver.
	 * @return void
	 */
	protected function bind( string $id, callable|string $resolver ): void {
		$this->container->bind( $id, $resolver );
	}

	/**
	 * Helper to bind a singleton.
	 *
	 * @param string          $id       Service identifier.
	 * @param callable|string $resolver Resolver.
	 * @return void
	 */
	protected function singleton( string $id, callable|string $resolver ): void {
		$this->container->singleton( $id, $resolver );
	}

	/**
	 * Helper to register an instance.
	 *
	 * @param string $id       Service identifier.
	 * @param object $instance The instance.
	 * @return void
	 */
	protected function instance( string $id, object $instance ): void {
		$this->container->instance( $id, $instance );
	}

	/**
	 * Helper to tag a service.
	 *
	 * @param string $id  Service identifier.
	 * @param string $tag Tag name.
	 * @return void
	 */
	protected function tag( string $id, string $tag ): void {
		$this->container->tag( $id, $tag );
	}
}
