<?php
/**
 * WooCommerce user gateway interface.
 *
 * @package AgentWP\Contracts
 */

namespace AgentWP\Contracts;

/**
 * Contract for WordPress/WooCommerce user operations.
 *
 * Abstracts get_userdata() and related user functions.
 */
interface WooCommerceUserGatewayInterface {

	/**
	 * Get user data by ID.
	 *
	 * @param int $user_id User ID.
	 * @return array|null User data array or null if not found.
	 */
	public function get_user( int $user_id ): ?array;

	/**
	 * Get user email by ID.
	 *
	 * @param int $user_id User ID.
	 * @return string|null Email or null if not found.
	 */
	public function get_user_email( int $user_id ): ?string;

	/**
	 * Get user display name by ID.
	 *
	 * @param int $user_id User ID.
	 * @return string|null Display name or null if not found.
	 */
	public function get_user_display_name( int $user_id ): ?string;

	/**
	 * Get current timestamp in WordPress timezone.
	 *
	 * @return int Unix timestamp.
	 */
	public function get_current_timestamp(): int;
}
