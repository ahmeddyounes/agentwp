<?php
/**
 * Compatibility checks for runtime requirements.
 *
 * @package AgentWP
 */

namespace AgentWP\Compatibility;

class Environment {
	const MIN_PHP = '8.0';
	const MIN_WP  = '6.4';
	const MIN_WC  = '8.0';

	/**
	 * Register admin notice hooks.
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );
		add_action( 'network_admin_notices', array( __CLASS__, 'render_notice' ) );
	}

	/**
	 * Determine whether the environment meets requirements.
	 *
	 * @return bool
	 */
	public static function is_compatible() {
		return empty( self::get_issues() );
	}

	/**
	 * Render admin notice for incompatible environments.
	 *
	 * @return void
	 */
	public static function render_notice() {
		if ( self::is_compatible() ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$issues = self::get_issues();
		if ( empty( $issues ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'AgentWP is disabled because your environment does not meet minimum requirements:', 'agentwp' );
		echo '</p><ul>';

		foreach ( $issues as $issue ) {
			echo '<li>' . esc_html( $issue ) . '</li>';
		}

		echo '</ul><p>';
		printf(
			'%s %s',
			esc_html__( 'Minimums:', 'agentwp' ),
			esc_html( sprintf( 'PHP %s, WordPress %s, WooCommerce %s.', self::MIN_PHP, self::MIN_WP, self::MIN_WC ) )
		);
		echo '</p></div>';
	}

	/**
	 * Gather compatibility issues.
	 *
	 * @return string[]
	 */
	private static function get_issues() {
		global $wp_version;

		$issues = array();
		$php    = phpversion();
		$php    = is_string( $php ) ? $php : '';
		$wp     = is_string( $wp_version ) ? $wp_version : '';
		$wc     = defined( 'WC_VERSION' ) ? WC_VERSION : '';

		if ( $php && version_compare( $php, self::MIN_PHP, '<' ) ) {
			$issues[] = sprintf(
				'PHP %s detected; AgentWP requires PHP %s or higher.',
				$php,
				self::MIN_PHP
			);
		}

		if ( $wp && version_compare( $wp, self::MIN_WP, '<' ) ) {
			$issues[] = sprintf(
				'WordPress %s detected; AgentWP requires WordPress %s or higher.',
				$wp,
				self::MIN_WP
			);
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			$issues[] = 'WooCommerce is not active. Activate WooCommerce to use AgentWP.';
		} elseif ( $wc && version_compare( $wc, self::MIN_WC, '<' ) ) {
			$issues[] = sprintf(
				'WooCommerce %s detected; AgentWP requires WooCommerce %s or higher.',
				$wc,
				self::MIN_WC
			);
		}

		return $issues;
	}
}
