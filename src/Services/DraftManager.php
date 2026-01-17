<?php
/**
 * Unified draft manager implementation.
 *
 * @package AgentWP\Services
 */

namespace AgentWP\Services;

use AgentWP\Contracts\DraftManagerInterface;
use AgentWP\Contracts\DraftStorageInterface;
use AgentWP\DTO\DraftPayload;
use AgentWP\DTO\ServiceResult;
use AgentWP\Plugin\SettingsManager;

/**
 * Unified draft lifecycle manager.
 *
 * Provides a standardized layer on top of DraftStorageInterface for consistent:
 * - Draft ID generation (type-prefixed, 12-char random)
 * - Payload shape (id, type, payload, preview, created_at, expires_at)
 * - TTL handling (from settings, in seconds)
 * - Claim semantics (atomic get + delete)
 * - Error handling (ServiceResult pattern)
 */
class DraftManager implements DraftManagerInterface {

	private DraftStorageInterface $storage;
	private SettingsManager $settings;

	/**
	 * Constructor.
	 *
	 * @param DraftStorageInterface $storage  Draft storage implementation.
	 * @param SettingsManager       $settings Settings manager for TTL.
	 */
	public function __construct(
		DraftStorageInterface $storage,
		SettingsManager $settings
	) {
		$this->storage  = $storage;
		$this->settings = $settings;
	}

	/**
	 * Create a draft with standardized payload shape.
	 *
	 * @param string $type    Draft type identifier.
	 * @param array  $payload Operation-specific data.
	 * @param array  $preview Human-readable preview data.
	 * @return ServiceResult Success with draft_id and preview, or failure.
	 */
	public function create( string $type, array $payload, array $preview = array() ): ServiceResult {
		$draft_id   = $this->storage->generate_id( $type );
		$ttl        = $this->getTtlSeconds();
		$now        = time();
		$expires_at = $now + $ttl;

		$draft_data = array(
			'id'         => $draft_id,
			'type'       => $type,
			'payload'    => $payload,
			'preview'    => $preview,
			'created_at' => $now,
			'expires_at' => $expires_at,
		);

		$stored = $this->storage->store( $type, $draft_id, $draft_data, $ttl );

		if ( ! $stored ) {
			return ServiceResult::operationFailed( 'Failed to save draft.' );
		}

		return ServiceResult::success(
			'Draft created.',
			array(
				'draft_id'   => $draft_id,
				'type'       => $type,
				'preview'    => $preview,
				'expires_at' => $expires_at,
				'ttl'        => $ttl,
			)
		);
	}

	/**
	 * Retrieve a draft without consuming it.
	 *
	 * @param string $type     Draft type identifier.
	 * @param string $draft_id Draft ID.
	 * @return DraftPayload|null
	 */
	public function get( string $type, string $draft_id ): ?DraftPayload {
		$data = $this->storage->get( $type, $draft_id );

		if ( ! $data ) {
			return null;
		}

		return DraftPayload::fromArray( $data );
	}

	/**
	 * Claim and consume a draft atomically.
	 *
	 * @param string $type     Draft type identifier.
	 * @param string $draft_id Draft ID.
	 * @return ServiceResult Success with payload, or draft_expired failure.
	 */
	public function claim( string $type, string $draft_id ): ServiceResult {
		$data = $this->storage->claim( $type, $draft_id );

		if ( ! $data ) {
			return ServiceResult::draftExpired( 'Draft not found or expired.' );
		}

		$draft = DraftPayload::fromArray( $data );

		if ( ! $draft ) {
			return ServiceResult::draftExpired( 'Draft data is invalid.' );
		}

		return ServiceResult::success(
			'Draft claimed.',
			array(
				'draft'   => $draft->toArray(),
				'payload' => $draft->payload,
				'preview' => $draft->preview,
			)
		);
	}

	/**
	 * Cancel/delete a draft.
	 *
	 * @param string $type     Draft type identifier.
	 * @param string $draft_id Draft ID.
	 * @return bool
	 */
	public function cancel( string $type, string $draft_id ): bool {
		return $this->storage->delete( $type, $draft_id );
	}

	/**
	 * Get the current TTL in seconds.
	 *
	 * @return int
	 */
	public function getTtlSeconds(): int {
		// Settings stores TTL in minutes, convert to seconds.
		return $this->settings->getDraftTtl() * 60;
	}
}
