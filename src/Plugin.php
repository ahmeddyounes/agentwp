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
	}

	/**
	 * Set up hooks.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_head', array( $this, 'output_theme_attribute' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_filter( 'rest_post_dispatch', array( $this, 'format_rest_response' ), 10, 3 );

		if ( class_exists( 'AgentWP\\Billing\\UsageTracker' ) ) {
			Billing\UsageTracker::init();
		}

		if ( class_exists( 'AgentWP\\Search\\Index' ) ) {
			Search\Index::init();
		}
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
			$theme = $this->get_user_theme_preference();

			wp_add_inline_script(
				'agentwp-admin',
				'window.agentwpSettings = ' . wp_json_encode(
					array(
						'root'  => esc_url_raw( rest_url() ),
						'nonce' => wp_create_nonce( 'wp_rest' ),
						'theme' => $theme,
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
		$script .= 'var theme=' . wp_json_encode( $theme ) . ';';
		$script .= "if(!theme){try{theme=window.localStorage.getItem('agentwp-theme-preference');}catch(e){theme='';}}";
		$script .= "if(theme!=='light'&&theme!=='dark'){return;}";
		$script .= 'var root=document.documentElement;';
		$script .= 'root.dataset.theme=theme;';
		$script .= 'root.style.colorScheme=theme;';
		$script .= '})();';

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
	 * @param \WP_REST_Request $request Request instance.
	 * @return mixed
	 */
	public function format_rest_response( $result, $server, $request ) {
		if ( ! $this->is_agentwp_route( $request ) ) {
			return $result;
		}

		$status     = 200;
		$error_code = '';

		if ( is_wp_error( $result ) ) {
			$error_code = $result->get_error_code();
			$message    = $result->get_error_message();
			$data       = $result->get_error_data();
			$status     = ( is_array( $data ) && isset( $data['status'] ) ) ? intval( $data['status'] ) : 500;

			$response = rest_ensure_response(
				array(
					'success' => false,
					'data'    => array(),
					'error'   => array(
						'code'    => $error_code,
						'message' => $message,
					),
				)
			);

			if ( $response instanceof \WP_REST_Response ) {
				$response->set_status( $status );
				if ( is_array( $data ) && isset( $data['retry_after'] ) ) {
					$response->header( 'Retry-After', (string) intval( $data['retry_after'] ) );
				}
			}

			$result = $response;
		} elseif ( $result instanceof \WP_REST_Response ) {
			$status = $result->get_status();
			$body   = $result->get_data();

			if ( is_array( $body ) && isset( $body['code'], $body['message'] ) ) {
				$error_code = (string) $body['code'];
				$message    = (string) $body['message'];
				$data       = isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : array();
				$status     = isset( $data['status'] ) ? intval( $data['status'] ) : $status;

				$response = rest_ensure_response(
					array(
						'success' => false,
						'data'    => array(),
						'error'   => array(
							'code'    => $error_code,
							'message' => $message,
						),
					)
				);

				if ( $response instanceof \WP_REST_Response ) {
					$response->set_status( $status );
					if ( isset( $data['retry_after'] ) ) {
						$response->header( 'Retry-After', (string) intval( $data['retry_after'] ) );
					}
				}

				$result = $response;
			} elseif ( is_array( $body ) && isset( $body['error']['code'] ) ) {
				$error_code = (string) $body['error']['code'];
			}
		} else {
			$result = rest_ensure_response(
				array(
					'success' => true,
					'data'    => $result,
				)
			);

			if ( $result instanceof \WP_REST_Response ) {
				$status = $result->get_status();
			}
		}

		if ( class_exists( 'AgentWP\\API\\RestController' ) ) {
			API\RestController::log_request( $request, $status, $error_code );
		}

		return $result;
	}

	/**
	 * Check whether request targets AgentWP REST routes.
	 *
	 * @param \WP_REST_Request $request Request instance.
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
