<?php
/**
 * Services service provider.
 *
 * @package AgentWP\Providers
 */

namespace AgentWP\Providers;

use AgentWP\Container\ServiceProvider;
use AgentWP\Infrastructure\WooCommerceOrderRepository;
use AgentWP\Services\AnalyticsService;
use AgentWP\Services\OrderRefundService;
use AgentWP\Services\OrderStatusService;
use AgentWP\Services\ProductStockService;
use AgentWP\Services\CustomerService;
use AgentWP\Services\OrderSearchService;

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
	}

	/**
	 * Register order search service.
	 *
	 * @return void
	 */
	private function registerOrderSearchService(): void {
		$this->container->singleton( OrderSearchService::class, fn() => new OrderSearchService() );
	}

	/**
	 * Register customer service.
	 *
	 * @return void
	 */
	private function registerCustomerService(): void {
		$this->container->singleton( CustomerService::class, fn() => new CustomerService() );
	}

	/**
	 * Register product stock service.
	 *
	 * @return void
	 */
	private function registerProductStockService(): void {
		$this->container->singleton( ProductStockService::class, fn() => new ProductStockService() );
	}

	/**
	 * Register order status service.
	 *
	 * @return void
	 */
	private function registerOrderStatusService(): void {
		$this->container->singleton( OrderStatusService::class, fn() => new OrderStatusService() );
	}

	/**
	 * Register analytics service.
	 *
	 * @return void
	 */
	private function registerAnalyticsService(): void {
		$this->container->singleton( AnalyticsService::class, fn() => new AnalyticsService() );
	}

	/**
	 * Register order refund service.
	 *
	 * @return void
	 */
	private function registerOrderRefundService(): void {
		$this->container->singleton(
			OrderRefundService::class,
			function () {
				$repo = null;
				// If Repo is registered, use it, otherwise new one (which falls back to global WC functions)
				if ( $this->container->has( WooCommerceOrderRepository::class ) ) {
					$repo = $this->container->get( WooCommerceOrderRepository::class );
				}
				return new OrderRefundService( $repo );
			}
		);
	}
}
