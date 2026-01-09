<?php
/**
 * Asset manager.
 *
 * @package AgentWP\Plugin
 */

namespace AgentWP\Plugin;

/**
 * Manages script and style enqueuing.
 */
final class AssetManager {

	/**
	 * Settings manager.
	 *
	 * @var SettingsManager
	 */
	private SettingsManager $settings;

	/**
	 * Theme manager.
	 *
	 * @var ThemeManager
	 */
	private ThemeManager $themeManager;

	/**
	 * Admin menu manager.
	 *
	 * @var AdminMenuManager
	 */
	private AdminMenuManager $menuManager;

	/**
	 * Plugin directory path.
	 *
	 * @var string
	 */
	private string $pluginDir;

	/**
	 * Plugin URL.
	 *
	 * @var string
	 */
	private string $pluginUrl;

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	private string $version;

	/**
	 * Create a new AssetManager.
	 *
	 * @param SettingsManager  $settings     Settings manager.
	 * @param ThemeManager     $themeManager Theme manager.
	 * @param AdminMenuManager $menuManager  Admin menu manager.
	 * @param string           $pluginDir    Plugin directory path.
	 * @param string           $pluginUrl    Plugin URL.
	 * @param string           $version      Plugin version.
	 */
	public function __construct(
		SettingsManager $settings,
		ThemeManager $themeManager,
		AdminMenuManager $menuManager,
		string $pluginDir,
		string $pluginUrl,
		string $version = ''
	) {
		$this->settings     = $settings;
		$this->themeManager = $themeManager;
		$this->menuManager  = $menuManager;
		$this->pluginDir    = rtrim( $pluginDir, '/' );
		$this->pluginUrl    = rtrim( $pluginUrl, '/' );
		$this->version      = $version;
	}

	/**
	 * Register asset hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAdminAssets' ) );
		add_action( 'admin_head', array( $this, 'outputThemeAttribute' ) );
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @param string $hookSuffix Current admin page hook.
	 * @return void
	 */
	public function enqueueAdminAssets( string $hookSuffix ): void {
		if ( ! $this->shouldEnqueueAssets( $hookSuffix ) ) {
			return;
		}

		$this->enqueueScript();
		$this->enqueueStyle();
	}

	/**
	 * Output theme attribute in admin head.
	 *
	 * @return void
	 */
	public function outputThemeAttribute(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( null === $screen ) {
			return;
		}

		$isAgentWPScreen = $this->menuManager->isAgentWPScreen( $screen->id );
		$isWCScreen      = $this->isWooCommerceScreen( $screen );

		if ( ! $isAgentWPScreen && ! $isWCScreen ) {
			return;
		}

		$this->themeManager->outputThemeScript();
	}

	/**
	 * Check if assets should be enqueued for the current screen.
	 *
	 * @param string $hookSuffix Current admin page hook.
	 * @return bool
	 */
	private function shouldEnqueueAssets( string $hookSuffix ): bool {
		$isAgentWPScreen = $this->menuManager->isAgentWPScreen( $hookSuffix );

		$screen     = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$isWCScreen = $screen ? $this->isWooCommerceScreen( $screen ) : false;

		return $isAgentWPScreen || $isWCScreen;
	}

	/**
	 * Enqueue the admin JavaScript.
	 *
	 * @return void
	 */
	private function enqueueScript(): void {
		$scriptPath = $this->pluginDir . '/assets/agentwp-admin.js';

		if ( ! file_exists( $scriptPath ) ) {
			return;
		}

		$scriptVersion = filemtime( $scriptPath );

		wp_enqueue_script(
			'agentwp-admin',
			$this->pluginUrl . '/assets/agentwp-admin.js',
			array( 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n' ),
			false !== $scriptVersion ? (string) $scriptVersion : $this->version,
			true
		);

		wp_enqueue_style( 'wp-components' );

		$localization = $this->getScriptLocalization();

		// Use JSON_HEX_TAG to prevent XSS via </script> in JSON strings.
		wp_add_inline_script(
			'agentwp-admin',
			'window.agentwpSettings = ' . wp_json_encode( $localization, JSON_HEX_TAG | JSON_HEX_AMP ),
			'before'
		);
	}

	/**
	 * Enqueue the admin stylesheet.
	 *
	 * @return void
	 */
	private function enqueueStyle(): void {
		$stylePath = $this->pluginDir . '/assets/agentwp-admin.css';

		if ( ! file_exists( $stylePath ) ) {
			return;
		}

		$styleVersion = filemtime( $stylePath );

		wp_enqueue_style(
			'agentwp-admin',
			$this->pluginUrl . '/assets/agentwp-admin.css',
			array(),
			false !== $styleVersion ? (string) $styleVersion : $this->version
		);
	}

	/**
	 * Get script localization data.
	 *
	 * @return array
	 */
	private function getScriptLocalization(): array {
		return array(
			'root'         => esc_url_raw( rest_url() ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'theme'        => $this->themeManager->getUserTheme(),
			'supportEmail' => sanitize_email( get_option( 'admin_email' ) ),
			'version'      => $this->version,
			'demoMode'     => $this->settings->isDemoMode(),
		);
	}

	/**
	 * Check if a screen is WooCommerce-related.
	 *
	 * @param \WP_Screen $screen Screen object.
	 * @return bool
	 */
	private function isWooCommerceScreen( \WP_Screen $screen ): bool {
		if ( function_exists( 'wc_get_screen_ids' ) ) {
			$wcScreens = wc_get_screen_ids();
			return in_array( $screen->id, $wcScreens, true );
		}

		return false !== strpos( $screen->id, 'woocommerce' );
	}
}
