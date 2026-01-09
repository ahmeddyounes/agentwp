<?php
/**
 * Handler service provider.
 *
 * @package AgentWP\Providers
 */

namespace AgentWP\Providers;

use AgentWP\Container\ServiceProvider;

/**
 * Registers intent handlers.
 */
final class HandlerServiceProvider extends ServiceProvider {

	/**
	 * Handler class names.
	 *
	 * @var string[]
	 */
	private const HANDLERS = array(
		'AgentWP\\Handlers\\OrderSearchHandler',
		'AgentWP\\Handlers\\RefundHandler',
		'AgentWP\\Handlers\\OrderStatusHandler',
		'AgentWP\\Handlers\\StockHandler',
		'AgentWP\\Handlers\\EmailDraftHandler',
		'AgentWP\\Handlers\\AnalyticsHandler',
		'AgentWP\\Handlers\\CustomerHandler',
		'AgentWP\\Handlers\\BulkHandler',
	);

	/**
	 * Register handlers.
	 *
	 * @return void
	 */
	public function register(): void {
		foreach ( self::HANDLERS as $handlerClass ) {
			$this->registerHandler( $handlerClass );
		}
	}

	/**
	 * Register a single handler.
	 *
	 * @param string $handlerClass Handler class name.
	 * @return void
	 */
	private function registerHandler( string $handlerClass ): void {
		if ( ! class_exists( $handlerClass ) ) {
			return;
		}

		$this->container->singleton(
			$handlerClass,
			fn() => new $handlerClass()
		);

		$this->container->tag( $handlerClass, 'intent.handler' );
	}

	/**
	 * Get all registered handler class names.
	 *
	 * @return string[]
	 */
	public static function getHandlerClasses(): array {
		return self::HANDLERS;
	}
}
