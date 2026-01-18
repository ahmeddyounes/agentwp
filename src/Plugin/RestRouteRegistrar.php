<?php
/**
 * REST route registrar.
 *
 * @package AgentWP\Plugin
 */

namespace AgentWP\Plugin;

use AgentWP\Container\ContainerInterface;

/**
 * Registers REST API routes from controller classes.
 */
final class RestRouteRegistrar {

	/**
	 * Controller class names to register.
	 *
	 * @var string[]
	 */
	private array $controllers;

	/**
	 * Pre-resolved controller instances from container tags.
	 *
	 * @var object[]
	 */
	private array $taggedControllers;

	/**
	 * Container for dependency injection.
	 *
	 * @var ContainerInterface|null
	 */
	private ?ContainerInterface $container;

	/**
	 * Create a new RestRouteRegistrar.
	 *
	 * @param string[]                $controllers       Controller class names (fallback).
	 * @param ContainerInterface|null $container         Optional container for DI.
	 * @param object[]                $taggedControllers Pre-resolved controller instances from tags.
	 */
	public function __construct( array $controllers = array(), ?ContainerInterface $container = null, array $taggedControllers = array() ) {
		$this->controllers       = $controllers;
		$this->container         = $container;
		$this->taggedControllers = $taggedControllers;
	}

	/**
	 * Register routes hook.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
	}

	/**
	 * Register all controller routes.
	 *
	 * Prefers tagged controllers (pre-resolved instances) over class names.
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		// Register tagged controllers first (pre-resolved instances).
		foreach ( $this->taggedControllers as $controller ) {
			$this->registerControllerInstance( $controller );
		}

		// Fall back to class-based registration if no tagged controllers.
		if ( empty( $this->taggedControllers ) ) {
			foreach ( $this->controllers as $controllerClass ) {
				$this->registerController( $controllerClass );
			}
		}
	}

	/**
	 * Add a controller class to register (fallback method).
	 *
	 * @param string $controllerClass Controller class name.
	 * @return self
	 */
	public function addController( string $controllerClass ): self {
		$this->controllers[] = $controllerClass;
		return $this;
	}

	/**
	 * Add a pre-resolved controller instance.
	 *
	 * @param object $controller Controller instance.
	 * @return self
	 */
	public function addTaggedController( object $controller ): self {
		$this->taggedControllers[] = $controller;
		return $this;
	}

	/**
	 * Register a single controller's routes by class name.
	 *
	 * @param string $controllerClass Controller class name.
	 * @return void
	 */
	private function registerController( string $controllerClass ): void {
		if ( ! class_exists( $controllerClass ) ) {
			return;
		}

		$controller = $this->resolveController( $controllerClass );

		if ( null === $controller ) {
			return;
		}

		$this->registerControllerInstance( $controller );
	}

	/**
	 * Register a controller instance's routes.
	 *
	 * @param object $controller Controller instance.
	 * @return void
	 */
	private function registerControllerInstance( object $controller ): void {
		if ( method_exists( $controller, 'register_routes' ) ) {
			$controller->register_routes();
		}
	}

	/**
	 * Resolve a controller instance.
	 *
	 * @param string $controllerClass Controller class name.
	 * @return object|null
	 */
	private function resolveController( string $controllerClass ): ?object {
		// Try container first.
		if ( null !== $this->container && $this->container->has( $controllerClass ) ) {
			return $this->container->get( $controllerClass );
		}

		// Fall back to direct instantiation.
		try {
			return new $controllerClass();
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Get the default controller classes (fallback list).
	 *
	 * Used only when no controllers are registered via container tags.
	 *
	 * @return string[]
	 */
	public static function getDefaultControllers(): array {
		return array(
			'AgentWP\\Rest\\SettingsController',
			'AgentWP\\Rest\\IntentController',
			'AgentWP\\Rest\\HealthController',
			'AgentWP\\Rest\\SearchController',
			'AgentWP\\Rest\\AnalyticsController',
			'AgentWP\\Rest\\HistoryController',
			'AgentWP\\API\\ThemeController',
		);
	}

	/**
	 * Create registrar with default controllers (fallback method).
	 *
	 * @param ContainerInterface|null $container Optional container.
	 * @return self
	 */
	public static function withDefaults( ?ContainerInterface $container = null ): self {
		return new self( self::getDefaultControllers(), $container );
	}

	/**
	 * Create registrar from container-tagged controllers.
	 *
	 * Discovers controllers via the 'rest.controller' tag.
	 * Falls back to default controllers if no tagged controllers found.
	 *
	 * @param ContainerInterface $container Container with tagged services.
	 * @return self
	 */
	public static function fromContainer( ContainerInterface $container ): self {
		$taggedControllers = $container->tagged( 'rest.controller' );

		// Use tagged controllers if available, otherwise fall back to defaults.
		if ( ! empty( $taggedControllers ) ) {
			return new self( array(), $container, $taggedControllers );
		}

		return self::withDefaults( $container );
	}
}
