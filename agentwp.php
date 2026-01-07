<?php
/**
 * Plugin Name: AgentWP
 * Plugin URI: https://agentwp.example
 * Description: React-powered admin UI for WooCommerce automation.
 * Version: 0.1.0
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * WC requires at least: 8.0
 * Author: AgentWP
 * Text Domain: agentwp
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AGENTWP_VERSION', '0.1.0' );
define( 'AGENTWP_PLUGIN_FILE', __FILE__ );
define( 'AGENTWP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AGENTWP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

$agentwp_autoload = AGENTWP_PLUGIN_DIR . 'vendor/autoload.php';
if ( file_exists( $agentwp_autoload ) ) {
	require_once $agentwp_autoload;
} else {
	spl_autoload_register(
		function ( $class ) {
			$prefix   = 'AgentWP\\';
			$base_dir = AGENTWP_PLUGIN_DIR . 'src/';

			if ( 0 !== strpos( $class, $prefix ) ) {
				return;
			}

			$relative_class = substr( $class, strlen( $prefix ) );
			$file           = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

			if ( file_exists( $file ) ) {
				require $file;
			}
		}
	);
}

register_activation_hook( __FILE__, array( 'AgentWP\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'AgentWP\\Plugin', 'deactivate' ) );

add_action(
	'plugins_loaded',
	function () {
		if ( class_exists( 'AgentWP\\Compatibility\\Environment' ) ) {
			AgentWP\Compatibility\Environment::boot();
			if ( ! AgentWP\Compatibility\Environment::is_compatible() ) {
				return;
			}
		}

		if ( class_exists( 'AgentWP\\Plugin' ) ) {
			AgentWP\Plugin::init();
		}
	}
);
