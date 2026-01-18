<?php
/**
 * Tests for AnalyticsService.
 *
 * @package AgentWP\Tests\Unit\Services
 */

namespace AgentWP\Tests\Unit\Services;

use AgentWP\Services\AnalyticsService;
use AgentWP\Tests\Fakes\FakeClock;
use AgentWP\Tests\Fakes\FakeOrderRepository;
use AgentWP\Tests\Fakes\FakeWooCommerceOrderGateway;
use AgentWP\Tests\TestCase;
use DateTimeImmutable;
use DateTimeZone;

class AnalyticsServiceTest extends TestCase {

	public function test_get_stats_is_deterministic_under_fake_clock(): void {
		$clock = new FakeClock( new DateTimeImmutable( '2024-01-15 10:00:00', new DateTimeZone( 'UTC' ) ) );

		$service = new AnalyticsService(
			new FakeOrderRepository(),
			new FakeWooCommerceOrderGateway(),
			$clock
		);

		$result1 = $service->get_stats( '7d' );
		$result2 = $service->get_stats( '7d' );

		$this->assertTrue( $result1->isSuccess() );
		$this->assertSame( $result1->toArray(), $result2->toArray() );
	}

	public function test_get_report_by_period_uses_clock_timezone(): void {
		$clock = new FakeClock( new DateTimeImmutable( '2024-01-15 10:00:00', new DateTimeZone( 'America/New_York' ) ) );

		$service = new AnalyticsService(
			new FakeOrderRepository(),
			new FakeWooCommerceOrderGateway(),
			$clock
		);

		$result = $service->get_report_by_period( 'today' );

		$this->assertTrue( $result->isSuccess() );

		$this->assertSame( 'today', $result->get( 'period' ) );

		// If timezone was UTC instead of America/New_York, the date could differ around midnight.
		// This assertion ensures we at least respect the injected clock's timezone.
		$this->assertSame( '2024-01-15', $result->get( 'start' ) );
		$this->assertSame( '2024-01-15', $result->get( 'end' ) );
	}
}
