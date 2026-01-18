<?php
/**
 * Fake logger for testing.
 *
 * @package AgentWP\Tests\Fakes
 */

namespace AgentWP\Tests\Fakes;

use AgentWP\Contracts\LoggerInterface;

/**
 * Logger that captures all messages for test assertions.
 */
final class FakeLogger implements LoggerInterface {

	/**
	 * Log entries.
	 *
	 * @var array<int, array{level: string, message: string, context: array<string, mixed>}>
	 */
	private array $logs = array();

	/**
	 * {@inheritDoc}
	 */
	public function emergency( string $message, array $context = array() ): void {
		$this->log( 'emergency', $message, $context );
	}

	/**
	 * {@inheritDoc}
	 */
	public function alert( string $message, array $context = array() ): void {
		$this->log( 'alert', $message, $context );
	}

	/**
	 * {@inheritDoc}
	 */
	public function critical( string $message, array $context = array() ): void {
		$this->log( 'critical', $message, $context );
	}

	/**
	 * {@inheritDoc}
	 */
	public function error( string $message, array $context = array() ): void {
		$this->log( 'error', $message, $context );
	}

	/**
	 * {@inheritDoc}
	 */
	public function warning( string $message, array $context = array() ): void {
		$this->log( 'warning', $message, $context );
	}

	/**
	 * {@inheritDoc}
	 */
	public function notice( string $message, array $context = array() ): void {
		$this->log( 'notice', $message, $context );
	}

	/**
	 * {@inheritDoc}
	 */
	public function info( string $message, array $context = array() ): void {
		$this->log( 'info', $message, $context );
	}

	/**
	 * {@inheritDoc}
	 */
	public function debug( string $message, array $context = array() ): void {
		$this->log( 'debug', $message, $context );
	}

	/**
	 * Add log entry.
	 *
	 * @param string               $level   Log level.
	 * @param string               $message Log message.
	 * @param array<string, mixed> $context Context data.
	 * @return void
	 */
	private function log( string $level, string $message, array $context ): void {
		$this->logs[] = array(
			'level'   => $level,
			'message' => $message,
			'context' => $context,
		);
	}

	// Test helpers.

	/**
	 * Get all log entries.
	 *
	 * @return array<int, array{level: string, message: string, context: array<string, mixed>}>
	 */
	public function getLogs(): array {
		return $this->logs;
	}

	/**
	 * Get log entries filtered by level.
	 *
	 * @param string $level Log level to filter by.
	 * @return array<int, array{level: string, message: string, context: array<string, mixed>}>
	 */
	public function getLogsByLevel( string $level ): array {
		return array_values(
			array_filter(
				$this->logs,
				fn( $log ) => $log['level'] === $level
			)
		);
	}

	/**
	 * Get the last log entry.
	 *
	 * @return array{level: string, message: string, context: array<string, mixed>}|null
	 */
	public function getLastLog(): ?array {
		if ( empty( $this->logs ) ) {
			return null;
		}

		return $this->logs[ count( $this->logs ) - 1 ];
	}

	/**
	 * Check if any log matches the given message pattern.
	 *
	 * @param string $pattern Regex pattern to match.
	 * @return bool
	 */
	public function hasLogMatching( string $pattern ): bool {
		foreach ( $this->logs as $log ) {
			if ( preg_match( $pattern, $log['message'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if any log at a specific level contains the message.
	 *
	 * @param string $level   Log level.
	 * @param string $message Substring to search for.
	 * @return bool
	 */
	public function hasLog( string $level, string $message ): bool {
		foreach ( $this->logs as $log ) {
			if ( $log['level'] === $level && str_contains( $log['message'], $message ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get log count.
	 *
	 * @return int
	 */
	public function getLogCount(): int {
		return count( $this->logs );
	}

	/**
	 * Reset the logger.
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->logs = array();
	}
}
