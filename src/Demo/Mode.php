<?php
/**
 * Demo mode helpers.
 *
 * @package AgentWP
 */

namespace AgentWP\Demo;

use AgentWP\Plugin;
use AgentWP\Plugin\SettingsManager;
use AgentWP\Security\ApiKeyStorage;

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

		$settings = get_option( SettingsManager::OPTION_SETTINGS, array() );
		$settings = is_array( $settings ) ? $settings : array();
		$settings = wp_parse_args( $settings, SettingsManager::getDefaults() );

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

		$storage = self::getApiKeyStorage();
		if ( ! $storage ) {
			return '';
		}

		return $storage->retrieveDemo();
	}

	/**
	 * Store demo API key at rest.
	 *
	 * @param string $api_key Demo API key.
	 * @return bool
	 */
	public static function store_demo_api_key( $api_key ) {
		if ( '' === $api_key ) {
			return false;
		}

		$api_key = sanitize_text_field( wp_unslash( $api_key ) );
		if ( '' === $api_key ) {
			return false;
		}

		$storage = self::getApiKeyStorage();
		if ( ! $storage ) {
			return false;
		}

		$result = $storage->storeDemo( $api_key );
		return true === $result;
	}

	/**
	 * Clear stored demo API key.
	 *
	 * @return void
	 */
	public static function clear_demo_api_key() {
		$storage = self::getApiKeyStorage();
		if ( ! $storage ) {
			return;
		}

		$storage->deleteDemo();
	}

	/**
	 * Get the ApiKeyStorage service from the container.
	 *
	 * @return ApiKeyStorage|null
	 */
	private static function getApiKeyStorage(): ?ApiKeyStorage {
		$container = Plugin::container();
		if ( ! $container || ! $container->has( ApiKeyStorage::class ) ) {
			return null;
		}

		try {
			$storage = $container->get( ApiKeyStorage::class );
			return $storage instanceof ApiKeyStorage ? $storage : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}
}
