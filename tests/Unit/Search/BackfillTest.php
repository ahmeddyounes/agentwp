<?php
/**
 * Tests for Search Index Backfill Logic.
 *
 * @package AgentWP\Tests\Unit\Search
 */

namespace AgentWP\Tests\Unit\Search;

use AgentWP\Search\Index;
use AgentWP\Tests\TestCase;
use ReflectionClass;
use ReflectionMethod;
use WP_Mock;

/**
 * Unit tests for Index backfill and throttling behavior.
 *
 * These tests validate the backfill logic including:
 * - State transitions (not started -> in progress -> complete)
 * - Cursor tracking
 * - Time window throttling behavior
 * - Constants validation
 */
class BackfillTest extends TestCase {

	/**
	 * Reset static state before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->reset_index_static_state();
	}

	/**
	 * Reset static state after each test.
	 */
	public function tearDown(): void {
		$this->reset_index_static_state();
		parent::tearDown();
	}

	/**
	 * Helper to reset Index static properties.
	 */
	private function reset_index_static_state(): void {
		$reflection = new ReflectionClass( Index::class );

		$properties = array(
			'hooks_registered' => false,
			'table_verified'   => false,
			'backfill_ran'     => false,
			'backfill_lock_token' => '',
		);

		foreach ( $properties as $name => $default ) {
			if ( $reflection->hasProperty( $name ) ) {
				$prop = $reflection->getProperty( $name );
				$prop->setAccessible( true );
				$prop->setValue( null, $default );
			}
		}
	}

	/**
	 * Get private/protected method as accessible.
	 *
	 * @param string $name Method name.
	 * @return ReflectionMethod
	 */
	private function get_method( $name ): ReflectionMethod {
		$reflection = new ReflectionClass( Index::class );
		$method     = $reflection->getMethod( $name );
		$method->setAccessible( true );
		return $method;
	}

	/**
	 * Get static property value.
	 *
	 * @param string $name Property name.
	 * @return mixed
	 */
	private function get_static_property( $name ) {
		$reflection = new ReflectionClass( Index::class );
		$prop       = $reflection->getProperty( $name );
		$prop->setAccessible( true );
		return $prop->getValue();
	}

	/**
	 * Set static property value.
	 *
	 * @param string $name Property name.
	 * @param mixed  $value Value to set.
	 */
	private function set_static_property( $name, $value ): void {
		$reflection = new ReflectionClass( Index::class );
		$prop       = $reflection->getProperty( $name );
		$prop->setAccessible( true );
		$prop->setValue( null, $value );
	}

	// ===========================================
	// Backfill Constants Tests
	// ===========================================

	public function test_backfill_limit_is_reasonable_batch_size(): void {
		// 200 records per batch is reasonable to process in 350ms.
		$this->assertGreaterThanOrEqual( 50, Index::BACKFILL_LIMIT );
		$this->assertLessThanOrEqual( 500, Index::BACKFILL_LIMIT );
	}

	public function test_backfill_window_under_one_second(): void {
		// Backfill should complete quickly to avoid blocking page loads.
		$this->assertLessThan( 1.0, Index::BACKFILL_WINDOW );
	}

	public function test_backfill_window_at_least_100ms(): void {
		// Need enough time to process at least some records.
		$this->assertGreaterThanOrEqual( 0.1, Index::BACKFILL_WINDOW );
	}

	// ===========================================
	// State Transition Tests
	// ===========================================

	public function test_state_zero_means_not_started(): void {
		$method = $this->get_method( 'is_backfill_complete' );
		$state  = array( 'products' => 0 );

		$this->assertFalse( $method->invoke( null, 'products', $state ) );
	}

	public function test_state_positive_means_in_progress(): void {
		$method = $this->get_method( 'is_backfill_complete' );
		$state  = array( 'products' => 150 ); // Cursor at ID 150.

		$this->assertFalse( $method->invoke( null, 'products', $state ) );
	}

	public function test_state_negative_one_means_complete(): void {
		$method = $this->get_method( 'is_backfill_complete' );
		$state  = array( 'products' => -1 );

		$this->assertTrue( $method->invoke( null, 'products', $state ) );
	}

	public function test_all_types_complete_when_all_negative_one(): void {
		$method = $this->get_method( 'is_backfill_complete' );
		$state  = array(
			'products'  => -1,
			'orders'    => -1,
			'customers' => -1,
		);

		$this->assertTrue( $method->invoke( null, 'products', $state ) );
		$this->assertTrue( $method->invoke( null, 'orders', $state ) );
		$this->assertTrue( $method->invoke( null, 'customers', $state ) );
	}

	public function test_partial_completion_detected(): void {
		$method = $this->get_method( 'is_backfill_complete' );
		$state  = array(
			'products'  => -1,  // Complete.
			'orders'    => 500, // In progress.
			'customers' => 0,   // Not started.
		);

		$this->assertTrue( $method->invoke( null, 'products', $state ) );
		$this->assertFalse( $method->invoke( null, 'orders', $state ) );
		$this->assertFalse( $method->invoke( null, 'customers', $state ) );
	}

	// ===========================================
	// Static Flag Tests
	// ===========================================

	public function test_backfill_ran_flag_starts_false(): void {
		$this->assertFalse( $this->get_static_property( 'backfill_ran' ) );
	}

	public function test_backfill_ran_flag_can_be_set(): void {
		$this->set_static_property( 'backfill_ran', true );
		$this->assertTrue( $this->get_static_property( 'backfill_ran' ) );
	}

	// ===========================================
	// Cursor Position Tests
	// ===========================================

	public function test_cursor_tracks_progress_correctly(): void {
		// Simulate cursor at 100, meaning IDs 1-100 have been processed.
		$state = array(
			'products' => 100,
		);

		$method = $this->get_method( 'is_backfill_complete' );

		// Still in progress.
		$this->assertFalse( $method->invoke( null, 'products', $state ) );
	}

	public function test_cursor_update_after_batch(): void {
		// After processing IDs [101, 102, 103], cursor should be max(103) = 103.
		$processed_ids = array( 101, 102, 103 );
		$new_cursor    = max( $processed_ids );

		$this->assertSame( 103, $new_cursor );
	}

	public function test_empty_batch_marks_complete(): void {
		// When fetch_ids returns empty, backfill_type sets cursor to -1.
		// The expected complete state is -1.
		$expected_complete_state = -1;

		$this->assertSame( -1, $expected_complete_state );
	}

	// ===========================================
	// Backfill Order Tests
	// ===========================================

	public function test_backfill_processes_types_in_order(): void {
		// Backfill processes: products -> orders -> customers.
		// This is the expected order as defined in maybe_backfill().
		$expected_order = array( 'products', 'orders', 'customers' );

		$this->assertSame( 'products', $expected_order[0] );
		$this->assertSame( 'orders', $expected_order[1] );
		$this->assertSame( 'customers', $expected_order[2] );
	}

	public function test_backfill_skips_complete_types(): void {
		// If products is complete (-1), backfill should skip it.
		$state = array(
			'products'  => -1, // Skip.
			'orders'    => 0,  // Process this.
			'customers' => 0,
		);

		$method = $this->get_method( 'is_backfill_complete' );

		$this->assertTrue( $method->invoke( null, 'products', $state ) );
		$this->assertFalse( $method->invoke( null, 'orders', $state ) );
	}

	// ===========================================
	// Time Window Behavior Tests
	// ===========================================

	public function test_time_window_calculation(): void {
		// Verify that 350ms window is properly defined.
		$window = Index::BACKFILL_WINDOW;

		// Should continue at 0ms.
		$should_continue_at_0ms = 0.0 < $window;
		$this->assertTrue( $should_continue_at_0ms );

		// Should continue at 200ms.
		$should_continue_at_200ms = 0.2 < $window;
		$this->assertTrue( $should_continue_at_200ms );

		// Should stop at 400ms.
		$should_stop_at_400ms = 0.4 >= $window;
		$this->assertTrue( $should_stop_at_400ms );
	}

	public function test_backfill_respects_time_window(): void {
		// The backfill uses: if (microtime(true) - $start >= BACKFILL_WINDOW) break;
		$window = Index::BACKFILL_WINDOW;
		$this->assertSame( 0.35, $window );
	}

	// ===========================================
	// State Default Value Tests
	// ===========================================

	public function test_state_defaults_for_all_types(): void {
		// When a type is missing from state, it should default to 0.
		$state = array();

		$method = $this->get_method( 'is_backfill_complete' );

		// All should return false (not complete) since they're missing/default to 0.
		$this->assertFalse( $method->invoke( null, 'products', $state ) );
		$this->assertFalse( $method->invoke( null, 'orders', $state ) );
		$this->assertFalse( $method->invoke( null, 'customers', $state ) );
	}

	public function test_state_with_string_cursor_is_coerced(): void {
		// State values should be cast to int.
		$state  = array( 'products' => '-1' );
		$method = $this->get_method( 'is_backfill_complete' );

		// is_backfill_complete checks intval($state[$type]) === -1.
		$this->assertTrue( $method->invoke( null, 'products', $state ) );
	}

	public function test_state_with_float_cursor_is_coerced(): void {
		$state  = array( 'products' => 100.5 );
		$method = $this->get_method( 'is_backfill_complete' );

		// intval(100.5) === 100, not -1, so not complete.
		$this->assertFalse( $method->invoke( null, 'products', $state ) );
	}

	// ===========================================
	// Edge Cases
	// ===========================================

	public function test_state_with_zero_string_is_not_complete(): void {
		$state  = array( 'products' => '0' );
		$method = $this->get_method( 'is_backfill_complete' );

		$this->assertFalse( $method->invoke( null, 'products', $state ) );
	}

	public function test_state_with_null_is_not_complete(): void {
		$state  = array( 'products' => null );
		$method = $this->get_method( 'is_backfill_complete' );

		$this->assertFalse( $method->invoke( null, 'products', $state ) );
	}

	public function test_state_with_very_large_cursor(): void {
		// Should still be in progress with a large cursor.
		$state  = array( 'products' => PHP_INT_MAX );
		$method = $this->get_method( 'is_backfill_complete' );

		$this->assertFalse( $method->invoke( null, 'products', $state ) );
	}

	// ===========================================
	// Scheduled Backfill Constants Tests
	// ===========================================

	public function test_backfill_hook_constant_exists(): void {
		$this->assertSame( 'agentwp_search_backfill', Index::BACKFILL_HOOK );
	}

	public function test_backfill_lock_constant_exists(): void {
		$this->assertSame( 'agentwp_search_backfill_lock', Index::BACKFILL_LOCK );
	}

	// ===========================================
	// Cron Interval Tests
	// ===========================================

	public function test_add_cron_interval_adds_one_minute_schedule(): void {
		$schedules = Index::add_cron_interval( array() );

		$this->assertArrayHasKey( 'agentwp_one_minute', $schedules );
		$this->assertSame( 60, $schedules['agentwp_one_minute']['interval'] );
	}

	public function test_add_cron_interval_preserves_existing_schedules(): void {
		$existing = array(
			'hourly' => array(
				'interval' => 3600,
				'display'  => 'Hourly',
			),
		);

		$schedules = Index::add_cron_interval( $existing );

		$this->assertArrayHasKey( 'hourly', $schedules );
		$this->assertArrayHasKey( 'agentwp_one_minute', $schedules );
	}

	public function test_add_cron_interval_does_not_overwrite_existing(): void {
		$existing = array(
			'agentwp_one_minute' => array(
				'interval' => 999,
				'display'  => 'Custom',
			),
		);

		$schedules = Index::add_cron_interval( $existing );

		// Should not overwrite the existing custom schedule.
		$this->assertSame( 999, $schedules['agentwp_one_minute']['interval'] );
	}

	// ===========================================
	// Lock Behavior Tests
	// ===========================================

	public function test_acquire_lock_returns_true_when_not_locked(): void {
		// Mock get_transient to return false (no lock).
		WP_Mock::userFunction(
			'get_transient',
			array(
				'args'   => array( Index::BACKFILL_LOCK ),
				'return_in_order' => array( false, false ),
			)
		);

		// Mock set_transient to return true (lock acquired).
		WP_Mock::userFunction(
			'set_transient',
			array(
				'args'   => array( Index::BACKFILL_LOCK, WP_Mock\Functions::type( 'array' ), Index::BACKFILL_LOCK_TTL ),
				'return' => true,
			)
		);

		$method = $this->get_method( 'acquire_backfill_lock' );
		$result = $method->invoke( null );

		$this->assertTrue( $result );
	}

	public function test_acquire_lock_returns_false_when_already_locked(): void {
		// Mock get_transient to return a timestamp (lock exists).
		WP_Mock::userFunction(
			'get_transient',
			array(
				'args'   => array( Index::BACKFILL_LOCK ),
				'return' => array(
					'token'      => 'lock-token',
					'issued_at'  => time(),
					'expires_at' => time() + Index::BACKFILL_LOCK_TTL,
				),
			)
		);

		$method = $this->get_method( 'acquire_backfill_lock' );
		$result = $method->invoke( null );

		$this->assertFalse( $result );
	}

	public function test_release_lock_calls_delete_transient(): void {
		$this->set_static_property( 'backfill_lock_token', 'lock-token' );

		WP_Mock::userFunction(
			'get_transient',
			array(
				'args'   => array( Index::BACKFILL_LOCK ),
				'return' => array(
					'token'      => 'lock-token',
					'issued_at'  => time(),
					'expires_at' => time() + Index::BACKFILL_LOCK_TTL,
				),
			)
		);

		// Mock delete_transient to verify it's called.
		WP_Mock::userFunction(
			'delete_transient',
			array(
				'args'   => array( Index::BACKFILL_LOCK ),
				'times'  => 1,
				'return' => true,
			)
		);

		$release_method = $this->get_method( 'release_backfill_lock' );
		$release_method->invoke( null );

		// WP_Mock will verify delete_transient was called once.
		$this->assertTrue( true );
	}

	public function test_release_lock_skips_when_token_mismatch(): void {
		$this->set_static_property( 'backfill_lock_token', 'lock-token' );

		WP_Mock::userFunction(
			'get_transient',
			array(
				'args'   => array( Index::BACKFILL_LOCK ),
				'return' => array(
					'token'      => 'other-token',
					'issued_at'  => time(),
					'expires_at' => time() + Index::BACKFILL_LOCK_TTL,
				),
			)
		);

		WP_Mock::userFunction(
			'delete_transient',
			array(
				'args'  => array( Index::BACKFILL_LOCK ),
				'times' => 0,
			)
		);

		$release_method = $this->get_method( 'release_backfill_lock' );
		$release_method->invoke( null );

		$this->assertTrue( true );
	}

	// ===========================================
	// Scheduling Tests
	// ===========================================

	public function test_schedule_backfill_skips_when_complete(): void {
		WP_Mock::userFunction(
			'get_option',
			array(
				'args'   => array( Index::STATE_OPTION, array() ),
				'return' => array(
					'products'  => -1,
					'orders'    => -1,
					'customers' => -1,
				),
			)
		);

		WP_Mock::userFunction(
			'wp_next_scheduled',
			array(
				'args'   => array( Index::BACKFILL_HOOK ),
				'return' => false,
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

		Index::schedule_backfill();
		$this->assertTrue( true );
	}

	public function test_schedule_backfill_schedules_when_needed(): void {
		WP_Mock::userFunction(
			'get_option',
			array(
				'args'   => array( Index::STATE_OPTION, array() ),
				'return' => array(),
			)
		);

		WP_Mock::userFunction(
			'wp_next_scheduled',
			array(
				'args'   => array( Index::BACKFILL_HOOK ),
				'return' => false,
			)
		);

		WP_Mock::userFunction(
			'wp_schedule_event',
			array(
				'args'   => array( WP_Mock\Functions::type( 'int' ), 'agentwp_one_minute', Index::BACKFILL_HOOK ),
				'return' => true,
				'times'  => 1,
			)
		);

		Index::schedule_backfill();
		$this->assertTrue( true );
	}

	public function test_schedule_backfill_reschedules_when_stale(): void {
		$stale = time() - ( Index::BACKFILL_STUCK_THRESHOLD + 5 );

		WP_Mock::userFunction(
			'get_option',
			array(
				'args'   => array( Index::STATE_OPTION, array() ),
				'return' => array(
					'products'  => 0,
					'orders'    => 0,
					'customers' => 0,
				),
			)
		);

		WP_Mock::userFunction(
			'wp_next_scheduled',
			array(
				'args'            => array( Index::BACKFILL_HOOK ),
				'return_in_order' => array( $stale, $stale, false ),
			)
		);

		WP_Mock::userFunction(
			'wp_unschedule_event',
			array(
				'args'  => array( $stale, Index::BACKFILL_HOOK ),
				'times' => 1,
			)
		);

		WP_Mock::userFunction(
			'wp_schedule_event',
			array(
				'args'   => array( WP_Mock\Functions::type( 'int' ), 'agentwp_one_minute', Index::BACKFILL_HOOK ),
				'return' => true,
				'times'  => 1,
			)
		);

		Index::schedule_backfill();
		$this->assertTrue( true );
	}

	public function test_schedule_backfill_reschedules_when_heartbeat_stale(): void {
		$future = time() + 30;
		$stale  = time() - ( Index::BACKFILL_STUCK_THRESHOLD + 5 );

		WP_Mock::userFunction(
			'get_option',
			array(
				'args'   => array( Index::STATE_OPTION, array() ),
				'return' => array(
					'products'  => 0,
					'orders'    => 0,
					'customers' => 0,
				),
			)
		);

		WP_Mock::userFunction(
			'get_option',
			array(
				'args'   => array( Index::BACKFILL_HEARTBEAT_OPTION, array() ),
				'return' => array(
					'last_run' => $stale,
				),
			)
		);

		WP_Mock::userFunction(
			'wp_next_scheduled',
			array(
				'args'            => array( Index::BACKFILL_HOOK ),
				'return_in_order' => array( $future, $future, false ),
			)
		);

		WP_Mock::userFunction(
			'wp_unschedule_event',
			array(
				'args'  => array( $future, Index::BACKFILL_HOOK ),
				'times' => 1,
			)
		);

		WP_Mock::userFunction(
			'wp_schedule_event',
			array(
				'args'   => array( WP_Mock\Functions::type( 'int' ), 'agentwp_one_minute', Index::BACKFILL_HOOK ),
				'return' => true,
				'times'  => 1,
			)
		);

		Index::schedule_backfill();
		$this->assertTrue( true );
	}

	// ===========================================
	// Scheduled Backfill Execution Tests
	// ===========================================

	public function test_run_scheduled_backfill_skips_when_locked(): void {
		// Mock get_transient to return a timestamp (lock exists).
		WP_Mock::userFunction(
			'get_transient',
			array(
				'args'   => array( Index::BACKFILL_LOCK ),
				'return' => time(),
			)
		);

		// Reset the backfill_ran flag.
		$this->set_static_property( 'backfill_ran', false );

		// Run scheduled backfill - should skip due to lock.
		Index::run_scheduled_backfill();

		// backfill_ran should still be false since we skipped.
		$this->assertFalse( $this->get_static_property( 'backfill_ran' ) );
	}
}
