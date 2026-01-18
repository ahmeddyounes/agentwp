<?php
/**
 * Infrastructure service provider.
 *
 * @package AgentWP\Providers
 */

namespace AgentWP\Providers;

use AgentWP\AI\AIClientFactory;
use AgentWP\Config\AgentWPConfig;
use AgentWP\Container\ServiceProvider;
use AgentWP\Contracts\AIClientFactoryInterface;
use AgentWP\Demo\DemoCredentials;
use AgentWP\Contracts\CacheInterface;
use AgentWP\Contracts\ClockInterface;
use AgentWP\Contracts\DraftStorageInterface;
use AgentWP\Contracts\HttpClientInterface;
use AgentWP\Contracts\OrderRepositoryInterface;
use AgentWP\Contracts\PolicyInterface;
use AgentWP\Contracts\RetryPolicyInterface;
use AgentWP\Contracts\SessionHandlerInterface;
use AgentWP\Contracts\SleeperInterface;
use AgentWP\Contracts\TransientCacheInterface;
use AgentWP\Contracts\OptionsInterface;
use AgentWP\Contracts\OpenAIKeyValidatorInterface;
use AgentWP\Contracts\UsageTrackerInterface;
use AgentWP\Contracts\WooCommerceConfigGatewayInterface;
use AgentWP\Contracts\WooCommercePriceFormatterInterface;
use AgentWP\Contracts\WooCommerceProductCategoryGatewayInterface;
use AgentWP\Contracts\WooCommerceUserGatewayInterface;
use AgentWP\Plugin\SettingsManager;
use AgentWP\Security\ApiKeyStorage;
use AgentWP\Security\Encryption;
use AgentWP\Security\Policy\WooCommercePolicy;
use AgentWP\Infrastructure\PhpSessionHandler;
use AgentWP\Infrastructure\RealSleeper;
use AgentWP\Infrastructure\SystemClock;
use AgentWP\Infrastructure\TransientDraftStorage;
use AgentWP\Infrastructure\WooCommerceConfigGateway;
use AgentWP\Infrastructure\WooCommerceOrderRepository;
use AgentWP\Infrastructure\WooCommercePriceFormatter;
use AgentWP\Infrastructure\WooCommerceProductCategoryGateway;
use AgentWP\Infrastructure\WooCommerceUserGateway;
use AgentWP\Infrastructure\WordPressHttpClient;
use AgentWP\Infrastructure\WordPressObjectCache;
use AgentWP\Infrastructure\WordPressTransientCache;
use AgentWP\Infrastructure\WPFunctions;
use AgentWP\Infrastructure\OpenAIKeyValidator;
use AgentWP\Infrastructure\UsageTrackerAdapter;
use AgentWP\Demo\DemoAwareKeyValidator;
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
		$this->registerWooCommerceGateways();
		$this->registerDemoCredentials();
		$this->registerAIClientFactory();
		$this->registerDraftStorage();
		$this->registerApiKeyStorage();
		$this->registerOpenAIKeyValidator();
		$this->registerPolicy();
		$this->registerUsageTracker();
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
	 * Register WooCommerce-related gateways for DI.
	 *
	 * These gateways abstract WooCommerce globals so services can remain testable.
	 *
	 * @return void
	 */
	private function registerWooCommerceGateways(): void {
		$this->container->singleton(
			WooCommerceConfigGatewayInterface::class,
			fn() => new WooCommerceConfigGateway()
		);

		$this->container->singleton(
			WooCommerceUserGatewayInterface::class,
			fn() => new WooCommerceUserGateway()
		);

		$this->container->singleton(
			WooCommerceProductCategoryGatewayInterface::class,
			fn( $c ) => new WooCommerceProductCategoryGateway(
				$c->get( CacheInterface::class )
			)
		);

		$this->container->singleton(
			WooCommercePriceFormatterInterface::class,
			fn() => new WooCommercePriceFormatter()
		);
	}

	/**
	 * Register demo credentials.
	 *
	 * @return void
	 */
	private function registerDemoCredentials(): void {
		$this->container->singleton(
			DemoCredentials::class,
			fn( $c ) => new DemoCredentials( $c->get( SettingsManager::class ) )
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
			function ( $c ) {
				$settings = $c->get( SettingsManager::class );
				// Get model from settings, or fall back to centralized config.
				$default_model = $settings->get( 'model' )
					?: AgentWPConfig::get( 'openai.default_model', AgentWPConfig::OPENAI_DEFAULT_MODEL );

				return new AIClientFactory(
					$c->get( HttpClientInterface::class ),
					$settings,
					$default_model,
					$c->get( DemoCredentials::class ),
					$c->get( UsageTrackerInterface::class )
				);
			}
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

	/**
	 * Register API key storage.
	 *
	 * @return void
	 */
	private function registerApiKeyStorage(): void {
		$this->container->singleton(
			Encryption::class,
			fn() => new Encryption()
		);

		$this->container->singleton(
			ApiKeyStorage::class,
			fn( $c ) => new ApiKeyStorage(
				$c->get( Encryption::class ),
				$c->get( OptionsInterface::class )
			)
		);
	}

	/**
	 * Register OpenAI key validator.
	 *
	 * The validator is wrapped with demo-mode awareness so that:
	 * - In demo stubbed mode: Validation always passes (no API calls)
	 * - In demo key mode: Only the demo key is validated
	 * - In normal mode: Standard key validation via OpenAI API
	 *
	 * @return void
	 */
	private function registerOpenAIKeyValidator(): void {
		// Register the underlying real validator.
		$this->container->singleton(
			OpenAIKeyValidator::class,
			fn( $c ) => new OpenAIKeyValidator(
				$c->get( HttpClientInterface::class )
			)
		);

		// Register the demo-aware validator as the interface implementation.
		$this->container->singleton(
			OpenAIKeyValidatorInterface::class,
			fn( $c ) => new DemoAwareKeyValidator(
				$c->get( DemoCredentials::class ),
				$c->get( OpenAIKeyValidator::class )
			)
		);
	}

	/**
	 * Register policy interface.
	 *
	 * The policy layer abstracts capability checks so that domain services
	 * don't need to call current_user_can() directly.
	 *
	 * @return void
	 */
	private function registerPolicy(): void {
		$this->container->singleton(
			PolicyInterface::class,
			fn( $c ) => new WooCommercePolicy(
				$c->get( WPFunctions::class )
			)
		);
	}

	/**
	 * Register usage tracker.
	 *
	 * The usage tracker adapter wraps the static UsageTracker class
	 * so it can be injected and mocked in tests.
	 *
	 * @return void
	 */
	private function registerUsageTracker(): void {
		$this->container->singleton(
			UsageTrackerInterface::class,
			fn() => new UsageTrackerAdapter()
		);
	}
}
