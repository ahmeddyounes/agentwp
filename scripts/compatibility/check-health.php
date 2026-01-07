<?php
/**
 * Smoke test for AgentWP REST health endpoint.
 *
 * @package AgentWP
 */

if ( ! function_exists( 'rest_do_request' ) ) {
	fwrite( STDERR, "REST API is unavailable.\n" );
	exit( 1 );
}

wp_set_current_user( 1 );

$request  = new WP_REST_Request( 'GET', '/agentwp/v1/health' );
$response = rest_do_request( $request );

if ( ! $response instanceof WP_REST_Response ) {
	fwrite( STDERR, "Health check failed: missing response.\n" );
	exit( 1 );
}

$status = $response->get_status();
$data   = $response->get_data();

if ( 200 !== $status || empty( $data['success'] ) ) {
	fwrite( STDERR, "Health check failed: status {$status}.\n" );
	exit( 1 );
}

echo "AgentWP health check OK.\n";
