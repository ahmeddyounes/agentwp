<?php
/**
 * Intent service provider.
 *
 * @package AgentWP\Providers
 */

namespace AgentWP\Providers;

use AgentWP\AI\Functions\ConfirmRefund;
use AgentWP\AI\Functions\ConfirmStatusUpdate;
use AgentWP\AI\Functions\ConfirmStockUpdate;
use AgentWP\AI\Functions\DraftEmail;
use AgentWP\AI\Functions\GetCustomerProfile;
use AgentWP\AI\Functions\GetSalesReport;
use AgentWP\AI\Functions\PrepareBulkStatusUpdate;
use AgentWP\AI\Functions\PrepareRefund;
use AgentWP\AI\Functions\PrepareStatusUpdate;
use AgentWP\AI\Functions\PrepareStockUpdate;
use AgentWP\AI\Functions\SearchOrders;
use AgentWP\AI\Functions\SearchProduct;
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
use AgentWP\Contracts\ToolRegistryInterface;
use AgentWP\Plugin\SettingsManager;
use AgentWP\Intent\Handlers\AnalyticsQueryHandler;
use AgentWP\Intent\Handlers\CustomerLookupHandler;
use AgentWP\Intent\Handlers\EmailDraftHandler;
use AgentWP\Intent\Handlers\FallbackHandler;
use AgentWP\Intent\Handlers\OrderRefundHandler;
use AgentWP\Intent\Handlers\OrderSearchHandler;
use AgentWP\Intent\Handlers\OrderStatusHandler;
use AgentWP\Intent\Classifier\ScorerRegistry;
use AgentWP\Intent\Classifier\Scorers;
use AgentWP\Intent\FunctionRegistry;
use AgentWP\Intent\Handler;
use AgentWP\Intent\HandlerRegistry;
use AgentWP\Intent\Handlers\ProductStockHandler;
use AgentWP\Intent\ToolRegistry;
use AgentWP\Infrastructure\WPFunctions;

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
		$this->registerToolRegistry();
		$this->registerHandlerRegistry();
		$this->registerFallbackHandler();
		$this->registerEngine();
		$this->registerHandlerFactory();
	}

	/**
	 * Register memory store.
	 *
	 * Configuration is read from SettingsManager with safe defaults if unavailable.
	 * Values can be customized via WordPress filters:
	 * - 'agentwp_memory_limit' (int): Max memory entries (min 1, default 5)
	 * - 'agentwp_memory_ttl' (int): TTL in seconds (min 60, default 1800)
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
			function () {
				// Get WPFunctions for filter hooks.
				$wp = $this->container->has( WPFunctions::class )
					? $this->container->get( WPFunctions::class )
					: new WPFunctions();

				// Read from settings or use safe defaults.
				$limit = SettingsManager::DEFAULT_MEMORY_LIMIT;
				$ttl   = SettingsManager::DEFAULT_MEMORY_TTL;

				if ( $this->container->has( SettingsManager::class ) ) {
					$settings = $this->container->get( SettingsManager::class );
					$limit    = $settings->getMemoryLimit();
					$ttl      = $settings->getMemoryTtl();
				}

				// Apply filters for customization.
				$limit = (int) $wp->applyFilters( 'agentwp_memory_limit', $limit );
				$ttl   = (int) $wp->applyFilters( 'agentwp_memory_ttl', $ttl );

				return new \AgentWP\Intent\MemoryStore( $limit, $ttl );
			}
		);
	}

	/**
	 * Register context builder.
	 *
	 * Wires the ContextBuilder with context providers registered via the
	 * 'intent.context_provider' tag. Providers are retrieved with their
	 * context keys for deterministic ordering.
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
			function () {
				// Get all context providers tagged with 'intent.context_provider'.
				// Returns associative array keyed by context key (e.g., 'user', 'store').
				$providers = $this->container->taggedWithKeys( 'intent.context_provider' );
				return new \AgentWP\Intent\ContextBuilder( $providers );
			}
		);
	}

	/**
	 * Register intent classifier.
	 *
	 * Uses ScorerRegistry as the canonical implementation per ADR 0003.
	 * Third-party code can register custom scorers via the 'agentwp_intent_scorers' filter.
	 *
	 * @return void
	 */
	private function registerIntentClassifier(): void {
		if ( ! class_exists( ScorerRegistry::class ) ) {
			return;
		}

		$this->container->singleton(
			IntentClassifierInterface::class,
			function () {
				// Get WPFunctions for filter/action hooks.
				$wp = $this->container->has( WPFunctions::class )
					? $this->container->get( WPFunctions::class )
					: new WPFunctions();

				$registry = new ScorerRegistry( $wp );

				// Register default scorers.
				$default_scorers = array(
					new Scorers\RefundScorer(),
					new Scorers\StatusScorer(),
					new Scorers\StockScorer(),
					new Scorers\EmailScorer(),
					new Scorers\AnalyticsScorer(),
					new Scorers\CustomerScorer(),
					new Scorers\SearchScorer(),
				);

				// Apply filter for third-party scorers.
				$scorers = $wp->applyFilters( 'agentwp_intent_scorers', $default_scorers );

				// Validate that filter returned an array.
				if ( ! is_array( $scorers ) ) {
					$scorers = $default_scorers;
				}

				$registry->registerMany( $scorers );

				return $registry;
			}
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
	 * Register tool registry with all function schemas.
	 *
	 * @return void
	 */
	private function registerToolRegistry(): void {
		if ( ! class_exists( ToolRegistry::class ) ) {
			return;
		}

		$this->container->singleton(
			ToolRegistryInterface::class,
			function () {
				$registry = new ToolRegistry();

				// Register all function schemas.
				$registry->register( new SearchOrders() );
				$registry->register( new PrepareRefund() );
				$registry->register( new ConfirmRefund() );
				$registry->register( new PrepareStatusUpdate() );
				$registry->register( new PrepareBulkStatusUpdate() );
				$registry->register( new ConfirmStatusUpdate() );
				$registry->register( new SearchProduct() );
				$registry->register( new PrepareStockUpdate() );
				$registry->register( new ConfirmStockUpdate() );
				$registry->register( new DraftEmail() );
				$registry->register( new GetSalesReport() );
				$registry->register( new GetCustomerProfile() );

				return $registry;
			}
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
	 * Register fallback handler for unknown intents.
	 *
	 * @return void
	 */
	private function registerFallbackHandler(): void {
		if ( ! class_exists( FallbackHandler::class ) ) {
			return;
		}

		$this->container->singleton(
			FallbackHandler::class,
			fn() => new FallbackHandler()
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
					$this->container->get( HandlerRegistry::class ),
					$this->container->get( FallbackHandler::class )
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
				$c->get( AIClientFactoryInterface::class ),
				$c->get( ToolRegistryInterface::class )
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
				$c->get( AIClientFactoryInterface::class ),
				$c->get( ToolRegistryInterface::class )
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
				$c->get( AIClientFactoryInterface::class ),
				$c->get( ToolRegistryInterface::class )
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
				$c->get( AIClientFactoryInterface::class ),
				$c->get( ToolRegistryInterface::class )
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
				$c->get( AIClientFactoryInterface::class ),
				$c->get( ToolRegistryInterface::class )
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
				$c->get( AIClientFactoryInterface::class ),
				$c->get( ToolRegistryInterface::class )
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
				$c->get( AIClientFactoryInterface::class ),
				$c->get( ToolRegistryInterface::class )
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
