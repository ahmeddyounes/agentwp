<?php
/**
 * Null logger implementation.
 *
 * @package AgentWP\Infrastructure
 */

namespace AgentWP\Infrastructure;

use AgentWP\Contracts\LoggerInterface;

/**
 * Logger that discards all messages.
 *
 * Use this when logging is not needed or to satisfy dependency injection
 * without actual logging behavior.
 */
final class NullLogger implements LoggerInterface {

	/**
	 * {@inheritDoc}
	 */
	public function emergency( string $message, array $context = array() ): void {
		unset( $message, $context );
	}

	/**
	 * {@inheritDoc}
	 */
	public function alert( string $message, array $context = array() ): void {
		unset( $message, $context );
	}

	/**
	 * {@inheritDoc}
	 */
	public function critical( string $message, array $context = array() ): void {
		unset( $message, $context );
	}

	/**
	 * {@inheritDoc}
	 */
	public function error( string $message, array $context = array() ): void {
		unset( $message, $context );
	}

	/**
	 * {@inheritDoc}
	 */
	public function warning( string $message, array $context = array() ): void {
		unset( $message, $context );
	}

	/**
	 * {@inheritDoc}
	 */
	public function notice( string $message, array $context = array() ): void {
		unset( $message, $context );
	}

	/**
	 * {@inheritDoc}
	 */
	public function info( string $message, array $context = array() ): void {
		unset( $message, $context );
	}

	/**
	 * {@inheritDoc}
	 */
	public function debug( string $message, array $context = array() ): void {
		unset( $message, $context );
	}
}
