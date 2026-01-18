<?php
/**
 * Audit logger implementation.
 *
 * @package AgentWP\Infrastructure
 */

namespace AgentWP\Infrastructure;

use AgentWP\Contracts\AuditLoggerInterface;
use AgentWP\Contracts\ClockInterface;
use AgentWP\Contracts\LoggerInterface;

/**
 * Audit logger that uses the underlying LoggerInterface.
 *
 * Provides structured audit logging for sensitive actions with:
 * - Consistent message formatting
 * - Timestamp and user attribution
 * - Action categorization
 *
 * Uses 'notice' log level for all audit events as they represent
 * significant actions that should be traceable.
 */
final class AuditLogger implements AuditLoggerInterface {

	private LoggerInterface $logger;
	private ClockInterface $clock;

	/**
	 * Create a new AuditLogger.
	 *
	 * @param LoggerInterface $logger Underlying logger.
	 * @param ClockInterface  $clock  Clock for timestamps.
	 */
	public function __construct( LoggerInterface $logger, ClockInterface $clock ) {
		$this->logger = $logger;
		$this->clock  = $clock;
	}

	/**
	 * {@inheritDoc}
	 */
	public function logApiKeyUpdate( string $action, int $user_id, string $key_last4 = '', array $extra = array() ): void {
		$message = $this->formatMessage( 'api_key', $action );

		$context = array_merge(
			array(
				'audit_type' => 'api_key',
				'action'     => $action,
				'user_id'    => $user_id,
				'user_login' => $this->getUserLogin( $user_id ),
				'key_last4'  => $key_last4,
				'timestamp'  => $this->clock->now()->format( 'c' ),
				'ip_address' => $this->getClientIp(),
			),
			$extra
		);

		$this->logger->notice( $message, $context );
	}

	/**
	 * {@inheritDoc}
	 */
	public function logDraftConfirmation( string $draft_type, string $draft_id, int $user_id, array $details = array() ): void {
		$message = $this->formatMessage( 'draft_confirm', $draft_type );

		$context = array_merge(
			array(
				'audit_type' => 'draft_confirm',
				'draft_type' => $draft_type,
				'draft_id'   => $draft_id,
				'user_id'    => $user_id,
				'user_login' => $this->getUserLogin( $user_id ),
				'timestamp'  => $this->clock->now()->format( 'c' ),
				'ip_address' => $this->getClientIp(),
			),
			$details
		);

		$this->logger->notice( $message, $context );
	}

	/**
	 * {@inheritDoc}
	 */
	public function logSensitiveAction( string $action, int $user_id, array $context = array() ): void {
		$message = $this->formatMessage( 'sensitive', $action );

		$full_context = array_merge(
			array(
				'audit_type' => 'sensitive',
				'action'     => $action,
				'user_id'    => $user_id,
				'user_login' => $this->getUserLogin( $user_id ),
				'timestamp'  => $this->clock->now()->format( 'c' ),
				'ip_address' => $this->getClientIp(),
			),
			$context
		);

		$this->logger->notice( $message, $full_context );
	}

	/**
	 * Format audit log message.
	 *
	 * @param string $type   Audit type.
	 * @param string $action Action performed.
	 * @return string Formatted message.
	 */
	private function formatMessage( string $type, string $action ): string {
		return sprintf( '[AUDIT:%s] %s', strtoupper( $type ), $action );
	}

	/**
	 * Get user login name.
	 *
	 * @param int $user_id User ID.
	 * @return string User login or 'unknown'.
	 */
	private function getUserLogin( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return 'anonymous';
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return 'unknown';
		}

		return $user->user_login;
	}

	/**
	 * Get client IP address.
	 *
	 * @return string Client IP or 'unknown'.
	 */
	private function getClientIp(): string {
		// Check for proxy headers first.
		$headers = array(
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_REAL_IP',
			'HTTP_CLIENT_IP',
			'REMOTE_ADDR',
		);

		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
				// X-Forwarded-For may contain multiple IPs, take the first.
				if ( str_contains( $ip, ',' ) ) {
					$ip = trim( explode( ',', $ip )[0] );
				}
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		return 'unknown';
	}
}
