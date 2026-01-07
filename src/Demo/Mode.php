<?php
/**
 * Demo mode helpers.
 *
 * @package AgentWP
 */

namespace AgentWP\Demo;

use AgentWP\Plugin;
use AgentWP\Security\Encryption;

class Mode {
	/**
	 * Check whether demo mode is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		if ( ! function_exists( 'get_option' ) ) {
			return false;
		}

		$settings = get_option( Plugin::OPTION_SETTINGS, array() );
		$settings = is_array( $settings ) ? $settings : array();
		$settings = wp_parse_args( $settings, Plugin::get_default_settings() );

		return ! empty( $settings['demo_mode'] );
	}

	/**
	 * Get demo API key from config, environment, or stored option.
	 *
	 * @return string
	 */
	public static function get_demo_api_key() {
		if ( defined( 'AGENTWP_DEMO_API_KEY' ) && is_string( AGENTWP_DEMO_API_KEY ) ) {
			return AGENTWP_DEMO_API_KEY;
		}

		$env_key = getenv( 'AGENTWP_DEMO_API_KEY' );
		if ( is_string( $env_key ) && '' !== $env_key ) {
			return $env_key;
		}

		if ( ! function_exists( 'get_option' ) ) {
			return '';
		}

		$stored = get_option( Plugin::OPTION_DEMO_API_KEY, '' );
		$stored = is_string( $stored ) ? $stored : '';
		if ( '' === $stored ) {
			return '';
		}

		$encryption = new Encryption();
		$decrypted  = $encryption->decrypt( $stored );

		if ( '' !== $decrypted ) {
			return $decrypted;
		}

		return $encryption->isEncrypted( $stored ) ? '' : $stored;
	}

	/**
	 * Store demo API key at rest.
	 *
	 * @param string $api_key Demo API key.
	 * @return bool
	 */
	public static function store_demo_api_key( $api_key ) {
		if ( '' === $api_key || ! function_exists( 'update_option' ) ) {
			return false;
		}

		$api_key = sanitize_text_field( wp_unslash( $api_key ) );
		if ( '' === $api_key ) {
			return false;
		}

		$encryption = new Encryption();
		$encrypted  = $encryption->encrypt( $api_key );
		if ( '' === $encrypted ) {
			return false;
		}

		update_option( Plugin::OPTION_DEMO_API_KEY, $encrypted, false );
		update_option( Plugin::OPTION_DEMO_API_KEY_LAST4, substr( $api_key, -4 ), false );

		return true;
	}

	/**
	 * Clear stored demo API key.
	 *
	 * @return void
	 */
	public static function clear_demo_api_key() {
		if ( ! function_exists( 'delete_option' ) ) {
			return;
		}

		delete_option( Plugin::OPTION_DEMO_API_KEY );
		delete_option( Plugin::OPTION_DEMO_API_KEY_LAST4 );
	}
}
