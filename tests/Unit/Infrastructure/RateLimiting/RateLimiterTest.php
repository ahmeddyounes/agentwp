<?php
/**
 * RateLimiter unit tests.
 *
 * @package AgentWP\Tests\Unit\Infrastructure\RateLimiting
 */

namespace AgentWP\Tests\Unit\Infrastructure\RateLimiting;

use AgentWP\Infrastructure\RateLimiting\RateLimiter;
use AgentWP\Tests\Fakes\FakeClock;
use AgentWP\Tests\Fakes\FakeTransientCache;
use AgentWP\Tests\TestCase;
use DateTimeImmutable;

class RateLimiterTest extends TestCase {

	private FakeTransientCache $cache;
	private FakeClock $clock;

	public function setUp(): void {
		parent::setUp();
		$this->cache = new FakeTransientCache();
		$this->clock = new FakeClock( new DateTimeImmutable( '@1000' ) );
	}

	public function tearDown(): void {
		$this->cache->reset();
		parent::tearDown();
	}

	public function test_check_returns_true_when_within_limit(): void {
		$limiter = new RateLimiter( $this->cache, $this->clock, 5, 60 );

		$this->assertTrue( $limiter->check( 1 ) );
	}

	public function test_check_returns_false_when_limit_exceeded(): void {
		$limiter = new RateLimiter( $this->cache, $this->clock, 2, 60 );

		$limiter->increment( 1 );
		$limiter->increment( 1 );

		$this->assertFalse( $limiter->check( 1 ) );
	}

	public function test_increment_increases_count(): void {
		$limiter = new RateLimiter( $this->cache, $this->clock, 5, 60 );

		$limiter->increment( 1 );
		$this->assertSame( 4, $limiter->getRemaining( 1 ) );

		$limiter->increment( 1 );
		$this->assertSame( 3, $limiter->getRemaining( 1 ) );
	}

	public function test_get_retry_after_returns_time_until_window_reset(): void {
		$limiter = new RateLimiter( $this->cache, $this->clock, 2, 60 );

		$limiter->increment( 1 );

		// Advance time by 30 seconds.
		$this->clock->advanceSeconds( 30 );

		$retryAfter = $limiter->getRetryAfter( 1 );
		$this->assertSame( 30, $retryAfter );
	}

	public function test_get_remaining_returns_remaining_requests(): void {
		$limiter = new RateLimiter( $this->cache, $this->clock, 5, 60 );

		$this->assertSame( 5, $limiter->getRemaining( 1 ) );

		$limiter->increment( 1 );
		$this->assertSame( 4, $limiter->getRemaining( 1 ) );
	}

	public function test_window_resets_after_expiry(): void {
		$limiter = new RateLimiter( $this->cache, $this->clock, 2, 60 );

		$limiter->increment( 1 );
		$limiter->increment( 1 );
		$this->assertFalse( $limiter->check( 1 ) );

		// Advance time past window.
		$this->clock->advanceSeconds( 61 );

		$this->assertTrue( $limiter->check( 1 ) );
		$this->assertSame( 2, $limiter->getRemaining( 1 ) );
	}

	public function test_check_and_increment_atomic_operation(): void {
		$limiter = new RateLimiter( $this->cache, $this->clock, 2, 60 );

		$this->assertTrue( $limiter->checkAndIncrement( 1 ) );
		$this->assertTrue( $limiter->checkAndIncrement( 1 ) );
		$this->assertFalse( $limiter->checkAndIncrement( 1 ) );
	}

	public function test_reset_clears_user_limit(): void {
		$limiter = new RateLimiter( $this->cache, $this->clock, 2, 60 );

		$limiter->increment( 1 );
		$limiter->increment( 1 );
		$this->assertFalse( $limiter->check( 1 ) );

		$limiter->reset( 1 );
		$this->assertTrue( $limiter->check( 1 ) );
	}

	public function test_get_limit_returns_configured_limit(): void {
		$limiter = new RateLimiter( $this->cache, $this->clock, 15, 60 );

		$this->assertSame( 15, $limiter->getLimit() );
	}

	public function test_get_window_returns_configured_window(): void {
		$limiter = new RateLimiter( $this->cache, $this->clock, 5, 120 );

		$this->assertSame( 120, $limiter->getWindow() );
	}

	public function test_different_users_have_separate_limits(): void {
		$limiter = new RateLimiter( $this->cache, $this->clock, 2, 60 );

		$limiter->increment( 1 );
		$limiter->increment( 1 );
		$this->assertFalse( $limiter->check( 1 ) );

		// User 2 should have fresh limit.
		$this->assertTrue( $limiter->check( 2 ) );
		$this->assertSame( 2, $limiter->getRemaining( 2 ) );
	}

	public function test_get_retry_after_returns_at_least_one_second(): void {
		$limiter = new RateLimiter( $this->cache, $this->clock, 2, 60 );

		$limiter->increment( 1 );

		// Advance time to just before window ends.
		$this->clock->advanceSeconds( 59 );

		$retryAfter = $limiter->getRetryAfter( 1 );
		// max(1, 60 - 59) = max(1, 1) = 1
		$this->assertSame( 1, $retryAfter );
	}

	public function test_check_and_increment_fails_open_on_lock_contention(): void {
		$limiter = new RateLimiter( $this->cache, $this->clock, 2, 60 );

		// Simulate lock contention where add() always fails.
		$this->cache->setSimulateLockContention( true );

		// Should fail open (return true) when lock cannot be acquired.
		$this->assertTrue( $limiter->checkAndIncrement( 1 ) );
	}

	public function test_check_and_increment_fails_open_on_storage_failure_during_lock(): void {
		$limiter = new RateLimiter( $this->cache, $this->clock, 2, 60 );

		// Simulate storage failure.
		$this->cache->setSimulateFailure( true );

		// Should fail open (return true) when storage throws exception.
		$this->assertTrue( $limiter->checkAndIncrement( 1 ) );
	}

	public function test_check_and_increment_fails_open_on_storage_failure_during_bucket_read(): void {
		$limiter = new RateLimiter( $this->cache, $this->clock, 2, 60 );

		// First, do a successful operation to ensure lock works.
		$this->assertTrue( $limiter->checkAndIncrement( 1 ) );

		// Now simulate failure after lock is acquired using mock.
		$failingCache = $this->createMock( \AgentWP\Contracts\TransientCacheInterface::class );
		$failingCache->method( 'add' )->willReturn( true ); // Lock succeeds.
		$failingCache->method( 'get' )->willThrowException( new \RuntimeException( 'Simulated storage failure' ) );
		$failingCache->method( 'delete' )->willReturn( true ); // Lock release succeeds.

		$clockForFailingCache = new FakeClock( new DateTimeImmutable( '@1000' ) );
		$limiterWithFailingCache = new RateLimiter( $failingCache, $clockForFailingCache, 2, 60 );

		// Should fail open when storage fails during bucket read.
		$this->assertTrue( $limiterWithFailingCache->checkAndIncrement( 1 ) );
	}

	public function test_lock_released_after_rate_limit_check(): void {
		$limiter = new RateLimiter( $this->cache, $this->clock, 2, 60 );

		// Perform check and increment.
		$limiter->checkAndIncrement( 1 );

		// Lock should be released, so we can acquire it again.
		$this->assertTrue( $this->cache->add( 'rate_1_lock', 1, 5 ) );
	}

	public function test_lock_released_even_when_at_limit(): void {
		$limiter = new RateLimiter( $this->cache, $this->clock, 2, 60 );

		// Exhaust the limit.
		$limiter->checkAndIncrement( 1 );
		$limiter->checkAndIncrement( 1 );
		$this->assertFalse( $limiter->checkAndIncrement( 1 ) );

		// Lock should still be released.
		$this->assertTrue( $this->cache->add( 'rate_1_lock', 1, 5 ) );
	}

	public function test_check_and_increment_handles_window_expiration(): void {
		$limiter = new RateLimiter( $this->cache, $this->clock, 2, 60 );

		// Exhaust limit.
		$this->assertTrue( $limiter->checkAndIncrement( 1 ) );
		$this->assertTrue( $limiter->checkAndIncrement( 1 ) );
		$this->assertFalse( $limiter->checkAndIncrement( 1 ) );

		// Advance time past window.
		$this->clock->advanceSeconds( 61 );

		// Should work again after window reset.
		$this->assertTrue( $limiter->checkAndIncrement( 1 ) );
	}
}
