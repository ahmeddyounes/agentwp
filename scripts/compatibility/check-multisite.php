<?php
/**
 * Verify AgentWP works in multisite environments.
 *
 * @package AgentWP
 */

if ( ! is_multisite() ) {
	fwrite( STDERR, "Multisite is not enabled.\n" );
	exit( 1 );
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';

if ( ! is_plugin_active( 'agentwp/agentwp.php' ) && ! is_plugin_active_for_network( 'agentwp/agentwp.php' ) ) {
	fwrite( STDERR, "AgentWP is not active in multisite.\n" );
	exit( 1 );
}

wp_set_current_user( 1 );

$request  = new WP_REST_Request( 'GET', '/agentwp/v1/health' );
$response = rest_do_request( $request );

if ( ! $response instanceof WP_REST_Response ) {
	fwrite( STDERR, "Multisite health response missing.\n" );
	exit( 1 );
}

if ( 200 !== $response->get_status() ) {
	fwrite( STDERR, "Multisite health check failed: status {$response->get_status()}.\n" );
	exit( 1 );
}

echo "Multisite compatibility OK.\n";
