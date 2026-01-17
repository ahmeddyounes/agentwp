<?php
/**
 * Services service provider.
 *
 * @package AgentWP\Providers
 */

namespace AgentWP\Providers;

use AgentWP\Container\ServiceProvider;
use AgentWP\Contracts\AnalyticsServiceInterface;
use AgentWP\Contracts\CacheInterface;
use AgentWP\Contracts\ClockInterface;
use AgentWP\Contracts\CustomerServiceInterface;
use AgentWP\Contracts\DraftStorageInterface;
use AgentWP\Contracts\EmailDraftServiceInterface;
use AgentWP\Contracts\OrderRefundServiceInterface;
use AgentWP\Contracts\OrderRepositoryInterface;
use AgentWP\Contracts\OrderSearchServiceInterface;
use AgentWP\Contracts\OrderStatusServiceInterface;
use AgentWP\Contracts\ProductStockServiceInterface;
use AgentWP\Contracts\TransientCacheInterface;
use AgentWP\Services\AnalyticsService;
use AgentWP\Services\EmailDraftService;
use AgentWP\Services\OrderRefundService;
use AgentWP\Services\OrderSearch\ArgumentNormalizer;
use AgentWP\Services\OrderSearch\DateRangeParser;
use AgentWP\Services\OrderSearch\OrderFormatter;
use AgentWP\Services\OrderSearch\OrderQueryService;
use AgentWP\Services\OrderSearch\OrderSearchParser;
use AgentWP\Services\OrderSearch\PipelineOrderSearchService;
use AgentWP\Services\OrderStatusService;
use AgentWP\Services\ProductStockService;
use AgentWP\Services\CustomerService;

/**
 * Registers domain services.
 */
final class ServicesServiceProvider extends ServiceProvider {

	/**
	 * Register services.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->registerOrderRefundService();
		$this->registerAnalyticsService();
		$this->registerOrderStatusService();
		$this->registerProductStockService();
		$this->registerCustomerService();
		$this->registerOrderSearchService();
		$this->registerEmailDraftService();
	}

	/**
	 * Register order search service using the pipeline architecture.
	 *
	 * @return void
	 */
	private function registerOrderSearchService(): void {
		// Register DateRangeParser.
		$this->container->singleton(
			DateRangeParser::class,
			fn( $c ) => DateRangeParser::withWordPressTimezone(
				$c->get( ClockInterface::class )
			)
		);

		// Register OrderSearchParser.
		$this->container->singleton(
			OrderSearchParser::class,
			fn( $c ) => new OrderSearchParser(
				$c->get( DateRangeParser::class )
			)
		);

		// Register ArgumentNormalizer.
		$this->container->singleton(
			ArgumentNormalizer::class,
			fn( $c ) => new ArgumentNormalizer(
				$c->get( OrderSearchParser::class ),
				$c->get( DateRangeParser::class )
			)
		);

		// Register OrderFormatter.
		$this->container->singleton(
			OrderFormatter::class,
			fn() => new OrderFormatter()
		);

		// Register OrderQueryService (only if WooCommerce is available).
		$this->container->singleton(
			OrderQueryService::class,
			function ( $c ) {
				if ( ! $c->has( OrderRepositoryInterface::class ) ) {
					return null;
				}
				return new OrderQueryService(
					$c->get( OrderRepositoryInterface::class ),
					$c->get( TransientCacheInterface::class ),
					$c->get( CacheInterface::class ),
					$c->get( OrderFormatter::class )
				);
			}
		);

		// Register the interface binding using the pipeline adapter.
		$this->container->singleton(
			OrderSearchServiceInterface::class,
			function ( $c ) {
				$queryService = $c->get( OrderQueryService::class );
				if ( null === $queryService ) {
					// Return a stub that returns error when WooCommerce is not available.
					return new class implements OrderSearchServiceInterface {
						public function handle( array $args ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Interface requirement
							return array(
								'error' => 'WooCommerce is required.',
								'code'  => 400,
							);
						}
					};
				}
				return new PipelineOrderSearchService(
					$c->get( ArgumentNormalizer::class ),
					$queryService
				);
			}
		);
	}

	/**
	 * Register customer service.
	 *
	 * @return void
	 */
	private function registerCustomerService(): void {
		$this->container->singleton(
			CustomerServiceInterface::class,
			fn() => new CustomerService()
		);
	}

	/**
	 * Register product stock service.
	 *
	 * @return void
	 */
	private function registerProductStockService(): void {
		$this->container->singleton(
			ProductStockServiceInterface::class,
			fn( $c ) => new ProductStockService(
				$c->get( DraftStorageInterface::class )
			)
		);
	}

	/**
	 * Register order status service.
	 *
	 * @return void
	 */
	private function registerOrderStatusService(): void {
		$this->container->singleton(
			OrderStatusServiceInterface::class,
			fn( $c ) => new OrderStatusService(
				$c->get( DraftStorageInterface::class )
			)
		);
	}

	/**
	 * Register analytics service.
	 *
	 * @return void
	 */
	private function registerAnalyticsService(): void {
		$this->container->singleton(
			AnalyticsServiceInterface::class,
			fn() => new AnalyticsService()
		);
	}

	/**
	 * Register order refund service.
	 *
	 * @return void
	 */
	private function registerOrderRefundService(): void {
		$this->container->singleton(
			OrderRefundServiceInterface::class,
			fn( $c ) => new OrderRefundService(
				$c->get( DraftStorageInterface::class )
			)
		);
	}

	/**
	 * Register email draft service.
	 *
	 * @return void
	 */
	private function registerEmailDraftService(): void {
		$this->container->singleton(
			EmailDraftServiceInterface::class,
			fn( $c ) => new EmailDraftService(
				$c->has( OrderRepositoryInterface::class ) ? $c->get( OrderRepositoryInterface::class ) : null
			)
		);
	}
}
