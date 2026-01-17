#!/usr/bin/env php
<?php
/**
 * OpenAPI validation script.
 *
 * Compares @openapi annotations in controllers against docs/openapi.json
 * and reports any drift (missing or extra endpoints).
 *
 * Usage: php scripts/openapi-validate.php [--fix]
 *
 * @package AgentWP
 */

declare(strict_types=1);

$project_root = dirname( __DIR__ );
$openapi_path = $project_root . '/docs/openapi.json';

/**
 * Directories to scan for @openapi annotations.
 */
$controller_dirs = array(
	$project_root . '/src/Rest',
	$project_root . '/src/API',
);

/**
 * Extract @openapi annotations from PHP files.
 *
 * @param array $directories Directories to scan.
 * @return array<string, array{method: string, path: string, file: string, line: int}>
 */
function extract_annotations( array $directories ): array {
	$annotations = array();

	foreach ( $directories as $dir ) {
		if ( ! is_dir( $dir ) ) {
			continue;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( $file->getExtension() !== 'php' ) {
				continue;
			}

			$content = file_get_contents( $file->getPathname() );
			if ( $content === false ) {
				continue;
			}

			// Match @openapi METHOD /path patterns in PHPDoc comments.
			if ( preg_match_all(
				'/\*\s*@openapi\s+(GET|POST|PUT|PATCH|DELETE)\s+(\/\S+)/i',
				$content,
				$matches,
				PREG_SET_ORDER | PREG_OFFSET_CAPTURE
			) ) {
				foreach ( $matches as $match ) {
					$method = strtolower( $match[1][0] );
					$path   = $match[2][0];
					$offset = $match[0][1];

					// Calculate line number from offset.
					$line = substr_count( substr( $content, 0, $offset ), "\n" ) + 1;

					$key                 = "{$method} {$path}";
					$annotations[ $key ] = array(
						'method' => $method,
						'path'   => $path,
						'file'   => $file->getPathname(),
						'line'   => $line,
					);
				}
			}
		}
	}

	ksort( $annotations );
	return $annotations;
}

/**
 * Extract endpoints from OpenAPI spec.
 *
 * @param string $openapi_path Path to openapi.json.
 * @return array<string, array{method: string, path: string}>
 */
function extract_spec_endpoints( string $openapi_path ): array {
	if ( ! file_exists( $openapi_path ) ) {
		return array();
	}

	$content = file_get_contents( $openapi_path );
	if ( $content === false ) {
		return array();
	}

	$spec = json_decode( $content, true );
	if ( ! is_array( $spec ) || ! isset( $spec['paths'] ) ) {
		return array();
	}

	$endpoints = array();
	foreach ( $spec['paths'] as $path => $methods ) {
		if ( ! is_array( $methods ) ) {
			continue;
		}
		foreach ( $methods as $method => $definition ) {
			// Skip non-method keys like 'parameters'.
			if ( ! in_array( $method, array( 'get', 'post', 'put', 'patch', 'delete' ), true ) ) {
				continue;
			}
			$key               = "{$method} {$path}";
			$endpoints[ $key ] = array(
				'method' => $method,
				'path'   => $path,
			);
		}
	}

	ksort( $endpoints );
	return $endpoints;
}

/**
 * Validate openapi.json structure.
 *
 * @param string $openapi_path Path to openapi.json.
 * @return array{valid: bool, errors: array}
 */
function validate_spec_structure( string $openapi_path ): array {
	$errors = array();

	if ( ! file_exists( $openapi_path ) ) {
		return array(
			'valid'  => false,
			'errors' => array( 'File not found: ' . $openapi_path ),
		);
	}

	$content = file_get_contents( $openapi_path );
	if ( $content === false ) {
		return array(
			'valid'  => false,
			'errors' => array( 'Unable to read file: ' . $openapi_path ),
		);
	}

	$spec = json_decode( $content, true );
	if ( json_last_error() !== JSON_ERROR_NONE ) {
		return array(
			'valid'  => false,
			'errors' => array( 'Invalid JSON: ' . json_last_error_msg() ),
		);
	}

	// Check required fields.
	$required = array( 'openapi', 'info', 'paths' );
	foreach ( $required as $field ) {
		if ( ! isset( $spec[ $field ] ) ) {
			$errors[] = "Missing required field: {$field}";
		}
	}

	// Validate OpenAPI version.
	if ( isset( $spec['openapi'] ) && ! preg_match( '/^3\.\d+\.\d+$/', $spec['openapi'] ) ) {
		$errors[] = "Invalid OpenAPI version: {$spec['openapi']} (expected 3.x.x)";
	}

	return array(
		'valid'  => empty( $errors ),
		'errors' => $errors,
	);
}

// Parse command line arguments.
$fix_mode = in_array( '--fix', $argv, true );

echo "OpenAPI Validation\n";
echo str_repeat( '=', 50 ) . "\n\n";

// Step 1: Validate spec structure.
echo "Validating openapi.json structure...\n";
$structure = validate_spec_structure( $openapi_path );
if ( ! $structure['valid'] ) {
	echo "\n❌ Spec structure validation failed:\n";
	foreach ( $structure['errors'] as $error ) {
		echo "   - {$error}\n";
	}
	exit( 1 );
}
echo "✓ Spec structure is valid\n\n";

// Step 2: Extract annotations and spec endpoints.
echo "Extracting @openapi annotations from controllers...\n";
$annotations = extract_annotations( $controller_dirs );
echo "Found " . count( $annotations ) . " annotations\n\n";

echo "Extracting endpoints from openapi.json...\n";
$spec_endpoints = extract_spec_endpoints( $openapi_path );
echo "Found " . count( $spec_endpoints ) . " endpoints\n\n";

// Step 3: Compare.
$annotation_keys = array_keys( $annotations );
$spec_keys       = array_keys( $spec_endpoints );

$missing_from_spec       = array_diff( $annotation_keys, $spec_keys );
$missing_from_code       = array_diff( $spec_keys, $annotation_keys );
$documented_in_both      = array_intersect( $annotation_keys, $spec_keys );

$has_errors = false;

echo "Validation Results\n";
echo str_repeat( '-', 50 ) . "\n\n";

if ( ! empty( $missing_from_spec ) ) {
	$has_errors = true;
	echo "❌ Endpoints annotated in code but MISSING from openapi.json:\n";
	foreach ( $missing_from_spec as $key ) {
		$info = $annotations[ $key ];
		$relative_file = str_replace( $project_root . '/', '', $info['file'] );
		echo "   - {$key}\n";
		echo "     Source: {$relative_file}:{$info['line']}\n";
	}
	echo "\n";
}

if ( ! empty( $missing_from_code ) ) {
	$has_errors = true;
	echo "❌ Endpoints in openapi.json but MISSING @openapi annotation in code:\n";
	foreach ( $missing_from_code as $key ) {
		echo "   - {$key}\n";
	}
	echo "\n   These may be stale entries that should be removed from openapi.json,\n";
	echo "   or the controller methods are missing @openapi annotations.\n\n";
}

if ( ! empty( $documented_in_both ) ) {
	echo "✓ Synchronized endpoints (" . count( $documented_in_both ) . "):\n";
	foreach ( $documented_in_both as $key ) {
		echo "   - {$key}\n";
	}
	echo "\n";
}

// Summary.
echo str_repeat( '=', 50 ) . "\n";
if ( $has_errors ) {
	echo "❌ VALIDATION FAILED\n\n";
	echo "To fix:\n";
	echo "1. For missing spec entries: Add the endpoint definition to docs/openapi.json\n";
	echo "2. For missing annotations: Add @openapi tag to controller method or remove stale spec entry\n";
	echo "\nSee docs/DEVELOPER.md for OpenAPI maintenance workflow.\n";
	exit( 1 );
} else {
	echo "✓ ALL ENDPOINTS SYNCHRONIZED\n";
	echo "Controller annotations match openapi.json\n";
	exit( 0 );
}
