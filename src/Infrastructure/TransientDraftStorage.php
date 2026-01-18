<?php
/**
 * Transient-based draft storage implementation.
 *
 * @package AgentWP\Infrastructure
 */

namespace AgentWP\Infrastructure;

use AgentWP\Config\AgentWPConfig;
use AgentWP\Contracts\CurrentUserContextInterface;
use AgentWP\Contracts\DraftStorageInterface;
use AgentWP\Plugin;

/**
 * Stores drafts using WordPress transients.
 */
class TransientDraftStorage implements DraftStorageInterface {

	/**
	 * Default TTL in seconds (1 hour).
	 */
	private const DEFAULT_TTL = 3600;

	/**
	 * Current user context provider.
	 *
	 * @var CurrentUserContextInterface|null
	 */
	private ?CurrentUserContextInterface $userContext;

	/**
	 * Create a new TransientDraftStorage.
	 *
	 * @param CurrentUserContextInterface|null $userContext User context provider (optional for backwards compatibility).
	 */
	public function __construct( ?CurrentUserContextInterface $userContext = null ) {
		$this->userContext = $userContext;
	}

	/**
	 * Generate a unique draft ID.
	 *
	 * @param string $prefix Prefix for the draft ID.
	 * @return string Generated draft ID.
	 */
	public function generate_id( string $prefix = 'draft' ): string {
		return $prefix . '_' . wp_generate_password( 12, false );
	}

	/**
	 * Store a draft.
	 *
	 * @param string $type Draft type identifier.
	 * @param string $id   Draft ID.
	 * @param array  $data Draft data.
	 * @param int    $ttl  Time to live in seconds.
	 * @return bool True on success.
	 */
	public function store( string $type, string $id, array $data, int $ttl = self::DEFAULT_TTL ): bool {
		$key = $this->build_key( $type, $id );
		return set_transient( $key, $data, $ttl );
	}

	/**
	 * Retrieve a draft without deleting it.
	 *
	 * @param string $type Draft type identifier.
	 * @param string $id   Draft ID.
	 * @return array|null Draft data or null if not found.
	 */
	public function get( string $type, string $id ): ?array {
		$key  = $this->build_key( $type, $id );
		$data = get_transient( $key );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Claim and delete a draft.
	 *
	 * @param string $type Draft type identifier.
	 * @param string $id   Draft ID.
	 * @return array|null Draft data or null if not found.
	 */
	public function claim( string $type, string $id ): ?array {
		$key  = $this->build_key( $type, $id );
		$data = get_transient( $key );

		if ( is_array( $data ) ) {
			delete_transient( $key );
			return $data;
		}

		return null;
	}

	/**
	 * Delete a draft.
	 *
	 * @param string $type Draft type identifier.
	 * @param string $id   Draft ID.
	 * @return bool True on success.
	 */
	public function delete( string $type, string $id ): bool {
		$key = $this->build_key( $type, $id );
		return delete_transient( $key );
	}

	/**
	 * Build the transient key.
	 *
	 * @param string $type Draft type.
	 * @param string $id   Draft ID.
	 * @return string Transient key.
	 */
	private function build_key( string $type, string $id ): string {
		$user_id = $this->getCurrentUserId();
		return Plugin::TRANSIENT_PREFIX . $type . '_' . AgentWPConfig::CACHE_PREFIX_DRAFT . $user_id . '_' . $id;
	}

	/**
	 * Get the current user ID from the injected context or fallback to WP global.
	 *
	 * @return int User ID.
	 */
	private function getCurrentUserId(): int {
		if ( $this->userContext !== null ) {
			return $this->userContext->getUserId();
		}

		// Fallback for backwards compatibility.
		return function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	}
}
