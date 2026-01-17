<?php
/**
 * Intent service provider.
 *
 * @package AgentWP\Providers
 */

namespace AgentWP\Providers;

use AgentWP\Container\ServiceProvider;
use AgentWP\Contracts\AIClientFactoryInterface;
use AgentWP\Contracts\AnalyticsServiceInterface;
use AgentWP\Contracts\ContextBuilderInterface;
use AgentWP\Contracts\CustomerServiceInterface;
use AgentWP\Contracts\EmailDraftServiceInterface;
use AgentWP\Contracts\IntentClassifierInterface;
use AgentWP\Contracts\MemoryStoreInterface;
use AgentWP\Contracts\OrderRefundServiceInterface;
use AgentWP\Contracts\OrderSearchServiceInterface;
use AgentWP\Contracts\OrderStatusServiceInterface;
use AgentWP\Contracts\ProductStockServiceInterface;
use AgentWP\Intent\Handlers\AnalyticsQueryHandler;
use AgentWP\Intent\Handlers\CustomerLookupHandler;
use AgentWP\Intent\Handlers\EmailDraftHandler;
use AgentWP\Intent\Handlers\OrderRefundHandler;
use AgentWP\Intent\Handlers\OrderSearchHandler;
use AgentWP\Intent\Handlers\OrderStatusHandler;
use AgentWP\Intent\FunctionRegistry;
use AgentWP\Intent\HandlerRegistry;
use AgentWP\Intent\Handlers\ProductStockHandler;

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
		$this->registerFunctionRegistry();
		$this->registerHandlerRegistry();
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
		if ( ! class_exists( 'AgentWP\\Intent\\MemoryStore' ) ) {
			return;
		}

		$this->container->singleton(
			MemoryStoreInterface::class,
			fn() => new \AgentWP\Intent\MemoryStore()
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
		if ( ! class_exists( 'AgentWP\\Intent\\ContextBuilder' ) ) {
			return;
		}

		$this->container->singleton(
			ContextBuilderInterface::class,
			fn() => new \AgentWP\Intent\ContextBuilder()
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
	 * Register function registry.
	 *
	 * @return void
	 */
	private function registerFunctionRegistry(): void {
		if ( ! class_exists( FunctionRegistry::class ) ) {
			return;
		}

		$this->container->singleton(
			FunctionRegistry::class,
			fn() => new FunctionRegistry()
		);
	}

	/**
	 * Register handler registry.
	 *
	 * @return void
	 */
	private function registerHandlerRegistry(): void {
		if ( ! class_exists( HandlerRegistry::class ) ) {
			return;
		}

		$this->container->singleton(
			HandlerRegistry::class,
			fn() => new HandlerRegistry()
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

		$this->registerHandlers();

		$this->container->singleton(
			'AgentWP\\Intent\\Engine',
			function () {
				$handlers = $this->container->tagged( 'intent.handler' );
				return new \AgentWP\Intent\Engine(
					$handlers,
					$this->container->get( FunctionRegistry::class ),
					$this->container->get( ContextBuilderInterface::class ),
					$this->container->get( IntentClassifierInterface::class ),
					$this->container->get( MemoryStoreInterface::class ),
					$this->container->get( HandlerRegistry::class )
				);
			}
		);
	}

	/**
	 * Register intent handlers.
	 *
	 * @return void
	 */
	private function registerHandlers(): void {
		$this->registerOrderSearchHandler();
		$this->registerOrderRefundHandler();
		$this->registerOrderStatusHandler();
		$this->registerProductStockHandler();
		$this->registerEmailDraftHandler();
		$this->registerAnalyticsQueryHandler();
		$this->registerCustomerLookupHandler();
	}

	/**
	 * Register order search handler.
	 *
	 * @return void
	 */
	private function registerOrderSearchHandler(): void {
		if ( ! class_exists( OrderSearchHandler::class ) ) {
			return;
		}

		$this->container->singleton(
			OrderSearchHandler::class,
			fn( $c ) => new OrderSearchHandler(
				$c->get( OrderSearchServiceInterface::class ),
				$c->get( AIClientFactoryInterface::class )
			)
		);
		$this->container->tag( OrderSearchHandler::class, 'intent.handler' );
	}

	/**
	 * Register order refund handler.
	 *
	 * @return void
	 */
	private function registerOrderRefundHandler(): void {
		if ( ! class_exists( OrderRefundHandler::class ) ) {
			return;
		}

		$this->container->singleton(
			OrderRefundHandler::class,
			fn( $c ) => new OrderRefundHandler(
				$c->get( OrderRefundServiceInterface::class ),
				$c->get( AIClientFactoryInterface::class )
			)
		);
		$this->container->tag( OrderRefundHandler::class, 'intent.handler' );
	}

	/**
	 * Register order status handler.
	 *
	 * @return void
	 */
	private function registerOrderStatusHandler(): void {
		if ( ! class_exists( OrderStatusHandler::class ) ) {
			return;
		}

		$this->container->singleton(
			OrderStatusHandler::class,
			fn( $c ) => new OrderStatusHandler(
				$c->get( OrderStatusServiceInterface::class ),
				$c->get( AIClientFactoryInterface::class )
			)
		);
		$this->container->tag( OrderStatusHandler::class, 'intent.handler' );
	}

	/**
	 * Register product stock handler.
	 *
	 * @return void
	 */
	private function registerProductStockHandler(): void {
		if ( ! class_exists( ProductStockHandler::class ) ) {
			return;
		}

		$this->container->singleton(
			ProductStockHandler::class,
			fn( $c ) => new ProductStockHandler(
				$c->get( ProductStockServiceInterface::class ),
				$c->get( AIClientFactoryInterface::class )
			)
		);
		$this->container->tag( ProductStockHandler::class, 'intent.handler' );
	}

	/**
	 * Register email draft handler.
	 *
	 * @return void
	 */
	private function registerEmailDraftHandler(): void {
		if ( ! class_exists( EmailDraftHandler::class ) ) {
			return;
		}

		$this->container->singleton(
			EmailDraftHandler::class,
			fn( $c ) => new EmailDraftHandler(
				$c->get( EmailDraftServiceInterface::class ),
				$c->get( AIClientFactoryInterface::class )
			)
		);
		$this->container->tag( EmailDraftHandler::class, 'intent.handler' );
	}

	/**
	 * Register analytics query handler.
	 *
	 * @return void
	 */
	private function registerAnalyticsQueryHandler(): void {
		if ( ! class_exists( AnalyticsQueryHandler::class ) ) {
			return;
		}

		$this->container->singleton(
			AnalyticsQueryHandler::class,
			fn( $c ) => new AnalyticsQueryHandler(
				$c->get( AnalyticsServiceInterface::class ),
				$c->get( AIClientFactoryInterface::class )
			)
		);
		$this->container->tag( AnalyticsQueryHandler::class, 'intent.handler' );
	}

	/**
	 * Register customer lookup handler.
	 *
	 * @return void
	 */
	private function registerCustomerLookupHandler(): void {
		if ( ! class_exists( CustomerLookupHandler::class ) ) {
			return;
		}

		$this->container->singleton(
			CustomerLookupHandler::class,
			fn( $c ) => new CustomerLookupHandler(
				$c->get( CustomerServiceInterface::class ),
				$c->get( AIClientFactoryInterface::class )
			)
		);
		$this->container->tag( CustomerLookupHandler::class, 'intent.handler' );
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
						if ( is_object( $handler ) && method_exists( $handler, 'canHandle' ) && $handler->canHandle( $intent ) ) {
							return $handler;
						}
					}

					return null;
				};
			}
		);
	}
}
