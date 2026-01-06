<?php
/**
 * Core plugin bootstrap.
 *
 * @package AgentWP
 */

namespace AgentWP;

class Plugin {
	const OPTION_SETTINGS     = 'agentwp_settings';
	const OPTION_API_KEY      = 'agentwp_api_key';
	const OPTION_API_KEY_LAST4 = 'agentwp_api_key_last4';
	const OPTION_BUDGET_LIMIT = 'agentwp_budget_limit';
	const OPTION_DRAFT_TTL    = 'agentwp_draft_ttl_minutes';
	const OPTION_USAGE_STATS  = 'agentwp_usage_stats';
	const TRANSIENT_PREFIX    = 'agentwp_';

	/**
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * @var string
	 */
	private $menu_hook = '';

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
	}

	/**
	 * Plugin deactivation handler.
	 *
	 * @return void
	 */
	public static function deactivate() {
		self::delete_transients();
	}

	/**
	 * Set up hooks.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
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
			wp_enqueue_script(
				'agentwp-admin',
				AGENTWP_PLUGIN_URL . 'assets/agentwp-admin.js',
				array( 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n' ),
				filemtime( $script_path ),
				true
			);

			wp_enqueue_style( 'wp-components' );

			wp_add_inline_script(
				'agentwp-admin',
				'window.agentwpSettings = ' . wp_json_encode(
					array(
						'root'  => esc_url_raw( rest_url() ),
						'nonce' => wp_create_nonce( 'wp_rest' ),
					)
				),
				'before'
			);
		}

		if ( file_exists( $style_path ) ) {
			wp_enqueue_style(
				'agentwp-admin',
				AGENTWP_PLUGIN_URL . 'assets/agentwp-admin.css',
				array(),
				filemtime( $style_path )
			);
		}
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register_rest_routes() {
		if ( class_exists( 'AgentWP\\Rest\\SettingsController' ) ) {
			$controller = new Rest\SettingsController();
			$controller->register_routes();
		}
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
		);
	}

	/**
	 * Default usage stats values.
	 *
	 * @return array
	 */
	public static function get_default_usage_stats() {
		return array(
			'total_commands_month' => 0,
			'estimated_cost'       => 0,
			'last_sync'            => '',
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
	}
}
