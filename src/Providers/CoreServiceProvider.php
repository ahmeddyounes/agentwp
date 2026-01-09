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
}
