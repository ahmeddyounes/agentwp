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
use AgentWP\Plugin\AdminMenuManager;
use AgentWP\Plugin\AssetManager;
use AgentWP\Plugin\ResponseFormatter;
use AgentWP\Plugin\RestRouteRegistrar;
use AgentWP\Plugin\SettingsManager;
use AgentWP\Plugin\ThemeManager;
use AgentWP\Providers\CoreServiceProvider;
use AgentWP\Providers\HandlerServiceProvider;
use AgentWP\Providers\InfrastructureServiceProvider;
use AgentWP\Providers\IntentServiceProvider;
use AgentWP\Providers\RestServiceProvider;
use AgentWP\Providers\ServicesServiceProvider;

class Plugin {
	const OPTION_SETTINGS     = 'agentwp_settings';
	const OPTION_API_KEY      = 'agentwp_api_key';
	const OPTION_API_KEY_LAST4 = 'agentwp_api_key_last4';
	const OPTION_DEMO_API_KEY = 'agentwp_demo_api_key';
	const OPTION_DEMO_API_KEY_LAST4 = 'agentwp_demo_api_key_last4';
	const OPTION_BUDGET_LIMIT = 'agentwp_budget_limit';
	const OPTION_DRAFT_TTL    = 'agentwp_draft_ttl_minutes';
	const OPTION_USAGE_STATS  = 'agentwp_usage_stats';
	const TRANSIENT_PREFIX    = 'agentwp_';

	/**
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * @var ContainerInterface
	 */
	private ContainerInterface $container;

	/**
	 * @var string|false
	 */
	private string|false $menu_hook = false;

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
	 * @return void
	 */
	public static function activate() {
		$defaults      = self::get_default_settings();
		$usage_default = self::get_default_usage_stats();

		add_option( self::OPTION_SETTINGS, $defaults, '', false );
		add_option( self::OPTION_USAGE_STATS, $usage_default, '', false );
		add_option( self::OPTION_BUDGET_LIMIT, 0, '', false );
		add_option( self::OPTION_DRAFT_TTL, 10, '', false );
		add_option( self::OPTION_API_KEY, '', '', false );
		add_option( self::OPTION_API_KEY_LAST4, '', '', false );
		add_option( self::OPTION_DEMO_API_KEY, '', '', false );
		add_option( self::OPTION_DEMO_API_KEY_LAST4, '', '', false );

		if ( class_exists( 'AgentWP\\Billing\\UsageTracker' ) ) {
			Billing\UsageTracker::activate();
		}

		if ( class_exists( 'AgentWP\\Search\\Index' ) ) {
			Search\Index::activate();
		}
	}

	/**
	 * Plugin deactivation handler.
	 *
	 * @return void
	 */
	public static function deactivate() {
		self::delete_transients();
		self::unschedule_action_scheduler_jobs();
		self::cleanup_export_files();

		if ( class_exists( 'AgentWP\\Demo\\Manager' ) ) {
			Demo\Manager::deactivate();
		}
	}

	/**
	 * Unschedule all pending Action Scheduler jobs for the plugin.
	 *
	 * @return void
	 */
	private static function unschedule_action_scheduler_jobs() {
		// Clean up BulkHandler async jobs.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( Handlers\BulkHandler::ACTION_HOOK );
		}
	}

	/**
	 * Clean up export data created by BulkHandler.
	 *
	 * @return void
	 */
	private static function cleanup_export_files() {
		// Bulk exports are returned inline and not written to disk.
		return;
	}

	/**
	 * Set up hooks.
	 */
	private function __construct() {
		$this->container = new Container();
		$this->registerProviders();
		$this->bootProviders();

		add_action( 'init', array( $this, 'load_textdomain' ) );
		// Note: admin_menu, admin_enqueue_scripts, admin_head hooks are now
		// registered by CoreServiceProvider via AdminMenuManager and AssetManager.
		// Note: rest_api_init and rest_post_dispatch hooks are now registered
		// by RestServiceProvider via RestRouteRegistrar and ResponseFormatter.

		if ( class_exists( 'AgentWP\\Billing\\UsageTracker' ) ) {
			Billing\UsageTracker::init();
		}

		if ( class_exists( 'AgentWP\\Search\\Index' ) ) {
			Search\Index::init();
		}

		if ( class_exists( 'AgentWP\\Handlers\\BulkHandler' ) ) {
			Handlers\BulkHandler::register_hooks();
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
	 * Register admin menu entry under WooCommerce.
	 *
	 * @return void
	 */
	public function register_menu() {
		$this->menu_hook = add_submenu_page(
			'woocommerce',
			esc_html__( 'AgentWP', 'agentwp' ),
			esc_html__( 'AgentWP', 'agentwp' ),
			'manage_woocommerce',
			'agentwp',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Render the admin screen placeholder.
	 *
	 * @return void
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'AgentWP', 'agentwp' ) . '</h1>';
		echo '<p>' . esc_html__( 'AgentWP admin UI will load here.', 'agentwp' ) . '</p>';
		echo '<div id="agentwp-admin-root" class="agentwp-admin"></div>';
		echo '</div>';
	}

	/**
	 * Enqueue admin assets only on AgentWP and WooCommerce screens.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue_admin_assets( $hook_suffix ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		$is_agentwp_screen = ( $hook_suffix === $this->menu_hook );
		$is_wc_screen      = $screen ? $this->is_woocommerce_screen( $screen ) : false;

		if ( ! $is_agentwp_screen && ! $is_wc_screen ) {
			return;
		}

		$script_path = AGENTWP_PLUGIN_DIR . 'assets/agentwp-admin.js';
		$style_path  = AGENTWP_PLUGIN_DIR . 'assets/agentwp-admin.css';

		if ( file_exists( $script_path ) ) {
			$script_ver = filemtime( $script_path );
			$script_ver = is_int( $script_ver ) ? (string) $script_ver : null;

			$settings = get_option( self::OPTION_SETTINGS, array() );
			$settings = is_array( $settings ) ? $settings : array();
			$settings = wp_parse_args( $settings, self::get_default_settings() );
			$demo_mode = ! empty( $settings['demo_mode'] );

			wp_enqueue_script(
				'agentwp-admin',
				AGENTWP_PLUGIN_URL . 'assets/agentwp-admin.js',
				array( 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n' ),
				$script_ver,
				true
			);

			wp_enqueue_style( 'wp-components' );
			$theme = $this->get_user_theme_preference();

			// Use JSON_HEX_TAG to prevent XSS via </script> in JSON strings.
			wp_add_inline_script(
				'agentwp-admin',
				'window.agentwpSettings = ' . wp_json_encode(
					array(
						'root'  => esc_url_raw( rest_url() ),
						'nonce' => wp_create_nonce( 'wp_rest' ),
						'theme' => $theme,
						'supportEmail' => sanitize_email( get_option( 'admin_email' ) ),
						'version' => defined( 'AGENTWP_VERSION' ) ? AGENTWP_VERSION : '',
						'demoMode' => $demo_mode,
					),
					JSON_HEX_TAG | JSON_HEX_AMP
				),
				'before'
			);
		}

		if ( file_exists( $style_path ) ) {
			$style_ver = filemtime( $style_path );
			$style_ver = is_int( $style_ver ) ? (string) $style_ver : null;

			wp_enqueue_style(
				'agentwp-admin',
				AGENTWP_PLUGIN_URL . 'assets/agentwp-admin.css',
				array(),
				$style_ver
			);
		}
	}

	/**
	 * Inject initial theme attribute to prevent flashes.
	 *
	 * @return void
	 */
	public function output_theme_attribute() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		$is_agentwp_screen = ( $screen && $this->menu_hook && $screen->id === $this->menu_hook );
		$is_wc_screen      = $screen ? $this->is_woocommerce_screen( $screen ) : false;

		if ( ! $is_agentwp_screen && ! $is_wc_screen ) {
			return;
		}

		$theme = $this->get_user_theme_preference();

		$script = '(function(){';
		// Use JSON_HEX_TAG to prevent XSS via </script> in JSON strings.
		$script .= 'var theme=' . wp_json_encode( $theme, JSON_HEX_TAG | JSON_HEX_AMP ) . ';';
		$script .= "if(!theme){try{theme=window.localStorage.getItem('agentwp-theme-preference');}catch(e){theme='';}}";
		$script .= "if(theme!=='light'&&theme!=='dark'){return;}";
		$script .= 'var root=document.documentElement;';
		$script .= 'root.dataset.theme=theme;';
		$script .= 'root.style.colorScheme=theme;';
		$script .= '})();';

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Theme values are validated above.
		echo '<script>' . $script . '</script>';
	}

	/**
	 * Retrieve the current user's theme preference.
	 *
	 * @return string
	 */
	private function get_user_theme_preference() {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return '';
		}

		$meta_key = class_exists( 'AgentWP\\API\\ThemeController' )
			? API\ThemeController::THEME_META_KEY
			: 'agentwp_theme_preference';
		$theme    = get_user_meta( $user_id, $meta_key, true );

		return in_array( $theme, array( 'light', 'dark' ), true ) ? $theme : '';
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register_rest_routes() {
		// Use container-based registration if available.
		if ( $this->container->has( RestRouteRegistrar::class ) ) {
			/** @var RestRouteRegistrar $registrar */
			$registrar = $this->container->get( RestRouteRegistrar::class );
			$registrar->registerRoutes();
			return;
		}

		// Fallback to direct instantiation for backward compatibility.
		if ( class_exists( 'AgentWP\\Rest\\SettingsController' ) ) {
			$controller = new Rest\SettingsController();
			$controller->register_routes();
		}

		if ( class_exists( 'AgentWP\\Rest\\IntentController' ) ) {
			$controller = new Rest\IntentController();
			$controller->register_routes();
		}

		if ( class_exists( 'AgentWP\\Rest\\HealthController' ) ) {
			$controller = new Rest\HealthController();
			$controller->register_routes();
		}

		if ( class_exists( 'AgentWP\\Rest\\SearchController' ) ) {
			$controller = new Rest\SearchController();
			$controller->register_routes();
		}

		if ( class_exists( 'AgentWP\\API\\HistoryController' ) ) {
			$controller = new API\HistoryController();
			$controller->register_routes();
		}

		if ( class_exists( 'AgentWP\\API\\ThemeController' ) ) {
			$controller = new API\ThemeController();
			$controller->register_routes();
		}
	}

	/**
	 * Normalize REST responses and log requests.
	 *
	 * @param mixed           $result Response value.
	 * @param \WP_REST_Server $server REST server instance.
	 * @param \WP_REST_Request<array<string, mixed>> $request Request instance.
	 * @return mixed
	 */
	public function format_rest_response( $result, $server, $request ) {
		if ( ! $this->is_agentwp_route( $request ) ) {
			return $result;
		}

		// Use ResponseFormatter from container if available.
		if ( ! $this->container->has( ResponseFormatter::class ) ) {
			return $result;
		}

		/** @var ResponseFormatter $formatter */
		$formatter = $this->container->get( ResponseFormatter::class );
		return $formatter->formatResponse( $result, $server, $request );
	}

	/**
	 * Check whether request targets AgentWP REST routes.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request instance.
	 * @return bool
	 */
	private function is_agentwp_route( $request ) {
		$route = $request->get_route();
		$route = is_string( $route ) ? $route : '';

		return ( 0 === strpos( $route, '/' . API\RestController::REST_NAMESPACE ) );
	}

	/**
	 * Default settings values.
	 *
	 * @return array
	 */
	public static function get_default_settings() {
		return array(
			'model'             => 'gpt-4o-mini',
			'budget_limit'      => 0,
			'draft_ttl_minutes' => 10,
			'hotkey'            => 'Cmd+K / Ctrl+K',
			'theme'             => 'light',
			'demo_mode'         => false,
		);
	}

	/**
	 * Default usage stats values.
	 *
	 * @return array
	 */
	public static function get_default_usage_stats() {
		return array(
			'total_tokens'        => 0,
			'total_cost_usd'      => 0,
			'breakdown_by_intent' => array(),
			'daily_trend'         => array(),
			'period_start'        => '',
			'period_end'          => '',
		);
	}

	/**
	 * Determine whether a screen is WooCommerce-related.
	 *
	 * @param \WP_Screen $screen Current screen.
	 * @return bool
	 */
	private function is_woocommerce_screen( $screen ) {
		if ( function_exists( 'wc_get_screen_ids' ) ) {
			$wc_screens = wc_get_screen_ids();
			return in_array( $screen->id, $wc_screens, true );
		}

		return ( false !== strpos( $screen->id, 'woocommerce' ) );
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
