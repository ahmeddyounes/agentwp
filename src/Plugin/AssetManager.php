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
		add_action( 'admin_footer', array( $this, 'outputMountNode' ) );
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
	 * Output the mount node on WooCommerce screens.
	 *
	 * The mount node is already rendered on the AgentWP page via renderDefaultPage(),
	 * so this only outputs on WooCommerce screens where the Command Deck should be
	 * accessible but isn't the dedicated AgentWP admin page.
	 *
	 * @return void
	 */
	public function outputMountNode(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( null === $screen ) {
			return;
		}

		// Skip the AgentWP page - it already has the mount node via renderDefaultPage().
		if ( $this->menuManager->isAgentWPScreen( $screen->id ) ) {
			return;
		}

		// Only output on WooCommerce screens.
		if ( ! $this->isWooCommerceScreen( $screen ) ) {
			return;
		}

		$this->menuManager->outputMountNode();
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
		$manifest = $this->getViteManifest();

		if ( null !== $manifest ) {
			$this->enqueueFromManifest( $manifest );
		} else {
			$this->enqueueLegacyScript();
		}

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
	 * Get the Vite manifest if available.
	 *
	 * @return array|null Manifest data or null if not available.
	 */
	private function getViteManifest(): ?array {
		$manifestPath = $this->pluginDir . '/assets/build/.vite/manifest.json';

		if ( ! file_exists( $manifestPath ) ) {
			return null;
		}

		global $wp_filesystem;

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		WP_Filesystem();

		if ( ! $wp_filesystem instanceof \WP_Filesystem_Base ) {
			return null;
		}

		$contents = $wp_filesystem->get_contents( $manifestPath );

		if ( false === $contents ) {
			return null;
		}

		$manifest = json_decode( $contents, true );

		if ( ! is_array( $manifest ) ) {
			return null;
		}

		return $manifest;
	}

	/**
	 * Enqueue scripts and styles from Vite manifest.
	 *
	 * @param array $manifest Vite manifest data.
	 * @return void
	 */
	private function enqueueFromManifest( array $manifest ): void {
		$entryKey = 'index.html';

		if ( ! isset( $manifest[ $entryKey ] ) ) {
			$this->enqueueLegacyScript();
			return;
		}

		$entry    = $manifest[ $entryKey ];
		$buildUrl = $this->pluginUrl . '/assets/build';
		$buildDir = $this->pluginDir . '/assets/build';

		// Enqueue the entry JS file.
		$entryFile    = $entry['file'];
		$entryPath    = $buildDir . '/' . $entryFile;
		$entryVersion = file_exists( $entryPath ) ? filemtime( $entryPath ) : false;

		wp_enqueue_script(
			'agentwp-admin',
			$buildUrl . '/' . $entryFile,
			array( 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n' ),
			false !== $entryVersion ? (string) $entryVersion : $this->version,
			true
		);

		// Add module type for ES module support.
		add_filter(
			'script_loader_tag',
			function ( $tag, $handle ) {
				if ( 'agentwp-admin' === $handle ) {
					return str_replace( ' src', ' type="module" src', $tag );
				}
				return $tag;
			},
			10,
			2
		);

		// Enqueue imported chunks.
		if ( ! empty( $entry['imports'] ) ) {
			foreach ( $entry['imports'] as $importKey ) {
				if ( isset( $manifest[ $importKey ] ) ) {
					$this->enqueueChunk( $manifest[ $importKey ], $manifest, $buildUrl, $buildDir );
				}
			}
		}

		// Enqueue CSS from entry.
		if ( ! empty( $entry['css'] ) ) {
			foreach ( $entry['css'] as $index => $cssFile ) {
				$cssPath    = $buildDir . '/' . $cssFile;
				$cssVersion = file_exists( $cssPath ) ? filemtime( $cssPath ) : false;

				wp_enqueue_style(
					'agentwp-admin-css-' . $index,
					$buildUrl . '/' . $cssFile,
					array(),
					false !== $cssVersion ? (string) $cssVersion : $this->version
				);
			}
		}
	}

	/**
	 * Enqueue a chunk and its dependencies.
	 *
	 * @param array  $chunk    Chunk data from manifest.
	 * @param array  $manifest Full manifest data.
	 * @param string $buildUrl Base URL for build assets.
	 * @param string $buildDir Base directory for build assets.
	 * @return void
	 */
	private function enqueueChunk( array $chunk, array $manifest, string $buildUrl, string $buildDir ): void {
		static $enqueuedChunks = array();

		$chunkFile = $chunk['file'];

		if ( isset( $enqueuedChunks[ $chunkFile ] ) ) {
			return;
		}

		$enqueuedChunks[ $chunkFile ] = true;

		// Recursively enqueue imports first.
		if ( ! empty( $chunk['imports'] ) ) {
			foreach ( $chunk['imports'] as $importKey ) {
				if ( isset( $manifest[ $importKey ] ) ) {
					$this->enqueueChunk( $manifest[ $importKey ], $manifest, $buildUrl, $buildDir );
				}
			}
		}

		// Preload this chunk via modulepreload link.
		$chunkPath    = $buildDir . '/' . $chunkFile;
		$chunkVersion = file_exists( $chunkPath ) ? filemtime( $chunkPath ) : false;

		$chunkUrl = $buildUrl . '/' . $chunkFile;
		if ( false !== $chunkVersion ) {
			$chunkUrl = add_query_arg( 'ver', $chunkVersion, $chunkUrl );
		}

		add_action(
			'admin_head',
			function () use ( $chunkUrl ) {
				printf(
					'<link rel="modulepreload" href="%s" />',
					esc_url( $chunkUrl )
				);
			}
		);

		// Enqueue CSS from chunk.
		if ( ! empty( $chunk['css'] ) ) {
			foreach ( $chunk['css'] as $index => $cssFile ) {
				$cssPath    = $buildDir . '/' . $cssFile;
				$cssVersion = file_exists( $cssPath ) ? filemtime( $cssPath ) : false;
				$handle     = 'agentwp-chunk-' . sanitize_title( basename( $chunkFile, '.js' ) ) . '-css-' . $index;

				wp_enqueue_style(
					$handle,
					$buildUrl . '/' . $cssFile,
					array(),
					false !== $cssVersion ? (string) $cssVersion : $this->version
				);
			}
		}
	}

	/**
	 * Enqueue the legacy admin script (fallback when manifest unavailable).
	 *
	 * @deprecated since 0.2.0 - The legacy wp-element UI bundle is deprecated.
	 *             Build the React UI from react/ directory. Will be removed in 1.0.0.
	 *
	 * @return void
	 */
	private function enqueueLegacyScript(): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error -- Intentional deprecation notice.
		trigger_error(
			'AgentWP: The legacy wp-element UI (agentwp-admin.js) is deprecated since 0.2.0 and will be removed in 1.0.0. ' .
			'Please build the React UI from the react/ directory.',
			E_USER_DEPRECATED
		);

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
			'assetsUrl'    => $this->pluginUrl . '/assets/build/',
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
