<?php
/**
 * REST service provider.
 *
 * @package AgentWP\Providers
 */

namespace AgentWP\Providers;

use AgentWP\API\RateLimiter;
use AgentWP\Rest\RestController;
use AgentWP\Config\AgentWPConfig;
use AgentWP\Container\ServiceProvider;
use AgentWP\Error\Handler as ErrorHandler;
use AgentWP\Contracts\ClockInterface;
use AgentWP\Contracts\RateLimiterInterface;
use AgentWP\Contracts\TransientCacheInterface;
use AgentWP\Plugin\ResponseFormatter;
use AgentWP\Plugin\RestRouteRegistrar;

/**
 * Registers REST API services.
 */
final class RestServiceProvider extends ServiceProvider {

	/**
	 * Register REST services.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->registerRateLimiter();
		$this->registerResponseFormatter();
		$this->registerRouteRegistrar();
		$this->registerControllers();
	}

	/**
	 * Boot REST services.
	 *
	 * @return void
	 */
	public function boot(): void {
		// Register REST routes.
		$registrar = $this->container->get( RestRouteRegistrar::class );
		$registrar->register();

		// Register response formatter.
		$formatter = $this->container->get( ResponseFormatter::class );
		$formatter->register();
	}

	/**
	 * Register rate limiter.
	 *
	 * Configuration is read from AgentWPConfig with filter support.
	 * Operators can tune via filters:
	 * - 'agentwp_config_rate_limit_requests' (int): Max requests per window (default 30)
	 * - 'agentwp_config_rate_limit_window' (int): Window duration in seconds (default 60)
	 *
	 * @return void
	 */
	private function registerRateLimiter(): void {
		$this->container->singleton(
			RateLimiterInterface::class,
			fn() => new RateLimiter(
				$this->container->get( TransientCacheInterface::class ),
				$this->container->get( ClockInterface::class ),
				(int) AgentWPConfig::get( 'rate_limit.requests', AgentWPConfig::RATE_LIMIT_REQUESTS ),
				(int) AgentWPConfig::get( 'rate_limit.window', AgentWPConfig::RATE_LIMIT_WINDOW )
			)
		);

		// Also register concrete class.
		$this->container->singleton(
			RateLimiter::class,
			fn() => $this->container->get( RateLimiterInterface::class )
		);
	}

	/**
	 * Register response formatter.
	 *
	 * @return void
	 */
	private function registerResponseFormatter(): void {
		$this->container->singleton(
			ResponseFormatter::class,
			function () {
				$formatter = new ResponseFormatter();

				// Set error categorizer if handler exists.
				if ( class_exists( ErrorHandler::class ) ) {
					$formatter->setErrorCategorizer(
						fn( $code, $status, $message, $data ) => ErrorHandler::categorize( $code, $status, $message, $data )
					);
				}

				// Set request logger if RestController exists.
				if ( class_exists( RestController::class ) ) {
					$formatter->setRequestLogger(
						fn( $request, $status, $errorCode ) => RestController::log_request( $request, $status, $errorCode )
					);
				}

				return $formatter;
			}
		);
	}

	/**
	 * Register route registrar.
	 *
	 * Uses tag-based discovery via 'rest.controller' tag.
	 * Falls back to default controllers if no tagged controllers found.
	 *
	 * @return void
	 */
	private function registerRouteRegistrar(): void {
		$this->container->singleton(
			RestRouteRegistrar::class,
			fn() => RestRouteRegistrar::fromContainer( $this->container )
		);
	}

	/**
	 * Register default REST controllers.
	 *
	 * Tags each controller with 'rest.controller' for discovery.
	 * Additional controllers can be registered by other providers
	 * by binding and tagging them with 'rest.controller'.
	 *
	 * @return void
	 */
	private function registerControllers(): void {
		$controllers = RestRouteRegistrar::getDefaultControllers();

		foreach ( $controllers as $controllerClass ) {
			if ( class_exists( $controllerClass ) ) {
				$this->container->bind(
					$controllerClass,
					$controllerClass
				);

				$this->container->tag( $controllerClass, 'rest.controller' );
			}
		}
	}
}
