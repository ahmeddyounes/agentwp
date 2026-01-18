<?php
/**
 * Current user context interface.
 *
 * @package AgentWP\Contracts
 */

namespace AgentWP\Contracts;

/**
 * Contract for providing current user context.
 *
 * This interface abstracts the runtime user identity so that services
 * don't depend directly on WordPress globals like get_current_user_id().
 * This enables:
 * - Unit testing with mock user contexts
 * - Explicit user context in background jobs or CLI commands
 * - Consistent user identification across the application
 */
interface CurrentUserContextInterface {

	/**
	 * Get the current user ID.
	 *
	 * @return int User ID, or 0 if no user is logged in.
	 */
	public function getUserId(): int;

	/**
	 * Check if a user is currently logged in.
	 *
	 * @return bool True if a user is logged in.
	 */
	public function isLoggedIn(): bool;
}
