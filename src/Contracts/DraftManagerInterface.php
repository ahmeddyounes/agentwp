<?php
/**
 * Draft manager interface.
 *
 * @package AgentWP\Contracts
 */

namespace AgentWP\Contracts;

use AgentWP\DTO\DraftPayload;
use AgentWP\DTO\ServiceResult;

/**
 * Interface for unified draft lifecycle management.
 *
 * Provides a standardized layer on top of DraftStorageInterface
 * for consistent draft id generation, payload shape, TTL, claim semantics,
 * and error handling across all draft-based flows.
 */
interface DraftManagerInterface {

	/**
	 * Create a draft with standardized payload shape.
	 *
	 * Generates a unique ID, wraps payload with metadata, applies TTL from settings,
	 * and stores the draft.
	 *
	 * @param string $type    Draft type identifier (e.g., 'refund', 'status', 'stock').
	 * @param array  $payload Operation-specific data.
	 * @param array  $preview Human-readable preview data for confirmation UI.
	 * @return ServiceResult Success result with draft_id and preview, or failure result.
	 */
	public function create( string $type, array $payload, array $preview = array() ): ServiceResult;

	/**
	 * Retrieve a draft without consuming it.
	 *
	 * @param string $type     Draft type identifier.
	 * @param string $draft_id Draft ID.
	 * @return DraftPayload|null Draft payload or null if not found/expired.
	 */
	public function get( string $type, string $draft_id ): ?DraftPayload;

	/**
	 * Claim and consume a draft atomically.
	 *
	 * This is the standard way to execute a draft operation: retrieve the draft
	 * and delete it in one atomic operation, preventing double-execution.
	 *
	 * @param string $type     Draft type identifier.
	 * @param string $draft_id Draft ID.
	 * @return ServiceResult Success result with payload data, or draft_expired failure.
	 */
	public function claim( string $type, string $draft_id ): ServiceResult;

	/**
	 * Cancel/delete a draft without executing it.
	 *
	 * @param string $type     Draft type identifier.
	 * @param string $draft_id Draft ID.
	 * @return bool True if deleted, false if not found.
	 */
	public function cancel( string $type, string $draft_id ): bool;

	/**
	 * Get the current TTL in seconds for drafts.
	 *
	 * @return int TTL in seconds.
	 */
	public function getTtlSeconds(): int;
}
