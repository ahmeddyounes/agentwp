<?php
/**
 * Current user context implementation.
 *
 * @package AgentWP\Infrastructure
 */

namespace AgentWP\Infrastructure;

use AgentWP\Contracts\CurrentUserContextInterface;
use AgentWP\Contracts\WPUserFunctionsInterface;

/**
 * WordPress-backed current user context.
 *
 * Provides the current user identity using WPFunctions,
 * which wraps WordPress core functions for testability.
 */
final class CurrentUserContext implements CurrentUserContextInterface {

	/**
	 * WordPress functions wrapper.
	 *
	 * @var WPUserFunctionsInterface
	 */
	private WPUserFunctionsInterface $wp;

	/**
	 * Create a new CurrentUserContext.
	 *
	 * @param WPUserFunctionsInterface $wp WordPress functions wrapper.
	 */
	public function __construct( WPUserFunctionsInterface $wp ) {
		$this->wp = $wp;
	}

	/**
	 * {@inheritDoc}
	 */
	public function getUserId(): int {
		return $this->wp->getCurrentUserId();
	}

	/**
	 * {@inheritDoc}
	 */
	public function isLoggedIn(): bool {
		return $this->wp->getCurrentUserId() > 0;
	}
}
