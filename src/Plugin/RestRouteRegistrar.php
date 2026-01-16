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
	 * Container for dependency injection.
	 *
	 * @var ContainerInterface|null
	 */
	private ?ContainerInterface $container;

	/**
	 * Create a new RestRouteRegistrar.
	 *
	 * @param string[]                $controllers Controller class names.
	 * @param ContainerInterface|null $container   Optional container for DI.
	 */
	public function __construct( array $controllers = array(), ?ContainerInterface $container = null ) {
		$this->controllers = $controllers;
		$this->container   = $container;
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
	 * @return void
	 */
	public function registerRoutes(): void {
		foreach ( $this->controllers as $controllerClass ) {
			$this->registerController( $controllerClass );
		}
	}

	/**
	 * Add a controller class to register.
	 *
	 * @param string $controllerClass Controller class name.
	 * @return self
	 */
	public function addController( string $controllerClass ): self {
		$this->controllers[] = $controllerClass;
		return $this;
	}

	/**
	 * Register a single controller's routes.
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
	 * Get the default controller classes.
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
			'AgentWP\\API\\HistoryController',
			'AgentWP\\API\\ThemeController',
		);
	}

	/**
	 * Create registrar with default controllers.
	 *
	 * @param ContainerInterface|null $container Optional container.
	 * @return self
	 */
	public static function withDefaults( ?ContainerInterface $container = null ): self {
		return new self( self::getDefaultControllers(), $container );
	}
}
