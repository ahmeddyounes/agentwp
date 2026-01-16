<?php
/**
 * Infrastructure service provider.
 *
 * @package AgentWP\Providers
 */

namespace AgentWP\Providers;

use AgentWP\AI\AIClientFactory;
use AgentWP\Container\ServiceProvider;
use AgentWP\Contracts\AIClientFactoryInterface;
use AgentWP\Contracts\CacheInterface;
use AgentWP\Contracts\ClockInterface;
use AgentWP\Contracts\DraftStorageInterface;
use AgentWP\Contracts\HttpClientInterface;
use AgentWP\Contracts\OrderRepositoryInterface;
use AgentWP\Contracts\RetryPolicyInterface;
use AgentWP\Contracts\SessionHandlerInterface;
use AgentWP\Contracts\SleeperInterface;
use AgentWP\Contracts\TransientCacheInterface;
use AgentWP\Plugin\SettingsManager;
use AgentWP\Infrastructure\PhpSessionHandler;
use AgentWP\Infrastructure\RealSleeper;
use AgentWP\Infrastructure\SystemClock;
use AgentWP\Infrastructure\TransientDraftStorage;
use AgentWP\Infrastructure\WooCommerceOrderRepository;
use AgentWP\Infrastructure\WordPressHttpClient;
use AgentWP\Infrastructure\WordPressObjectCache;
use AgentWP\Infrastructure\WordPressTransientCache;
use AgentWP\Retry\ExponentialBackoffPolicy;
use AgentWP\Retry\RetryExecutor;

/**
 * Registers infrastructure services.
 */
final class InfrastructureServiceProvider extends ServiceProvider {

	/**
	 * Register infrastructure services.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->registerCache();
		$this->registerTransientCache();
		$this->registerHttpClient();
		$this->registerClock();
		$this->registerSleeper();
		$this->registerSession();
		$this->registerRetry();
		$this->registerOrderRepository();
		$this->registerAIClientFactory();
		$this->registerDraftStorage();
	}

	/**
	 * Register object cache.
	 *
	 * @return void
	 */
	private function registerCache(): void {
		$this->container->singleton(
			CacheInterface::class,
			fn() => new WordPressObjectCache( 'agentwp' )
		);
	}

	/**
	 * Register transient cache.
	 *
	 * @return void
	 */
	private function registerTransientCache(): void {
		$this->container->singleton(
			TransientCacheInterface::class,
			fn() => new WordPressTransientCache( 'agentwp_' )
		);
	}

	/**
	 * Register HTTP client.
	 *
	 * @return void
	 */
	private function registerHttpClient(): void {
		$this->container->singleton(
			HttpClientInterface::class,
			fn() => new WordPressHttpClient( 30 )
		);
	}

	/**
	 * Register clock.
	 *
	 * @return void
	 */
	private function registerClock(): void {
		$this->container->singleton(
			ClockInterface::class,
			fn() => SystemClock::withWordPressTimezone()
		);
	}

	/**
	 * Register sleeper.
	 *
	 * @return void
	 */
	private function registerSleeper(): void {
		$this->container->singleton(
			SleeperInterface::class,
			fn() => new RealSleeper()
		);
	}

	/**
	 * Register session handler.
	 *
	 * @return void
	 */
	private function registerSession(): void {
		$this->container->singleton(
			SessionHandlerInterface::class,
			fn() => new PhpSessionHandler( 'agentwp_' )
		);
	}

	/**
	 * Register retry infrastructure.
	 *
	 * @return void
	 */
	private function registerRetry(): void {
		$this->container->singleton(
			RetryPolicyInterface::class,
			fn() => ExponentialBackoffPolicy::forOpenAI()
		);

		$this->container->singleton(
			RetryExecutor::class,
			fn() => new RetryExecutor(
				$this->container->get( RetryPolicyInterface::class ),
				$this->container->get( SleeperInterface::class )
			)
		);
	}

	/**
	 * Register order repository.
	 *
	 * @return void
	 */
	private function registerOrderRepository(): void {
		// Only register if WooCommerce is active.
		// Don't register null - let has() return false and get() throw NotFoundException.
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return;
		}

		$this->container->singleton(
			OrderRepositoryInterface::class,
			fn() => new WooCommerceOrderRepository()
		);
	}

	/**
	 * Register AI client factory.
	 *
	 * @return void
	 */
	private function registerAIClientFactory(): void {
		$this->container->singleton(
			AIClientFactory::class,
			fn( $c ) => new AIClientFactory( $c->get( SettingsManager::class ) )
		);
		$this->container->singleton(
			AIClientFactoryInterface::class,
			fn( $c ) => $c->get( AIClientFactory::class )
		);
	}

	/**
	 * Register draft storage.
	 *
	 * @return void
	 */
	private function registerDraftStorage(): void {
		$this->container->singleton(
			DraftStorageInterface::class,
			fn() => new TransientDraftStorage()
		);
	}
}
