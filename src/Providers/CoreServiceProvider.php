<?php
/**
 * Core service provider.
 *
 * @package AgentWP\Providers
 */

namespace AgentWP\Providers;

use AgentWP\Container\ServiceProvider;
use AgentWP\Contracts\OptionsInterface;
use AgentWP\Infrastructure\WordPressOptions;
use AgentWP\Infrastructure\WPFunctions;
use AgentWP\Intent\HandlerRegistry;
use AgentWP\Intent\ContextProviders\UserContextProvider;
use AgentWP\Intent\ContextProviders\OrderContextProvider;
use AgentWP\Intent\ContextProviders\StoreContextProvider;
use AgentWP\Plugin\AdminMenuManager;
use AgentWP\Plugin\AssetManager;
use AgentWP\Plugin\SettingsManager;
use AgentWP\Plugin\ThemeManager;

/**
 * Registers core plugin services.
 */
final class CoreServiceProvider extends ServiceProvider {

	/**
	 * Register core services.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->registerOptions();
		$this->registerSettings();
		$this->registerTheme();
		$this->registerMenu();
		$this->registerAssets();
		$this->registerInfrastructure();
		$this->registerContextProviders();
	}

	/**
	 * Boot core services.
	 *
	 * @return void
	 */
	public function boot(): void {
		// Register admin menu hook.
		$menu = $this->container->get( AdminMenuManager::class );
		$menu->register();

		// Register asset hooks.
		$assets = $this->container->get( AssetManager::class );
		$assets->register();
	}

	/**
	 * Register options interface.
	 *
	 * @return void
	 */
	private function registerOptions(): void {
		$this->container->singleton(
			OptionsInterface::class,
			fn() => new WordPressOptions( '' ) // No prefix - use raw option names.
		);
	}

	/**
	 * Register settings manager.
	 *
	 * @return void
	 */
	private function registerSettings(): void {
		$this->container->singleton(
			SettingsManager::class,
			fn() => new SettingsManager(
				$this->container->get( OptionsInterface::class )
			)
		);
	}

	/**
	 * Register theme manager.
	 *
	 * @return void
	 */
	private function registerTheme(): void {
		$this->container->singleton( ThemeManager::class, fn() => new ThemeManager() );
	}

	/**
	 * Register admin menu manager.
	 *
	 * @return void
	 */
	private function registerMenu(): void {
		$this->container->singleton( AdminMenuManager::class, fn() => new AdminMenuManager() );
	}

	/**
	 * Register asset manager.
	 *
	 * @return void
	 */
	private function registerAssets(): void {
		$this->container->singleton(
			AssetManager::class,
			function () {
				return new AssetManager(
					$this->container->get( SettingsManager::class ),
					$this->container->get( ThemeManager::class ),
					$this->container->get( AdminMenuManager::class ),
					defined( 'AGENTWP_PLUGIN_DIR' ) ? AGENTWP_PLUGIN_DIR : '',
					defined( 'AGENTWP_PLUGIN_URL' ) ? AGENTWP_PLUGIN_URL : '',
					defined( 'AGENTWP_VERSION' ) ? AGENTWP_VERSION : ''
				);
			}
		);
	}

	/**
	 * Register infrastructure services.
	 *
	 * @return void
	 */
	private function registerInfrastructure(): void {
		// WordPress functions wrapper for testability.
		$this->container->singleton( WPFunctions::class, fn() => new WPFunctions() );

		// Handler registry for O(1) intent resolution.
		$this->container->singleton( HandlerRegistry::class, fn() => new HandlerRegistry() );
	}

	/**
	 * Register context providers.
	 *
	 * @return void
	 */
	private function registerContextProviders(): void {
		$this->container->singleton( UserContextProvider::class, fn() => new UserContextProvider() );
		$this->container->singleton( OrderContextProvider::class, fn() => new OrderContextProvider() );
		$this->container->singleton( StoreContextProvider::class, fn() => new StoreContextProvider() );
	}
}
