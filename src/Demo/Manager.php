<?php
/**
 * Demo mode orchestration.
 *
 * @package AgentWP
 */

namespace AgentWP\Demo;

class Manager {
	const RESET_HOOK = 'agentwp_demo_daily_reset';

	/**
	 * Boot demo mode integrations.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_enable_demo' ) );
		add_action( self::RESET_HOOK, array( __CLASS__, 'handle_daily_reset' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			CLI::register();
		}
	}

	/**
	 * Set up demo mode hooks when enabled.
	 *
	 * @return void
	 */
	public static function maybe_enable_demo() {
		if ( ! Mode::is_enabled() ) {
			self::unschedule_reset();
			return;
		}

		self::schedule_reset();

		add_action( 'wp_footer', array( __CLASS__, 'render_watermark' ) );
		add_action( 'admin_footer', array( __CLASS__, 'render_watermark' ) );
		add_filter( 'woocommerce_cart_needs_payment', array( __CLASS__, 'disable_payments' ), 99, 2 );
		add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'limit_gateways' ), 99 );
	}

	/**
	 * Disable payment requirements in demo mode.
	 *
	 * @param bool $needs_payment Whether payment is required.
	 * @param mixed $cart Cart instance.
	 * @return bool
	 */
	public static function disable_payments( $needs_payment, $cart ) {
		return false;
	}

	/**
	 * Limit gateways to offline methods in demo mode.
	 *
	 * @param array $gateways Payment gateways.
	 * @return array
	 */
	public static function limit_gateways( $gateways ) {
		if ( ! is_array( $gateways ) ) {
			return $gateways;
		}

		$allowed = array( 'cod', 'bacs' );
		$filtered = array();

		foreach ( $allowed as $gateway_id ) {
			if ( isset( $gateways[ $gateway_id ] ) ) {
				$filtered[ $gateway_id ] = $gateways[ $gateway_id ];
			}
		}

		return empty( $filtered ) ? $gateways : $filtered;
	}

	/**
	 * Render a demo watermark overlay.
	 *
	 * @return void
	 */
	public static function render_watermark() {
		if ( ! Mode::is_enabled() ) {
			return;
		}

		$styles = array(
			'position:fixed',
			'bottom:24px',
			'right:24px',
			'font-size:72px',
			'font-weight:700',
			'letter-spacing:0.3em',
			'text-transform:uppercase',
			'color:rgba(15,23,42,0.08)',
			'transform:rotate(-12deg)',
			'pointer-events:none',
			'z-index:99999',
			'user-select:none',
		);

		echo '<div class="agentwp-demo-watermark" style="' . esc_attr( implode( ';', $styles ) ) . '" aria-hidden="true">Demo</div>';
	}

	/**
	 * Schedule daily demo reset.
	 *
	 * @return void
	 */
	public static function schedule_reset() {
		if ( ! function_exists( 'wp_next_scheduled' ) ) {
			return;
		}

		if ( wp_next_scheduled( self::RESET_HOOK ) ) {
			return;
		}

		wp_schedule_event( time() + 600, 'daily', self::RESET_HOOK );
	}

	/**
	 * Unschedule demo reset.
	 *
	 * @return void
	 */
	public static function unschedule_reset() {
		if ( ! function_exists( 'wp_next_scheduled' ) ) {
			return;
		}

		$timestamp = wp_next_scheduled( self::RESET_HOOK );
		while ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::RESET_HOOK );
			$timestamp = wp_next_scheduled( self::RESET_HOOK );
		}
	}

	/**
	 * Run daily reset handler.
	 *
	 * @return void
	 */
	public static function handle_daily_reset() {
		if ( ! Mode::is_enabled() ) {
			return;
		}

		Resetter::run( true );
	}

	/**
	 * Clean up when plugin deactivates.
	 *
	 * @return void
	 */
	public static function deactivate() {
		self::unschedule_reset();
	}
}
