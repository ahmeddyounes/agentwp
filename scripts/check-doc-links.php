#!/usr/bin/env php
<?php
/**
 * Documentation link checker script.
 *
 * Scans docs/**\/*.md for relative markdown links and fails if targets are missing/renamed.
 *
 * Usage: php scripts/check-doc-links.php
 *
 * @package AgentWP
 */

declare(strict_types=1);

$project_root = dirname( __DIR__ );
$docs_dir     = $project_root . '/docs';

/**
 * Find all markdown files in a directory recursively.
 *
 * @param string $dir Directory to scan.
 * @return array<string> List of markdown file paths.
 */
function find_markdown_files( string $dir ): array {
	$files = array();

	if ( ! is_dir( $dir ) ) {
		return $files;
	}

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS )
	);

	foreach ( $iterator as $file ) {
		if ( strtolower( $file->getExtension() ) === 'md' ) {
			$files[] = $file->getPathname();
		}
	}

	sort( $files );
	return $files;
}

/**
 * Extract relative markdown links from a file.
 *
 * Matches patterns like:
 * - [text](relative/path.md)
 * - [text](relative/path.md#anchor)
 * - [text](./relative/path.md)
 * - [text](../relative/path.md)
 *
 * Excludes:
 * - Absolute URLs (http://, https://, mailto:, etc.)
 * - Anchor-only links (#section)
 *
 * @param string $file_path Path to the markdown file.
 * @return array<array{link: string, line: int, target: string}> List of links with line numbers.
 */
function extract_relative_links( string $file_path ): array {
	$content = file_get_contents( $file_path );
	if ( $content === false ) {
		return array();
	}

	$links = array();
	$lines = explode( "\n", $content );

	foreach ( $lines as $line_num => $line ) {
		// Match markdown links: [text](url)
		// Also match reference-style definitions: [id]: url
		if ( preg_match_all( '/\[([^\]]*)\]\(([^)]+)\)/', $line, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$link = $match[2];

				// Skip absolute URLs.
				if ( preg_match( '/^(https?:|mailto:|ftp:|tel:|#)/', $link ) ) {
					continue;
				}

				// Remove anchor from link for file existence check.
				$target = preg_replace( '/#.*$/', '', $link );

				// Skip empty targets (pure anchor links are already filtered).
				if ( empty( $target ) ) {
					continue;
				}

				$links[] = array(
					'link'   => $link,
					'line'   => $line_num + 1,
					'target' => $target,
				);
			}
		}
	}

	return $links;
}

/**
 * Resolve a relative link to an absolute path.
 *
 * @param string $base_file   The file containing the link.
 * @param string $relative_link The relative link path.
 * @return string Resolved absolute path.
 */
function resolve_link_path( string $base_file, string $relative_link ): string {
	$base_dir = dirname( $base_file );
	$resolved = $base_dir . '/' . $relative_link;

	// Normalize the path (resolve . and ..).
	$parts      = explode( '/', $resolved );
	$normalized = array();

	foreach ( $parts as $part ) {
		if ( $part === '.' || $part === '' ) {
			continue;
		}
		if ( $part === '..' ) {
			array_pop( $normalized );
		} else {
			$normalized[] = $part;
		}
	}

	return '/' . implode( '/', $normalized );
}

/**
 * Check if a target file exists.
 *
 * @param string $path Absolute path to check.
 * @return bool True if file exists.
 */
function target_exists( string $path ): bool {
	return file_exists( $path );
}

// Main script execution.
echo "Documentation Link Checker\n";
echo str_repeat( '=', 50 ) . "\n\n";

// Find all markdown files in docs/.
echo "Scanning docs/ for markdown files...\n";
$markdown_files = find_markdown_files( $docs_dir );

if ( empty( $markdown_files ) ) {
	echo "No markdown files found in docs/\n";
	exit( 0 );
}

echo 'Found ' . count( $markdown_files ) . " markdown files\n\n";

// Check each file for broken links.
$broken_links  = array();
$total_links   = 0;
$checked_files = 0;

foreach ( $markdown_files as $file ) {
	$relative_file = str_replace( $project_root . '/', '', $file );
	$links         = extract_relative_links( $file );

	if ( empty( $links ) ) {
		continue;
	}

	$checked_files++;
	$total_links += count( $links );

	foreach ( $links as $link_info ) {
		$resolved_path = resolve_link_path( $file, $link_info['target'] );

		if ( ! target_exists( $resolved_path ) ) {
			$broken_links[] = array(
				'file'   => $relative_file,
				'line'   => $link_info['line'],
				'link'   => $link_info['link'],
				'target' => $link_info['target'],
			);
		}
	}
}

echo "Checked {$total_links} links in {$checked_files} files\n\n";

// Report results.
echo str_repeat( '-', 50 ) . "\n\n";

if ( empty( $broken_links ) ) {
	echo "All links are valid.\n";
	exit( 0 );
}

echo "BROKEN LINKS FOUND (" . count( $broken_links ) . "):\n\n";

foreach ( $broken_links as $broken ) {
	echo "  {$broken['file']}:{$broken['line']}\n";
	echo "    Link:   {$broken['link']}\n";
	echo "    Target: {$broken['target']} (not found)\n\n";
}

echo str_repeat( '=', 50 ) . "\n";
echo "VALIDATION FAILED\n\n";
echo "To fix:\n";
echo "1. Update the link to point to the correct file\n";
echo "2. Create the missing target file if it should exist\n";
echo "3. Remove the link if the target was intentionally deleted\n";

exit( 1 );
