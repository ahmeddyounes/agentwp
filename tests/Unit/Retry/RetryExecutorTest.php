<?php
/**
 * RetryExecutor unit tests.
 */

namespace AgentWP\Tests\Unit\Retry;

use AgentWP\Contracts\RetryPolicyInterface;
use AgentWP\DTO\HttpResponse;
use AgentWP\Retry\ExponentialBackoffPolicy;
use AgentWP\Retry\RetryExhaustedException;
use AgentWP\Retry\RetryExecutor;
use AgentWP\Tests\Fakes\FakeSleeper;
use AgentWP\Tests\TestCase;

class RetryExecutorTest extends TestCase {

	private FakeSleeper $sleeper;

	public function setUp(): void {
		parent::setUp();
		$this->sleeper = new FakeSleeper();
	}

	public function tearDown(): void {
		$this->sleeper->reset();
		parent::tearDown();
	}

	public function test_execute_succeeds_on_first_attempt(): void {
		$policy   = new ExponentialBackoffPolicy( maxRetries: 3 );
		$executor = new RetryExecutor( $policy, $this->sleeper );

		$result = $executor->execute( fn() => 'success' );

		$this->assertSame( 'success', $result );
		$this->assertSame( 0, $this->sleeper->getSleepCount() );
	}

	public function test_execute_retries_on_failure_then_succeeds(): void {
		$policy   = new ExponentialBackoffPolicy( maxRetries: 3 );
		$executor = new RetryExecutor( $policy, $this->sleeper );

		$attempts = 0;
		$result   = $executor->execute( function () use ( &$attempts ) {
			++$attempts;
			if ( $attempts < 2 ) {
				return false; // Fail first attempt.
			}
			return 'success';
		} );

		$this->assertSame( 'success', $result );
		$this->assertSame( 2, $attempts );
		$this->assertSame( 1, $this->sleeper->getSleepCount() );
	}

	public function test_execute_respects_max_retries(): void {
		$policy   = new ExponentialBackoffPolicy( maxRetries: 2 );
		$executor = new RetryExecutor( $policy, $this->sleeper );

		$attempts = 0;
		$result   = $executor->execute( function () use ( &$attempts ) {
			++$attempts;
			return false; // Always fail.
		} );

		$this->assertFalse( $result );
		// Initial attempt + 2 retries = 3 total attempts.
		$this->assertSame( 3, $attempts );
		// 2 sleeps (before each retry).
		$this->assertSame( 2, $this->sleeper->getSleepCount() );
	}

	public function test_execute_with_http_response_success(): void {
		$policy   = ExponentialBackoffPolicy::forOpenAI();
		$executor = new RetryExecutor( $policy, $this->sleeper );

		$response = HttpResponse::success( '{"result": "ok"}', 200 );
		$result   = $executor->execute( fn() => $response );

		$this->assertSame( $response, $result );
		$this->assertSame( 0, $this->sleeper->getSleepCount() );
	}

	public function test_execute_retries_on_429_status(): void {
		$policy   = ExponentialBackoffPolicy::forOpenAI();
		$executor = new RetryExecutor( $policy, $this->sleeper );

		$attempts = 0;
		$result   = $executor->executeWithCheck(
			function () use ( &$attempts ) {
				++$attempts;
				if ( $attempts < 2 ) {
					return new HttpResponse(
						success: false,
						statusCode: 429,
						body: '{"error": "rate limited"}',
						headers: array( 'retry-after' => '2' )
					);
				}
				return HttpResponse::success( '{"result": "ok"}', 200 );
			},
			fn( HttpResponse $r ) => $r->success
		);

		$this->assertTrue( $result->success );
		$this->assertSame( 200, $result->statusCode );
		$this->assertSame( 2, $attempts );
		$this->assertSame( 1, $this->sleeper->getSleepCount() );
	}

	public function test_execute_retries_on_500_status(): void {
		$policy   = ExponentialBackoffPolicy::forOpenAI();
		$executor = new RetryExecutor( $policy, $this->sleeper );

		$attempts = 0;
		$result   = $executor->executeWithCheck(
			function () use ( &$attempts ) {
				++$attempts;
				if ( $attempts < 3 ) {
					return new HttpResponse(
						success: false,
						statusCode: 500,
						body: '{"error": "internal server error"}'
					);
				}
				return HttpResponse::success( '{"result": "ok"}', 200 );
			},
			fn( HttpResponse $r ) => $r->success
		);

		$this->assertTrue( $result->success );
		$this->assertSame( 3, $attempts );
		$this->assertSame( 2, $this->sleeper->getSleepCount() );
	}

	public function test_execute_does_not_retry_on_400_client_error(): void {
		$policy   = ExponentialBackoffPolicy::forOpenAI();
		$executor = new RetryExecutor( $policy, $this->sleeper );

		$attempts = 0;
		$result   = $executor->executeWithCheck(
			function () use ( &$attempts ) {
				++$attempts;
				return new HttpResponse(
					success: false,
					statusCode: 400,
					body: '{"error": "bad request"}'
				);
			},
			fn( HttpResponse $r ) => $r->success
		);

		$this->assertFalse( $result->success );
		$this->assertSame( 400, $result->statusCode );
		// Only 1 attempt, no retries for 4xx (except 429).
		$this->assertSame( 1, $attempts );
		$this->assertSame( 0, $this->sleeper->getSleepCount() );
	}

	public function test_execute_respects_retry_after_header(): void {
		$policy   = new ExponentialBackoffPolicy(
			maxRetries: 3,
			baseDelayMs: 1000,
			maxDelayMs: 60000
		);
		$executor = new RetryExecutor( $policy, $this->sleeper );

		$attempts = 0;
		$executor->executeWithCheck(
			function () use ( &$attempts ) {
				++$attempts;
				if ( $attempts < 2 ) {
					return new HttpResponse(
						success: false,
						statusCode: 429,
						body: '{"error": "rate limited"}',
						headers: array( 'retry-after' => '5' )
					);
				}
				return HttpResponse::success( '{"result": "ok"}', 200 );
			},
			fn( HttpResponse $r ) => $r->success
		);

		// Retry-After of 5 seconds = 5000ms.
		$sleepLog = $this->sleeper->getSleepLog();
		$this->assertCount( 1, $sleepLog );
		$this->assertSame( 5000, $sleepLog[0] );
	}

	public function test_on_retry_callback_is_called(): void {
		$policy   = new ExponentialBackoffPolicy( maxRetries: 3 );
		$executor = new RetryExecutor( $policy, $this->sleeper );

		$retryLog = array();
		$executor->onRetry( function ( $attempt, $delayMs, $result ) use ( &$retryLog ) {
			$retryLog[] = array(
				'attempt'  => $attempt,
				'delayMs'  => $delayMs,
				'result'   => $result,
			);
		} );

		$attempts = 0;
		$executor->execute( function () use ( &$attempts ) {
			++$attempts;
			if ( $attempts < 3 ) {
				return false;
			}
			return 'success';
		} );

		$this->assertCount( 2, $retryLog );
		$this->assertSame( 0, $retryLog[0]['attempt'] );
		$this->assertSame( 1, $retryLog[1]['attempt'] );
	}

	public function test_execute_throws_original_exception_when_retries_exhausted(): void {
		$policy   = new ExponentialBackoffPolicy( maxRetries: 2 );
		$executor = new RetryExecutor( $policy, $this->sleeper );

		// When retries are exhausted on a retryable exception, the original
		// exception is thrown (shouldRetry returns false at max retries).
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Connection timed out' );

		$executor->execute( function () {
			throw new \RuntimeException( 'Connection timed out' );
		} );
	}

	public function test_execute_rethrows_non_retryable_exception(): void {
		$policy   = new ExponentialBackoffPolicy( maxRetries: 3 );
		$executor = new RetryExecutor( $policy, $this->sleeper );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Invalid argument' );

		$executor->execute( function () {
			throw new \InvalidArgumentException( 'Invalid argument' );
		} );
	}

	public function test_exponential_backoff_delay_increases(): void {
		$policy   = new ExponentialBackoffPolicy(
			maxRetries: 5,
			baseDelayMs: 1000,
			maxDelayMs: 30000,
			jitterFactor: 0.0 // No jitter for predictable testing.
		);
		$executor = new RetryExecutor( $policy, $this->sleeper );

		$attempts = 0;
		$executor->execute( function () use ( &$attempts ) {
			++$attempts;
			if ( $attempts <= 4 ) {
				return false;
			}
			return 'success';
		} );

		$sleepLog = $this->sleeper->getSleepLog();
		$this->assertCount( 4, $sleepLog );

		// Exponential backoff: 1000, 2000, 4000, 8000.
		$this->assertSame( 1000, $sleepLog[0] );
		$this->assertSame( 2000, $sleepLog[1] );
		$this->assertSame( 4000, $sleepLog[2] );
		$this->assertSame( 8000, $sleepLog[3] );
	}

	public function test_delay_caps_at_max_delay(): void {
		$policy   = new ExponentialBackoffPolicy(
			maxRetries: 10,
			baseDelayMs: 1000,
			maxDelayMs: 5000,
			jitterFactor: 0.0
		);
		$executor = new RetryExecutor( $policy, $this->sleeper );

		$attempts = 0;
		$executor->execute( function () use ( &$attempts ) {
			++$attempts;
			if ( $attempts <= 5 ) {
				return false;
			}
			return 'success';
		} );

		$sleepLog = $this->sleeper->getSleepLog();
		// All delays should be capped at 5000ms.
		foreach ( $sleepLog as $delay ) {
			$this->assertLessThanOrEqual( 5000, $delay );
		}
	}

	public function test_for_open_ai_factory_creates_correct_policy(): void {
		$policy = ExponentialBackoffPolicy::forOpenAI();

		$this->assertSame( 3, $policy->getMaxRetries() );

		// Test that 429, 500, 502, 503, 504 are retryable.
		$retryableCodes = array( 429, 500, 502, 503, 504, 520, 521, 522, 524 );
		foreach ( $retryableCodes as $code ) {
			$response = new HttpResponse( success: false, statusCode: $code, body: '' );
			$this->assertTrue(
				$policy->shouldRetry( 0, $response ),
				"Status code $code should be retryable"
			);
		}

		// Test that 400, 401, 403, 404 are not retryable.
		$nonRetryableCodes = array( 400, 401, 403, 404 );
		foreach ( $nonRetryableCodes as $code ) {
			$response = new HttpResponse( success: false, statusCode: $code, body: '' );
			$this->assertFalse(
				$policy->shouldRetry( 0, $response ),
				"Status code $code should not be retryable"
			);
		}
	}

	public function test_for_rate_limiting_factory_creates_correct_policy(): void {
		$policy = ExponentialBackoffPolicy::forRateLimiting();

		$this->assertSame( 5, $policy->getMaxRetries() );

		// Only 429 should be retryable.
		$response429 = new HttpResponse( success: false, statusCode: 429, body: '' );
		$this->assertTrue( $policy->shouldRetry( 0, $response429 ) );

		$response500 = new HttpResponse( success: false, statusCode: 500, body: '' );
		// 500 is still retryable via isRetryable() check.
		$this->assertTrue( $policy->shouldRetry( 0, $response500 ) );
	}

	public function test_network_error_is_retryable(): void {
		$policy   = ExponentialBackoffPolicy::forOpenAI();
		$executor = new RetryExecutor( $policy, $this->sleeper );

		$attempts = 0;
		$result   = $executor->executeWithCheck(
			function () use ( &$attempts ) {
				++$attempts;
				if ( $attempts < 2 ) {
					return new HttpResponse(
						success: false,
						statusCode: 0,
						body: '',
						error: 'Connection refused'
					);
				}
				return HttpResponse::success( '{"result": "ok"}', 200 );
			},
			fn( HttpResponse $r ) => $r->success
		);

		$this->assertTrue( $result->success );
		$this->assertSame( 2, $attempts );
	}

	public function test_retries_are_attempted_before_exception_is_rethrown(): void {
		$policy   = new ExponentialBackoffPolicy( maxRetries: 2 );
		$executor = new RetryExecutor( $policy, $this->sleeper );

		$attempts = 0;
		try {
			$executor->execute( function () use ( &$attempts ) {
				++$attempts;
				throw new \RuntimeException( 'timeout' );
			} );
			$this->fail( 'Expected RuntimeException to be thrown' );
		} catch ( \RuntimeException $e ) {
			// Initial attempt + 2 retries = 3 total attempts.
			$this->assertSame( 3, $attempts );
			$this->assertSame( 'timeout', $e->getMessage() );
			// 2 sleeps occurred (before each retry).
			$this->assertSame( 2, $this->sleeper->getSleepCount() );
		}
	}
}
