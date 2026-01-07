<?php
/**
 * OpenAI VCR for AgentWP integration tests.
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

if ( ! defined( 'AGENTWP_E2E' ) || ! AGENTWP_E2E ) {
	return;
}

function agentwp_openai_vcr_mode() {
	$mode = getenv( 'AGENTWP_OPENAI_MODE' );
	$mode = is_string( $mode ) ? strtolower( trim( $mode ) ) : '';

	if ( ! in_array( $mode, array( 'playback', 'record', 'live' ), true ) ) {
		$mode = 'playback';
	}

	return $mode;
}

function agentwp_openai_vcr_fixture_dir() {
	$dir = getenv( 'AGENTWP_OPENAI_FIXTURES' );
	$dir = is_string( $dir ) ? trim( $dir ) : '';

	if ( '' !== $dir ) {
		return rtrim( $dir, '/' );
	}

	return WP_PLUGIN_DIR . '/agentwp/tests/fixtures/openai';
}

function agentwp_openai_vcr_should_capture( $url ) {
	if ( ! is_string( $url ) ) {
		return false;
	}

	return false !== strpos( $url, 'api.openai.com/v1' );
}

function agentwp_openai_vcr_normalize_body( $body ) {
	if ( is_array( $body ) ) {
		return wp_json_encode( $body );
	}

	if ( is_string( $body ) ) {
		return $body;
	}

	return '';
}

function agentwp_openai_vcr_hash( $method, $url, $body = '' ) {
	$method = is_string( $method ) ? strtoupper( $method ) : 'GET';
	$url    = is_string( $url ) ? $url : '';
	$body   = is_string( $body ) ? $body : '';

	return sha1( $method . '|' . $url . '|' . $body );
}

function agentwp_openai_vcr_hash_url( $method, $url ) {
	$method = is_string( $method ) ? strtoupper( $method ) : 'GET';
	$url    = is_string( $url ) ? $url : '';

	return sha1( $method . '|' . $url );
}

function agentwp_openai_vcr_load_fixture( $method, $url, $body ) {
	$dir = agentwp_openai_vcr_fixture_dir();
	if ( ! is_dir( $dir ) ) {
		return null;
	}

	$body_hash = agentwp_openai_vcr_hash( $method, $url, $body );
	$body_path = $dir . '/' . $body_hash . '.json';
	if ( file_exists( $body_path ) ) {
		$contents = file_get_contents( $body_path );
		return is_string( $contents ) ? json_decode( $contents, true ) : null;
	}

	$url_hash = agentwp_openai_vcr_hash_url( $method, $url );
	$url_path = $dir . '/' . $url_hash . '.json';
	if ( file_exists( $url_path ) ) {
		$contents = file_get_contents( $url_path );
		return is_string( $contents ) ? json_decode( $contents, true ) : null;
	}

	return null;
}

function agentwp_openai_vcr_build_response( array $fixture ) {
	return array(
		'headers'  => isset( $fixture['headers'] ) && is_array( $fixture['headers'] ) ? $fixture['headers'] : array(),
		'body'     => isset( $fixture['body'] ) ? $fixture['body'] : '',
		'response' => array(
			'code'    => isset( $fixture['status'] ) ? (int) $fixture['status'] : 200,
			'message' => isset( $fixture['message'] ) ? (string) $fixture['message'] : 'OK',
		),
		'cookies'  => array(),
		'filename' => null,
	);
}

add_filter(
	'pre_http_request',
	function ( $preempt, $args, $url ) {
		if ( false !== $preempt || ! agentwp_openai_vcr_should_capture( $url ) ) {
			return $preempt;
		}

		$mode = agentwp_openai_vcr_mode();
		if ( 'live' === $mode || 'record' === $mode ) {
			return $preempt;
		}

		$method  = isset( $args['method'] ) ? $args['method'] : 'GET';
		$body    = agentwp_openai_vcr_normalize_body( isset( $args['body'] ) ? $args['body'] : '' );
		$fixture = agentwp_openai_vcr_load_fixture( $method, $url, $body );

		if ( null === $fixture ) {
			return new WP_Error( 'agentwp_vcr_missing', 'OpenAI fixture not found for request.' );
		}

		return agentwp_openai_vcr_build_response( $fixture );
	},
	10,
	3
);

add_filter(
	'http_response',
	function ( $response, $args, $url ) {
		if ( ! agentwp_openai_vcr_should_capture( $url ) || 'record' !== agentwp_openai_vcr_mode() ) {
			return $response;
		}

		$method = isset( $args['method'] ) ? $args['method'] : 'GET';
		$body   = agentwp_openai_vcr_normalize_body( isset( $args['body'] ) ? $args['body'] : '' );
		$hash   = agentwp_openai_vcr_hash( $method, $url, $body );
		$dir    = agentwp_openai_vcr_fixture_dir();

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$fixture = array(
			'status'  => isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0,
			'headers' => isset( $response['headers'] ) ? $response['headers'] : array(),
			'body'    => isset( $response['body'] ) ? $response['body'] : '',
		);

		file_put_contents( $dir . '/' . $hash . '.json', wp_json_encode( $fixture, JSON_PRETTY_PRINT ) );

		return $response;
	},
	10,
	3
);
