<?php
/**
 * Core plugin bootstrap.
 *
 * @package AgentWP
 */

namespace AgentWP;

use AgentWP\Container\Container;
use AgentWP\Container\ContainerInterface;
use AgentWP\Container\ServiceProvider;
use AgentWP\Plugin\SettingsManager;
use AgentWP\Providers\CoreServiceProvider;
use AgentWP\Providers\InfrastructureServiceProvider;
use AgentWP\Providers\IntentServiceProvider;
use AgentWP\Providers\RestServiceProvider;
use AgentWP\Providers\ServicesServiceProvider;

class Plugin {
	const TRANSIENT_PREFIX = 'agentwp_';

	/**
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * @var ContainerInterface
	 */
	private ContainerInterface $container;

	/**
	 * Registered service providers.
	 *
	 * @var ServiceProvider[]
	 */
	private array $providers = array();

	/**
	 * Initialize plugin hooks.
	 *
	 * @return Plugin
	 */
	public static function init() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Plugin activation handler.
	 *
	 * Creates initial options and database tables for first-time installations.
	 * Schema creation is centralized through SchemaManager to ensure a single
	 * entry point for all database operations.
	 *
	 * @return void
	 */
	public static function activate() {
		$defaults      = SettingsManager::getDefaults();
		$usage_default = SettingsManager::getDefaultUsageStats();

		add_option( SettingsManager::OPTION_SETTINGS, $defaults, '', false );
		add_option( SettingsManager::OPTION_USAGE_STATS, $usage_default, '', false );
		add_option( SettingsManager::OPTION_BUDGET_LIMIT, SettingsManager::DEFAULT_BUDGET_LIMIT, '', false );
		add_option( SettingsManager::OPTION_DRAFT_TTL, SettingsManager::DEFAULT_DRAFT_TTL, '', false );
		add_option( SettingsManager::OPTION_API_KEY, SettingsManager::DEFAULT_API_KEY, '', false );
		add_option( SettingsManager::OPTION_API_KEY_LAST4, SettingsManager::DEFAULT_API_KEY_LAST4, '', false );
		add_option( SettingsManager::OPTION_DEMO_API_KEY, SettingsManager::DEFAULT_DEMO_API_KEY, '', false );
		add_option( SettingsManager::OPTION_DEMO_API_KEY_LAST4, SettingsManager::DEFAULT_DEMO_API_KEY_LAST4, '', false );

		// Set installed version for new installations.
		if ( class_exists( 'AgentWP\\Plugin\\Upgrader' ) ) {
			Plugin\Upgrader::update_installed_version( defined( 'AGENTWP_VERSION' ) ? AGENTWP_VERSION : '0.0.0' );
		}

		// Create database tables via SchemaManager (single entry point for schema).
		if ( class_exists( 'AgentWP\\Plugin\\SchemaManager' ) ) {
			Plugin\SchemaManager::create_tables();
		}
	}

	/**
	 * Plugin deactivation handler.
	 *
	 * @return void
	 */
	public static function deactivate() {
		self::delete_transients();

		if ( class_exists( 'AgentWP\\Demo\\Manager' ) ) {
			Demo\Manager::deactivate();
		}
	}

	/**
	 * Set up hooks.
	 */
	private function __construct() {
		$this->container = new Container();
		$this->registerProviders();
		$this->bootProviders();

		add_action( 'init', array( $this, 'load_textdomain' ) );

		if ( class_exists( 'AgentWP\\Billing\\UsageTracker' ) ) {
			Billing\UsageTracker::init();
		}

		if ( class_exists( 'AgentWP\\Search\\Index' ) ) {
			Search\Index::init();
		}

		if ( class_exists( 'AgentWP\\Demo\\Manager' ) ) {
			Demo\Manager::init();
		}
	}

	/**
	 * Register service providers.
	 *
	 * @return void
	 */
	private function registerProviders(): void {
		$this->providers = array(
			new CoreServiceProvider( $this->container ),
			new InfrastructureServiceProvider( $this->container ),
			new ServicesServiceProvider( $this->container ),
			new RestServiceProvider( $this->container ),
			new IntentServiceProvider( $this->container ),
		);

		foreach ( $this->providers as $provider ) {
			$provider->register();
		}

		/**
		 * Allow extensions to register additional providers.
		 *
		 * @param ContainerInterface $container The DI container.
		 */
		do_action( 'agentwp_register_providers', $this->container );
	}

	/**
	 * Boot service providers.
	 *
	 * @return void
	 */
	private function bootProviders(): void {
		foreach ( $this->providers as $provider ) {
			if ( method_exists( $provider, 'boot' ) ) {
				$provider->boot();
			}
		}

		/**
		 * Fires after all providers have been booted.
		 *
		 * Use this hook to perform post-boot initialization.
		 *
		 * @param ContainerInterface $container The DI container.
		 */
		do_action( 'agentwp_boot_providers', $this->container );
	}

	/**
	 * Get the container instance.
	 *
	 * @return ContainerInterface
	 */
	public function getContainer(): ContainerInterface {
		return $this->container;
	}

	/**
	 * Get the container instance statically.
	 *
	 * @return ContainerInterface|null
	 */
	public static function container(): ?ContainerInterface {
		return self::$instance?->container;
	}

	/**
	 * Load translations.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'agentwp',
			false,
			dirname( plugin_basename( AGENTWP_PLUGIN_FILE ) ) . '/languages'
		);
	}

	/**
	 * Remove plugin transients.
	 *
	 * @return void
	 */
	private static function delete_transients() {
		global $wpdb;

		$transient_like = $wpdb->esc_like( self::TRANSIENT_PREFIX ) . '%';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup on deactivation; caching is not applicable.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				"_transient_{$transient_like}",
				"_transient_timeout_{$transient_like}"
			)
		);

		if ( is_multisite() ) {
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s OR meta_key LIKE %s",
					"_site_transient_{$transient_like}",
					"_site_transient_timeout_{$transient_like}"
				)
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}
}
