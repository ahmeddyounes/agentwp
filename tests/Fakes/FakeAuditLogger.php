<?php
/**
 * Fake audit logger for testing.
 *
 * @package AgentWP\Tests\Fakes
 */

namespace AgentWP\Tests\Fakes;

use AgentWP\Contracts\AuditLoggerInterface;

/**
 * Audit logger that captures events for assertions.
 */
final class FakeAuditLogger implements AuditLoggerInterface {

	/**
	 * Captured audit events.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $logs = array();

	/**
	 * {@inheritDoc}
	 */
	public function logApiKeyUpdate( string $action, int $user_id, string $key_last4 = '', array $extra = array() ): void {
		$this->logs[] = array(
			'type'      => 'api_key',
			'action'    => $action,
			'user_id'   => $user_id,
			'key_last4' => $key_last4,
			'context'   => $extra,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function logDraftConfirmation( string $draft_type, string $draft_id, int $user_id, array $details = array() ): void {
		$this->logs[] = array(
			'type'       => 'draft_confirm',
			'draft_type' => $draft_type,
			'draft_id'   => $draft_id,
			'user_id'    => $user_id,
			'context'    => $details,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function logSensitiveAction( string $action, int $user_id, array $context = array() ): void {
		$this->logs[] = array(
			'type'    => 'sensitive',
			'action'  => $action,
			'user_id' => $user_id,
			'context' => $context,
		);
	}

	/**
	 * Get all audit events.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getLogs(): array {
		return $this->logs;
	}

	/**
	 * Get last audit event.
	 *
	 * @return array<string, mixed>|null
	 */
	public function getLastLog(): ?array {
		if ( empty( $this->logs ) ) {
			return null;
		}

		return $this->logs[ count( $this->logs ) - 1 ];
	}
}
