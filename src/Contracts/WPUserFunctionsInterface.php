<?php
/**
 * WordPress user functions interface.
 *
 * @package AgentWP\Contracts
 */

namespace AgentWP\Contracts;

/**
 * Contract for WordPress user-related functions.
 *
 * This interface enables testability by abstracting WordPress's global
 * user functions. Business logic should depend on this interface rather
 * than calling WordPress functions directly.
 */
interface WPUserFunctionsInterface {

	/**
	 * Get the current user ID.
	 *
	 * @return int Current user ID, or 0 if not logged in.
	 */
	public function getCurrentUserId(): int;
}
