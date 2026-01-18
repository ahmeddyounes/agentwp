<?php
/**
 * Audit logger interface.
 *
 * @package AgentWP\Contracts
 */

namespace AgentWP\Contracts;

/**
 * Contract for audit logging services.
 *
 * Provides structured audit logging for sensitive actions.
 * All audit events are logged at 'notice' level for traceability.
 */
interface AuditLoggerInterface {

	/**
	 * Log an API key update event.
	 *
	 * @param string $action      Action performed: 'stored', 'deleted', 'rotated'.
	 * @param int    $user_id     User who performed the action.
	 * @param string $key_last4   Last 4 characters of the key (for stored/rotated).
	 * @param array  $extra       Additional context.
	 * @return void
	 */
	public function logApiKeyUpdate( string $action, int $user_id, string $key_last4 = '', array $extra = array() ): void;

	/**
	 * Log a draft confirmation event.
	 *
	 * @param string $draft_type  Type of draft: 'stock', 'status', 'refund'.
	 * @param string $draft_id    Draft identifier.
	 * @param int    $user_id     User who confirmed.
	 * @param array  $details     Operation-specific details.
	 * @return void
	 */
	public function logDraftConfirmation( string $draft_type, string $draft_id, int $user_id, array $details = array() ): void;

	/**
	 * Log a generic sensitive action.
	 *
	 * @param string $action   Action name.
	 * @param int    $user_id  User who performed the action.
	 * @param array  $context  Additional context.
	 * @return void
	 */
	public function logSensitiveAction( string $action, int $user_id, array $context = array() ): void;
}
