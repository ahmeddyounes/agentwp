<?php
/**
 * Fake draft manager for testing.
 *
 * @package AgentWP\Tests\Fakes
 */

namespace AgentWP\Tests\Fakes;

use AgentWP\Contracts\DraftManagerInterface;
use AgentWP\DTO\DraftPayload;
use AgentWP\DTO\ServiceResult;

/**
 * In-memory draft manager for testing.
 */
final class FakeDraftManager implements DraftManagerInterface {

	/**
	 * Stored drafts.
	 *
	 * @var array<string, DraftPayload>
	 */
	private array $drafts = array();

	/**
	 * Next draft ID counter.
	 *
	 * @var int
	 */
	private int $nextId = 1;

	/**
	 * TTL in seconds.
	 *
	 * @var int
	 */
	private int $ttl = 300;

	/**
	 * Current time (for testing expiration).
	 *
	 * @var int|null
	 */
	private ?int $currentTime = null;

	/**
	 * Whether to fail the next create operation.
	 *
	 * @var bool
	 */
	private bool $failNextCreate = false;

	/**
	 * {@inheritDoc}
	 */
	public function create( string $type, array $payload, array $preview = array() ): ServiceResult {
		if ( $this->failNextCreate ) {
			$this->failNextCreate = false;
			return ServiceResult::operationFailed( 'Draft creation failed.' );
		}

		$now       = $this->getCurrentTime();
		$draft_id  = 'draft_' . $this->nextId++;
		$expiresAt = $now + $this->ttl;

		$draftPayload = new DraftPayload(
			id: $draft_id,
			type: $type,
			payload: $payload,
			preview: $preview,
			createdAt: $now,
			expiresAt: $expiresAt,
		);

		$key                 = $this->makeKey( $type, $draft_id );
		$this->drafts[ $key ] = $draftPayload;

		return ServiceResult::success(
			'Draft created.',
			array(
				'draft_id'   => $draft_id,
				'type'       => $type,
				'preview'    => $preview,
				'expires_at' => $expiresAt,
				'ttl'        => $this->ttl,
			)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get( string $type, string $draft_id ): ?DraftPayload {
		$key   = $this->makeKey( $type, $draft_id );
		$draft = $this->drafts[ $key ] ?? null;

		if ( null === $draft ) {
			return null;
		}

		if ( $draft->isExpired( $this->getCurrentTime() ) ) {
			unset( $this->drafts[ $key ] );
			return null;
		}

		return $draft;
	}

	/**
	 * {@inheritDoc}
	 */
	public function claim( string $type, string $draft_id ): ServiceResult {
		$draft = $this->get( $type, $draft_id );

		if ( null === $draft ) {
			return ServiceResult::draftExpired( 'Draft not found or expired.' );
		}

		$key = $this->makeKey( $type, $draft_id );
		unset( $this->drafts[ $key ] );

		return ServiceResult::success(
			'Draft claimed.',
			array(
				'payload' => $draft->payload,
				'preview' => $draft->preview,
			)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function cancel( string $type, string $draft_id ): bool {
		$key = $this->makeKey( $type, $draft_id );

		if ( ! isset( $this->drafts[ $key ] ) ) {
			return false;
		}

		unset( $this->drafts[ $key ] );
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function getTtlSeconds(): int {
		return $this->ttl;
	}

	// Test helpers.

	/**
	 * Set the TTL in seconds.
	 *
	 * @param int $ttl TTL in seconds.
	 * @return self
	 */
	public function setTtl( int $ttl ): self {
		$this->ttl = $ttl;
		return $this;
	}

	/**
	 * Set the current time for testing expiration.
	 *
	 * @param int $time Unix timestamp.
	 * @return self
	 */
	public function setCurrentTime( int $time ): self {
		$this->currentTime = $time;
		return $this;
	}

	/**
	 * Advance the current time by seconds.
	 *
	 * @param int $seconds Seconds to advance.
	 * @return self
	 */
	public function advanceTime( int $seconds ): self {
		$this->currentTime = $this->getCurrentTime() + $seconds;
		return $this;
	}

	/**
	 * Set the next create to fail.
	 *
	 * @return self
	 */
	public function failNextCreate(): self {
		$this->failNextCreate = true;
		return $this;
	}

	/**
	 * Get all stored drafts.
	 *
	 * @return array<string, DraftPayload>
	 */
	public function getDrafts(): array {
		return $this->drafts;
	}

	/**
	 * Get draft count.
	 *
	 * @return int
	 */
	public function getDraftCount(): int {
		return count( $this->drafts );
	}

	/**
	 * Clear all drafts.
	 *
	 * @return void
	 */
	public function clear(): void {
		$this->drafts         = array();
		$this->nextId         = 1;
		$this->currentTime    = null;
		$this->failNextCreate = false;
	}

	/**
	 * Get the current time.
	 *
	 * @return int
	 */
	private function getCurrentTime(): int {
		return $this->currentTime ?? time();
	}

	/**
	 * Make a storage key from type and draft ID.
	 *
	 * @param string $type     Draft type.
	 * @param string $draft_id Draft ID.
	 * @return string
	 */
	private function makeKey( string $type, string $draft_id ): string {
		return "{$type}:{$draft_id}";
	}
}
