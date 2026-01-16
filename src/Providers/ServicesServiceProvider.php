<?php
/**
 * Services service provider.
 *
 * @package AgentWP\Providers
 */

namespace AgentWP\Providers;

use AgentWP\Container\ServiceProvider;
use AgentWP\Contracts\AnalyticsServiceInterface;
use AgentWP\Contracts\CustomerServiceInterface;
use AgentWP\Contracts\DraftStorageInterface;
use AgentWP\Contracts\EmailDraftServiceInterface;
use AgentWP\Contracts\OrderRefundServiceInterface;
use AgentWP\Contracts\OrderRepositoryInterface;
use AgentWP\Contracts\OrderSearchServiceInterface;
use AgentWP\Contracts\OrderStatusServiceInterface;
use AgentWP\Contracts\ProductStockServiceInterface;
use AgentWP\Services\AnalyticsService;
use AgentWP\Services\EmailDraftService;
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
		$this->registerEmailDraftService();
	}

	/**
	 * Register order search service.
	 *
	 * @return void
	 */
	private function registerOrderSearchService(): void {
		$this->container->singleton(
			OrderSearchServiceInterface::class,
			fn() => new OrderSearchService()
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
				$c->has( DraftStorageInterface::class ) ? $c->get( DraftStorageInterface::class ) : null
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
				$c->has( DraftStorageInterface::class ) ? $c->get( DraftStorageInterface::class ) : null
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
				$c->has( DraftStorageInterface::class ) ? $c->get( DraftStorageInterface::class ) : null
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
