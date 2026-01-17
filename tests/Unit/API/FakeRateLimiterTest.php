<?php
/**
 * FakeRateLimiter unit tests.
 *
 * @package AgentWP\Tests\Unit\API
 */

namespace AgentWP\Tests\Unit\API;

use AgentWP\Tests\Fakes\FakeRateLimiter;
use AgentWP\Tests\TestCase;

class FakeRateLimiterTest extends TestCase {

	private FakeRateLimiter $limiter;

	public function setUp(): void {
		parent::setUp();
		$this->limiter = new FakeRateLimiter( 5, 60 );
	}

	public function tearDown(): void {
		$this->limiter->resetAll();
		parent::tearDown();
	}

	public function test_check_returns_true_when_within_limit(): void {
		$this->assertTrue( $this->limiter->check( 1 ) );
	}

	public function test_check_returns_false_when_limit_exceeded(): void {
		$this->limiter->exhaust( 1 );
		$this->assertFalse( $this->limiter->check( 1 ) );
	}

	public function test_increment_increases_count(): void {
		$this->limiter->increment( 1 );
		$this->assertSame( 1, $this->limiter->getCount( 1 ) );

		$this->limiter->increment( 1 );
		$this->assertSame( 2, $this->limiter->getCount( 1 ) );
	}

	public function test_get_remaining_returns_remaining_requests(): void {
		$this->assertSame( 5, $this->limiter->getRemaining( 1 ) );

		$this->limiter->increment( 1 );
		$this->assertSame( 4, $this->limiter->getRemaining( 1 ) );
	}

	public function test_exhaust_sets_count_to_limit(): void {
		$this->limiter->exhaust( 1 );
		$this->assertSame( 0, $this->limiter->getRemaining( 1 ) );
		$this->assertFalse( $this->limiter->check( 1 ) );
	}

	public function test_reset_clears_user_limit(): void {
		$this->limiter->exhaust( 1 );
		$this->assertFalse( $this->limiter->check( 1 ) );

		$this->limiter->reset( 1 );
		$this->assertTrue( $this->limiter->check( 1 ) );
	}

	public function test_reset_all_clears_all_limits(): void {
		$this->limiter->exhaust( 1 );
		$this->limiter->exhaust( 2 );

		$this->limiter->resetAll();

		$this->assertTrue( $this->limiter->check( 1 ) );
		$this->assertTrue( $this->limiter->check( 2 ) );
	}

	public function test_disable_bypasses_rate_limiting(): void {
		$this->limiter->exhaust( 1 );
		$this->assertFalse( $this->limiter->check( 1 ) );

		$this->limiter->disable();
		$this->assertTrue( $this->limiter->check( 1 ) );
	}

	public function test_enable_restores_rate_limiting(): void {
		$this->limiter->exhaust( 1 );
		$this->limiter->disable();
		$this->assertTrue( $this->limiter->check( 1 ) );

		$this->limiter->enable();
		$this->assertFalse( $this->limiter->check( 1 ) );
	}

	public function test_set_current_time_controls_time(): void {
		$this->limiter->setCurrentTime( 1000 );
		$this->limiter->increment( 1 );

		$this->limiter->setCurrentTime( 1030 );
		$retryAfter = $this->limiter->getRetryAfter( 1 );
		$this->assertSame( 30, $retryAfter );
	}

	public function test_advance_time_moves_clock_forward(): void {
		$this->limiter->setCurrentTime( 1000 );
		$this->limiter->increment( 1 );

		$this->limiter->advanceTime( 30 );
		$retryAfter = $this->limiter->getRetryAfter( 1 );
		$this->assertSame( 30, $retryAfter );
	}

	public function test_window_resets_after_time_passes(): void {
		$this->limiter->setCurrentTime( 1000 );
		$this->limiter->exhaust( 1 );
		$this->assertFalse( $this->limiter->check( 1 ) );

		// Advance past window.
		$this->limiter->advanceTime( 61 );

		$this->assertTrue( $this->limiter->check( 1 ) );
	}

	public function test_set_count_sets_specific_count(): void {
		$this->limiter->setCount( 1, 3 );
		$this->assertSame( 3, $this->limiter->getCount( 1 ) );
		$this->assertSame( 2, $this->limiter->getRemaining( 1 ) );
	}

	public function test_different_users_have_separate_limits(): void {
		$this->limiter->exhaust( 1 );
		$this->assertFalse( $this->limiter->check( 1 ) );
		$this->assertTrue( $this->limiter->check( 2 ) );
	}
}
