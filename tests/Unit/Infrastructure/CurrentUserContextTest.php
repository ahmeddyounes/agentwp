<?php
/**
 * Unit tests for CurrentUserContext class.
 */

namespace AgentWP\Tests\Unit\Infrastructure;

use AgentWP\Contracts\CurrentUserContextInterface;
use AgentWP\Infrastructure\CurrentUserContext;
use AgentWP\Tests\Fakes\FakeWPFunctions;
use AgentWP\Tests\TestCase;

class CurrentUserContextTest extends TestCase {

	private FakeWPFunctions $wpFunctions;
	private CurrentUserContext $userContext;

	public function setUp(): void {
		parent::setUp();
		$this->wpFunctions = new FakeWPFunctions();
		$this->userContext = new CurrentUserContext( $this->wpFunctions );
	}

	public function test_implements_interface(): void {
		$this->assertInstanceOf( CurrentUserContextInterface::class, $this->userContext );
	}

	public function test_get_user_id_returns_id_from_wp_functions(): void {
		$this->wpFunctions->setCurrentUserId( 42 );

		$result = $this->userContext->getUserId();

		$this->assertSame( 42, $result );
	}

	public function test_get_user_id_returns_zero_for_logged_out_user(): void {
		$this->wpFunctions->setCurrentUserId( 0 );

		$result = $this->userContext->getUserId();

		$this->assertSame( 0, $result );
	}

	public function test_is_logged_in_returns_true_for_positive_user_id(): void {
		$this->wpFunctions->setCurrentUserId( 123 );

		$result = $this->userContext->isLoggedIn();

		$this->assertTrue( $result );
	}

	public function test_is_logged_in_returns_false_for_zero_user_id(): void {
		$this->wpFunctions->setCurrentUserId( 0 );

		$result = $this->userContext->isLoggedIn();

		$this->assertFalse( $result );
	}
}
