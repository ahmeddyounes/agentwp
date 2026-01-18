<?php
/**
 * Plugin uninstall cleanup.
 *
 * @package AgentWP
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$autoload = __DIR__ . '/vendor/autoload.php';
if ( file_exists( $autoload ) ) {
	require_once $autoload;
}

require_once __DIR__ . '/src/Plugin/Uninstall.php';

if ( class_exists( 'AgentWP\\Plugin\\Uninstall' ) ) {
	AgentWP\Plugin\Uninstall::run();
}
