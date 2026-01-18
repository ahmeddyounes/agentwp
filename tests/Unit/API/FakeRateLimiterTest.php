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

	// Atomic behavior tests.

	public function test_check_and_increment_returns_true_when_within_limit(): void {
		$this->assertTrue( $this->limiter->checkAndIncrement( 1 ) );
		$this->assertSame( 1, $this->limiter->getCount( 1 ) );
	}

	public function test_check_and_increment_increments_counter(): void {
		$this->limiter->checkAndIncrement( 1 );
		$this->assertSame( 1, $this->limiter->getCount( 1 ) );

		$this->limiter->checkAndIncrement( 1 );
		$this->assertSame( 2, $this->limiter->getCount( 1 ) );
	}

	public function test_check_and_increment_returns_false_when_limit_exceeded(): void {
		// With limit of 5, first 5 calls should succeed.
		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertTrue( $this->limiter->checkAndIncrement( 1 ) );
		}

		// 6th call should fail.
		$this->assertFalse( $this->limiter->checkAndIncrement( 1 ) );
	}

	public function test_check_and_increment_does_not_increment_when_at_limit(): void {
		$this->limiter->exhaust( 1 );
		$this->assertSame( 5, $this->limiter->getCount( 1 ) );

		// Should return false and not increment further.
		$this->assertFalse( $this->limiter->checkAndIncrement( 1 ) );
		$this->assertSame( 5, $this->limiter->getCount( 1 ) );
	}

	public function test_check_and_increment_bypassed_when_disabled(): void {
		$this->limiter->disable();
		$this->limiter->exhaust( 1 );

		// Should return true even when exhausted because rate limiting is disabled.
		$this->assertTrue( $this->limiter->checkAndIncrement( 1 ) );
	}

	public function test_check_and_increment_resets_after_window_expires(): void {
		$this->limiter->setCurrentTime( 1000 );
		$this->limiter->exhaust( 1 );
		$this->assertFalse( $this->limiter->checkAndIncrement( 1 ) );

		// Advance past window (60 seconds).
		$this->limiter->advanceTime( 61 );

		// Should work again.
		$this->assertTrue( $this->limiter->checkAndIncrement( 1 ) );
		$this->assertSame( 1, $this->limiter->getCount( 1 ) );
	}

	// Retry-after propagation tests.

	public function test_retry_after_returns_remaining_window_time(): void {
		$this->limiter->setCurrentTime( 1000 );
		$this->limiter->increment( 1 );

		// Advance 30 seconds.
		$this->limiter->advanceTime( 30 );

		// Should have 30 seconds remaining (60 - 30).
		$this->assertSame( 30, $this->limiter->getRetryAfter( 1 ) );
	}

	public function test_retry_after_returns_at_least_one_second(): void {
		$this->limiter->setCurrentTime( 1000 );
		$this->limiter->increment( 1 );

		// Advance 59 seconds.
		$this->limiter->advanceTime( 59 );

		// max(1, 60 - 59) = 1.
		$this->assertSame( 1, $this->limiter->getRetryAfter( 1 ) );
	}

	public function test_retry_after_consistent_with_check_and_increment_failure(): void {
		$this->limiter->setCurrentTime( 1000 );

		// Exhaust the limit.
		for ( $i = 0; $i < 5; $i++ ) {
			$this->limiter->checkAndIncrement( 1 );
		}

		// Advance 20 seconds.
		$this->limiter->advanceTime( 20 );

		// Attempt that should fail.
		$this->assertFalse( $this->limiter->checkAndIncrement( 1 ) );

		// Retry-after should be consistent (40 seconds remaining).
		$retryAfter = $this->limiter->getRetryAfter( 1 );
		$this->assertSame( 40, $retryAfter );
	}

	public function test_429_scenario_with_retry_after(): void {
		$this->limiter->setCurrentTime( 1000 );

		// Simulate exhausting limit.
		$this->limiter->exhaust( 1 );

		// Advance 15 seconds.
		$this->limiter->advanceTime( 15 );

		// Check should fail (429 scenario).
		$this->assertFalse( $this->limiter->check( 1 ) );

		// Retry-after should return proper value.
		$retryAfter = $this->limiter->getRetryAfter( 1 );
		$this->assertSame( 45, $retryAfter );
		$this->assertGreaterThan( 0, $retryAfter );
	}

	public function test_atomic_check_and_increment_429_scenario(): void {
		$this->limiter->setCurrentTime( 1000 );

		// Exhaust limit using atomic method.
		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertTrue( $this->limiter->checkAndIncrement( 1 ) );
		}

		// Advance 25 seconds.
		$this->limiter->advanceTime( 25 );

		// Next attempt should fail (429 scenario).
		$this->assertFalse( $this->limiter->checkAndIncrement( 1 ) );

		// Retry-after should be consistent.
		$retryAfter = $this->limiter->getRetryAfter( 1 );
		$this->assertSame( 35, $retryAfter );
	}

	public function test_fallback_path_check_then_increment(): void {
		// This tests the non-atomic fallback path: check() then increment().
		$this->assertTrue( $this->limiter->check( 1 ) );
		$this->limiter->increment( 1 );
		$this->assertSame( 1, $this->limiter->getCount( 1 ) );

		// Continue until limit.
		for ( $i = 1; $i < 5; $i++ ) {
			$this->assertTrue( $this->limiter->check( 1 ) );
			$this->limiter->increment( 1 );
		}

		// Now at limit.
		$this->assertFalse( $this->limiter->check( 1 ) );
	}

	public function test_fallback_path_retry_after_on_limit_exceeded(): void {
		$this->limiter->setCurrentTime( 1000 );

		// Use fallback path.
		for ( $i = 0; $i < 5; $i++ ) {
			if ( $this->limiter->check( 1 ) ) {
				$this->limiter->increment( 1 );
			}
		}

		// Advance 10 seconds.
		$this->limiter->advanceTime( 10 );

		// Check fails.
		$this->assertFalse( $this->limiter->check( 1 ) );

		// Retry-after should be 50 seconds.
		$this->assertSame( 50, $this->limiter->getRetryAfter( 1 ) );
	}

	public function test_atomic_and_fallback_produce_consistent_retry_after(): void {
		// Create two limiters to compare atomic vs fallback paths.
		$atomicLimiter   = new FakeRateLimiter( 5, 60 );
		$fallbackLimiter = new FakeRateLimiter( 5, 60 );

		$atomicLimiter->setCurrentTime( 1000 );
		$fallbackLimiter->setCurrentTime( 1000 );

		// Exhaust via atomic path.
		for ( $i = 0; $i < 5; $i++ ) {
			$atomicLimiter->checkAndIncrement( 1 );
		}

		// Exhaust via fallback path.
		for ( $i = 0; $i < 5; $i++ ) {
			if ( $fallbackLimiter->check( 1 ) ) {
				$fallbackLimiter->increment( 1 );
			}
		}

		// Advance both by same amount.
		$atomicLimiter->advanceTime( 20 );
		$fallbackLimiter->advanceTime( 20 );

		// Both should report same retry-after.
		$this->assertSame(
			$atomicLimiter->getRetryAfter( 1 ),
			$fallbackLimiter->getRetryAfter( 1 )
		);
		$this->assertSame( 40, $atomicLimiter->getRetryAfter( 1 ) );
	}
}
