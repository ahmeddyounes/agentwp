<?php
/**
 * Admin menu manager.
 *
 * @package AgentWP\Plugin
 */

namespace AgentWP\Plugin;

/**
 * Manages WordPress admin menu registration.
 */
final class AdminMenuManager {

	/**
	 * Menu hook suffix.
	 *
	 * @var string
	 */
	private string $menuHook = '';

	/**
	 * Page render callback.
	 *
	 * @var callable
	 */
	private $renderCallback;

	/**
	 * Create a new AdminMenuManager.
	 *
	 * @param callable|null $renderCallback Optional render callback.
	 */
	public function __construct( ?callable $renderCallback = null ) {
		$this->renderCallback = $renderCallback ?? array( $this, 'renderDefaultPage' );
	}

	/**
	 * Register the admin menu.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'addMenu' ) );
	}

	/**
	 * Add the submenu page under WooCommerce.
	 *
	 * @return void
	 */
	public function addMenu(): void {
		$hookOrFalse = add_submenu_page(
			'woocommerce',
			esc_html__( 'AgentWP', 'agentwp' ),
			esc_html__( 'AgentWP', 'agentwp' ),
			'manage_woocommerce',
			'agentwp',
			$this->renderCallback
		);

		// add_submenu_page returns false if user lacks capability.
		$this->menuHook = false !== $hookOrFalse ? $hookOrFalse : '';
	}

	/**
	 * Get the menu hook suffix.
	 *
	 * @return string
	 */
	public function getMenuHook(): string {
		return $this->menuHook;
	}

	/**
	 * Check if current screen is the AgentWP admin page.
	 *
	 * @param string $hookSuffix Current admin page hook.
	 * @return bool
	 */
	public function isAgentWPScreen( string $hookSuffix ): bool {
		return $hookSuffix === $this->menuHook && '' !== $this->menuHook;
	}

	/**
	 * Default page render callback.
	 *
	 * @return void
	 */
	public function renderDefaultPage(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'AgentWP', 'agentwp' ) . '</h1>';
		echo '<p>' . esc_html__( 'AgentWP admin UI will load here.', 'agentwp' ) . '</p>';
		echo '<div id="agentwp-root" class="agentwp-admin"></div>';
		echo '</div>';
	}

	/**
	 * Set a custom render callback.
	 *
	 * @param callable $callback Render callback.
	 * @return void
	 */
	public function setRenderCallback( callable $callback ): void {
		$this->renderCallback = $callback;
	}

	/**
	 * Output the React mount node.
	 *
	 * This renders the mount point for the Command Deck UI. It should be called
	 * via admin_footer on screens where the UI should be accessible but isn't
	 * the dedicated AgentWP page (e.g., WooCommerce screens).
	 *
	 * @return void
	 */
	public function outputMountNode(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		echo '<div id="agentwp-root" class="agentwp-admin"></div>';
	}
}
