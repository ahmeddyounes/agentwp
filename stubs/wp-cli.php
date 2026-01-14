<?php
/**
 * WP-CLI stubs for PHPStan.
 *
 * These declarations are only loaded by static analysis (see `phpstan.neon`).
 * They should never be included at runtime.
 */

class WP_CLI_Command {}

class WP_CLI {
	/** @param callable|string|object $callable */
	public static function add_command( string $name, $callable ): void {}

	public static function success( string $message ): void {}

	/** @param bool|int $exit */
	public static function error( string $message, $exit = true ): void {}

	public static function log( string $message ): void {}
}

