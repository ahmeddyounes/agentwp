<?php
/**
 * Usage purge scheduling tests.
 *
 * @package AgentWP\Tests\Unit\Billing
 */

namespace AgentWP\Tests\Unit\Billing;

use AgentWP\Billing\UsageTracker;
use AgentWP\Tests\TestCase;
use WP_Mock;

class UsagePurgeScheduleTest extends TestCase {

	public function test_schedule_purge_skips_when_already_scheduled_and_recent(): void {
		$future = time() + 3600;

		WP_Mock::userFunction(
			'get_option',
			array(
				'args'   => array( UsageTracker::PURGE_LAST_RUN_OPTION, 0 ),
				'return' => time(),
			)
		);

		WP_Mock::userFunction(
			'wp_next_scheduled',
			array(
				'args'   => array( UsageTracker::PURGE_HOOK ),
				'return' => $future,
			)
		);

		WP_Mock::userFunction(
			'wp_unschedule_event',
			array(
				'times' => 0,
			)
		);

		WP_Mock::userFunction(
			'wp_schedule_event',
			array(
				'times' => 0,
			)
		);

		UsageTracker::schedule_purge();
		$this->assertTrue( true );
	}

	public function test_schedule_purge_schedules_when_missing(): void {
		WP_Mock::userFunction(
			'get_option',
			array(
				'args'   => array( UsageTracker::PURGE_LAST_RUN_OPTION, 0 ),
				'return' => 0,
			)
		);

		WP_Mock::userFunction(
			'wp_next_scheduled',
			array(
				'args'   => array( UsageTracker::PURGE_HOOK ),
				'return' => false,
			)
		);

		WP_Mock::userFunction(
			'wp_schedule_event',
			array(
				'args'   => array( WP_Mock\Functions::type( 'int' ), 'daily', UsageTracker::PURGE_HOOK ),
				'return' => true,
				'times'  => 1,
			)
		);

		UsageTracker::schedule_purge();
		$this->assertTrue( true );
	}

	public function test_schedule_purge_reschedules_when_stale_next_scheduled(): void {
		$stale = time() - ( UsageTracker::PURGE_STUCK_THRESHOLD + 5 );

		WP_Mock::userFunction(
			'get_option',
			array(
				'args'   => array( UsageTracker::PURGE_LAST_RUN_OPTION, 0 ),
				'return' => 0,
			)
		);

		WP_Mock::userFunction(
			'wp_next_scheduled',
			array(
				'args'            => array( UsageTracker::PURGE_HOOK ),
				'return_in_order' => array( $stale, $stale, false ),
			)
		);

		WP_Mock::userFunction(
			'wp_unschedule_event',
			array(
				'args'  => array( $stale, UsageTracker::PURGE_HOOK ),
				'times' => 1,
			)
		);

		WP_Mock::userFunction(
			'wp_schedule_event',
			array(
				'args'   => array( WP_Mock\Functions::type( 'int' ), 'daily', UsageTracker::PURGE_HOOK ),
				'return' => true,
				'times'  => 1,
			)
		);

		UsageTracker::schedule_purge();
		$this->assertTrue( true );
	}

	public function test_schedule_purge_reschedules_when_last_run_stale(): void {
		$future = time() + 3600;
		$stale  = time() - ( UsageTracker::PURGE_STUCK_THRESHOLD + 5 );

		WP_Mock::userFunction(
			'get_option',
			array(
				'args'   => array( UsageTracker::PURGE_LAST_RUN_OPTION, 0 ),
				'return' => $stale,
			)
		);

		WP_Mock::userFunction(
			'wp_next_scheduled',
			array(
				'args'            => array( UsageTracker::PURGE_HOOK ),
				'return_in_order' => array( $future, $future, false ),
			)
		);

		WP_Mock::userFunction(
			'wp_unschedule_event',
			array(
				'args'  => array( $future, UsageTracker::PURGE_HOOK ),
				'times' => 1,
			)
		);

		WP_Mock::userFunction(
			'wp_schedule_event',
			array(
				'args'   => array( WP_Mock\Functions::type( 'int' ), 'daily', UsageTracker::PURGE_HOOK ),
				'return' => true,
				'times'  => 1,
			)
		);

		UsageTracker::schedule_purge();
		$this->assertTrue( true );
	}
}
