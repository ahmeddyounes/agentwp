<?php
/**
 * Verify AgentWP REST responses include no-cache headers.
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
	fwrite( STDERR, "Health response missing.\n" );
	exit( 1 );
}

$headers = $response->get_headers();
$cache   = '';

foreach ( $headers as $key => $value ) {
	if ( 'cache-control' === strtolower( (string) $key ) ) {
		$cache = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		break;
	}
}

if ( '' === $cache ) {
	fwrite( STDERR, "Cache-Control header missing.\n" );
	exit( 1 );
}

if ( false === strpos( $cache, 'no-store' ) && false === strpos( $cache, 'no-cache' ) ) {
	fwrite( STDERR, "Cache-Control header missing no-cache directive: {$cache}\n" );
	exit( 1 );
}

echo "Cache-Control header OK: {$cache}\n";
