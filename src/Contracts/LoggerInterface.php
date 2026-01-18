<?php
/**
 * Logger interface.
 *
 * @package AgentWP\Contracts
 */

namespace AgentWP\Contracts;

/**
 * Contract for logging services.
 *
 * Provides a minimal PSR-3 inspired interface for logging at various levels.
 * Implementations should sanitize messages to prevent leaking secrets.
 */
interface LoggerInterface {

	/**
	 * Log an emergency message.
	 *
	 * System is unusable.
	 *
	 * @param string               $message Log message.
	 * @param array<string, mixed> $context Additional context data.
	 * @return void
	 */
	public function emergency( string $message, array $context = array() ): void;

	/**
	 * Log an alert message.
	 *
	 * Action must be taken immediately.
	 *
	 * @param string               $message Log message.
	 * @param array<string, mixed> $context Additional context data.
	 * @return void
	 */
	public function alert( string $message, array $context = array() ): void;

	/**
	 * Log a critical message.
	 *
	 * Critical conditions.
	 *
	 * @param string               $message Log message.
	 * @param array<string, mixed> $context Additional context data.
	 * @return void
	 */
	public function critical( string $message, array $context = array() ): void;

	/**
	 * Log an error message.
	 *
	 * Runtime errors that do not require immediate action.
	 *
	 * @param string               $message Log message.
	 * @param array<string, mixed> $context Additional context data.
	 * @return void
	 */
	public function error( string $message, array $context = array() ): void;

	/**
	 * Log a warning message.
	 *
	 * Exceptional occurrences that are not errors.
	 *
	 * @param string               $message Log message.
	 * @param array<string, mixed> $context Additional context data.
	 * @return void
	 */
	public function warning( string $message, array $context = array() ): void;

	/**
	 * Log a notice message.
	 *
	 * Normal but significant events.
	 *
	 * @param string               $message Log message.
	 * @param array<string, mixed> $context Additional context data.
	 * @return void
	 */
	public function notice( string $message, array $context = array() ): void;

	/**
	 * Log an informational message.
	 *
	 * Interesting events.
	 *
	 * @param string               $message Log message.
	 * @param array<string, mixed> $context Additional context data.
	 * @return void
	 */
	public function info( string $message, array $context = array() ): void;

	/**
	 * Log a debug message.
	 *
	 * Detailed debug information.
	 *
	 * @param string               $message Log message.
	 * @param array<string, mixed> $context Additional context data.
	 * @return void
	 */
	public function debug( string $message, array $context = array() ): void;
}
