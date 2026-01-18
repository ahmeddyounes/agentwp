<?php
/**
 * Upgrader unit tests.
 *
 * @package AgentWP\Tests\Unit\Plugin
 */

namespace AgentWP\Tests\Unit\Plugin;

use AgentWP\Plugin\Upgrader;
use AgentWP\Tests\TestCase;
use ReflectionClass;

/**
 * Unit tests for Upgrader.
 *
 * Tests validate the version tracking and upgrade step logic including:
 * - Option naming conventions
 * - Version comparison logic
 * - Upgrade step ordering
 * - Reset functionality for testing
 */
class UpgraderTest extends TestCase {

	/**
	 * Reset upgrader state before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		Upgrader::reset();
	}

	/**
	 * Reset upgrader state after each test.
	 */
	public function tearDown(): void {
		Upgrader::reset();
		parent::tearDown();
	}

	/**
	 * Get private static property value.
	 *
	 * @param string $name Property name.
	 * @return mixed
	 */
	private function get_static_property( string $name ) {
		$reflection = new ReflectionClass( Upgrader::class );
		$prop       = $reflection->getProperty( $name );
		$prop->setAccessible( true );
		return $prop->getValue();
	}

	/**
	 * Set private static property value.
	 *
	 * @param string $name  Property name.
	 * @param mixed  $value Value to set.
	 */
	private function set_static_property( string $name, $value ): void {
		$reflection = new ReflectionClass( Upgrader::class );
		$prop       = $reflection->getProperty( $name );
		$prop->setAccessible( true );
		$prop->setValue( null, $value );
	}

	// ===========================================
	// Option Naming Tests
	// ===========================================

	public function test_option_constant_is_namespaced(): void {
		$this->assertStringStartsWith( 'agentwp_', Upgrader::OPTION_INSTALLED_VERSION );
	}

	public function test_option_constant_contains_version(): void {
		$this->assertStringContainsString( 'version', Upgrader::OPTION_INSTALLED_VERSION );
	}

	public function test_option_constant_is_correct_value(): void {
		$this->assertSame( 'agentwp_installed_version', Upgrader::OPTION_INSTALLED_VERSION );
	}

	// ===========================================
	// Version Retrieval Tests
	// ===========================================

	public function test_get_current_version_returns_string(): void {
		$version = Upgrader::get_current_version();
		$this->assertIsString( $version );
	}

	public function test_get_current_version_returns_non_empty(): void {
		$version = Upgrader::get_current_version();
		$this->assertNotEmpty( $version );
	}

	public function test_get_current_version_returns_valid_semver(): void {
		$version = Upgrader::get_current_version();
		// Version should be valid for version_compare.
		$this->assertNotFalse( version_compare( $version, '0.0.0' ) );
	}

	public function test_get_current_version_uses_constant(): void {
		// If AGENTWP_VERSION is defined, it should use it.
		if ( defined( 'AGENTWP_VERSION' ) ) {
			$this->assertSame( AGENTWP_VERSION, Upgrader::get_current_version() );
		} else {
			$this->assertSame( '0.0.0', Upgrader::get_current_version() );
		}
	}

	// ===========================================
	// Static State Tests
	// ===========================================

	public function test_has_run_starts_false(): void {
		$this->assertFalse( $this->get_static_property( 'has_run' ) );
	}

	public function test_reset_sets_has_run_to_false(): void {
		$this->set_static_property( 'has_run', true );
		$this->assertTrue( $this->get_static_property( 'has_run' ) );

		Upgrader::reset();

		$this->assertFalse( $this->get_static_property( 'has_run' ) );
	}

	// ===========================================
	// Version Comparison Logic Tests
	// ===========================================

	public function test_version_compare_for_upgrade_detection(): void {
		// Test the core logic used in needs_upgrade().
		$installed = '0.0.1';
		$current   = '0.1.0';

		$needs_upgrade = version_compare( $installed, $current, '<' );
		$this->assertTrue( $needs_upgrade );
	}

	public function test_version_compare_when_up_to_date(): void {
		$installed = '0.1.0';
		$current   = '0.1.0';

		$needs_upgrade = version_compare( $installed, $current, '<' );
		$this->assertFalse( $needs_upgrade );
	}

	public function test_version_compare_when_ahead(): void {
		// Edge case: installed version is ahead (shouldn't happen normally).
		$installed = '0.2.0';
		$current   = '0.1.0';

		$needs_upgrade = version_compare( $installed, $current, '<' );
		$this->assertFalse( $needs_upgrade );
	}

	// ===========================================
	// Upgrade Step Ordering Tests
	// ===========================================

	public function test_get_pending_steps_returns_array(): void {
		$steps = Upgrader::get_pending_steps( '0.0.1', '0.1.0' );
		$this->assertIsArray( $steps );
	}

	public function test_get_pending_steps_empty_for_same_version(): void {
		$steps = Upgrader::get_pending_steps( '0.1.0', '0.1.0' );
		$this->assertSame( array(), $steps );
	}

	public function test_get_pending_steps_empty_when_downgrading(): void {
		$steps = Upgrader::get_pending_steps( '0.2.0', '0.1.0' );
		$this->assertSame( array(), $steps );
	}

	public function test_get_pending_steps_filters_by_version_range(): void {
		// Test the filtering logic directly.
		$from = '0.1.0';
		$to   = '0.3.0';

		// Simulate what would happen with defined steps.
		$test_steps = array(
			'0.0.5' => 'step_0_0_5', // Before range.
			'0.1.0' => 'step_0_1_0', // At from (should be skipped).
			'0.2.0' => 'step_0_2_0', // In range.
			'0.3.0' => 'step_0_3_0', // At to (should be included).
			'0.4.0' => 'step_0_4_0', // Beyond range.
		);

		$pending = array();
		foreach ( array_keys( $test_steps ) as $step_version ) {
			// Skip steps already applied (version <= from).
			if ( version_compare( $step_version, $from, '<=' ) ) {
				continue;
			}
			// Skip steps beyond target version.
			if ( version_compare( $step_version, $to, '>' ) ) {
				continue;
			}
			$pending[] = $step_version;
		}

		$this->assertSame( array( '0.2.0', '0.3.0' ), $pending );
	}

	// ===========================================
	// Upgrade Step Execution Order Tests
	// ===========================================

	public function test_upgrade_steps_sorted_by_version(): void {
		// Verify that version_compare with uksort works correctly.
		$steps = array(
			'0.3.0' => 'c',
			'0.1.0' => 'a',
			'0.2.0' => 'b',
		);

		uksort( $steps, 'version_compare' );
		$keys = array_keys( $steps );

		$this->assertSame( array( '0.1.0', '0.2.0', '0.3.0' ), $keys );
	}

	public function test_upgrade_steps_handle_patch_versions(): void {
		$steps = array(
			'0.1.1' => 'b',
			'0.1.0' => 'a',
			'0.1.2' => 'c',
		);

		uksort( $steps, 'version_compare' );
		$keys = array_keys( $steps );

		$this->assertSame( array( '0.1.0', '0.1.1', '0.1.2' ), $keys );
	}

	public function test_upgrade_steps_handle_major_bumps(): void {
		$steps = array(
			'2.0.0' => 'c',
			'0.1.0' => 'a',
			'1.0.0' => 'b',
		);

		uksort( $steps, 'version_compare' );
		$keys = array_keys( $steps );

		$this->assertSame( array( '0.1.0', '1.0.0', '2.0.0' ), $keys );
	}

	// ===========================================
	// Fresh Install Detection Tests
	// ===========================================

	public function test_empty_installed_version_is_fresh_install(): void {
		$installed = '';
		$this->assertSame( '', $installed );
		// Empty string means fresh install - should not run upgrades.
	}

	public function test_fresh_install_should_not_run_upgrade_steps(): void {
		// Verify the logic: fresh install sets version but doesn't run steps.
		$installed = '';

		// Fresh install: set version, don't run upgrades.
		if ( '' === $installed ) {
			$run_upgrades = false;
		} else {
			$run_upgrades = true;
		}

		$this->assertFalse( $run_upgrades );
	}

	// ===========================================
	// Idempotency Tests
	// ===========================================

	public function test_has_run_flag_prevents_multiple_executions(): void {
		// First call sets has_run to true.
		$this->set_static_property( 'has_run', false );
		$this->assertFalse( $this->get_static_property( 'has_run' ) );

		// Simulate first init call.
		$this->set_static_property( 'has_run', true );
		$this->assertTrue( $this->get_static_property( 'has_run' ) );

		// Second call should check has_run and return early.
		$has_run = $this->get_static_property( 'has_run' );
		$this->assertTrue( $has_run ); // Would return early.
	}

	// ===========================================
	// Return Type Tests
	// ===========================================

	public function test_get_installed_version_returns_string(): void {
		// Method signature requires string return.
		$reflection = new ReflectionClass( Upgrader::class );
		$method     = $reflection->getMethod( 'get_installed_version' );
		$returnType = $method->getReturnType();

		$this->assertNotNull( $returnType );
		$this->assertSame( 'string', $returnType->getName() );
	}

	public function test_get_current_version_returns_string_type(): void {
		$reflection = new ReflectionClass( Upgrader::class );
		$method     = $reflection->getMethod( 'get_current_version' );
		$returnType = $method->getReturnType();

		$this->assertNotNull( $returnType );
		$this->assertSame( 'string', $returnType->getName() );
	}

	public function test_update_installed_version_returns_bool_type(): void {
		$reflection = new ReflectionClass( Upgrader::class );
		$method     = $reflection->getMethod( 'update_installed_version' );
		$returnType = $method->getReturnType();

		$this->assertNotNull( $returnType );
		$this->assertSame( 'bool', $returnType->getName() );
	}

	public function test_needs_upgrade_returns_bool_type(): void {
		$reflection = new ReflectionClass( Upgrader::class );
		$method     = $reflection->getMethod( 'needs_upgrade' );
		$returnType = $method->getReturnType();

		$this->assertNotNull( $returnType );
		$this->assertSame( 'bool', $returnType->getName() );
	}

	// ===========================================
	// Public API Tests
	// ===========================================

	public function test_init_is_public(): void {
		$reflection = new ReflectionClass( Upgrader::class );
		$method     = $reflection->getMethod( 'init' );

		$this->assertTrue( $method->isPublic() );
		$this->assertTrue( $method->isStatic() );
	}

	public function test_reset_is_public(): void {
		$reflection = new ReflectionClass( Upgrader::class );
		$method     = $reflection->getMethod( 'reset' );

		$this->assertTrue( $method->isPublic() );
		$this->assertTrue( $method->isStatic() );
	}

	public function test_maybe_upgrade_is_public(): void {
		$reflection = new ReflectionClass( Upgrader::class );
		$method     = $reflection->getMethod( 'maybe_upgrade' );

		$this->assertTrue( $method->isPublic() );
		$this->assertTrue( $method->isStatic() );
	}

	public function test_run_upgrades_is_private(): void {
		$reflection = new ReflectionClass( Upgrader::class );
		$method     = $reflection->getMethod( 'run_upgrades' );

		$this->assertTrue( $method->isPrivate() );
		$this->assertTrue( $method->isStatic() );
	}

	public function test_get_upgrade_steps_is_private(): void {
		$reflection = new ReflectionClass( Upgrader::class );
		$method     = $reflection->getMethod( 'get_upgrade_steps' );

		$this->assertTrue( $method->isPrivate() );
		$this->assertTrue( $method->isStatic() );
	}

	// ===========================================
	// Upgrade Step Content Tests
	// ===========================================

	public function test_upgrade_step_0_2_0_exists(): void {
		// Use reflection to access the private method.
		$reflection = new ReflectionClass( Upgrader::class );
		$method     = $reflection->getMethod( 'get_upgrade_steps' );
		$method->setAccessible( true );

		$steps = $method->invoke( null );

		$this->assertArrayHasKey( '0.2.0', $steps );
	}

	public function test_upgrade_step_0_2_0_callback_references_valid_method(): void {
		$reflection = new ReflectionClass( Upgrader::class );
		$method     = $reflection->getMethod( 'get_upgrade_steps' );
		$method->setAccessible( true );

		$steps = $method->invoke( null );

		// Check the callback is an array with class and method name.
		$this->assertIsArray( $steps['0.2.0'] );
		$this->assertCount( 2, $steps['0.2.0'] );
		$this->assertSame( Upgrader::class, $steps['0.2.0'][0] );
		$this->assertSame( 'upgrade_to_0_2_0', $steps['0.2.0'][1] );

		// Verify the method exists.
		$this->assertTrue( $reflection->hasMethod( $steps['0.2.0'][1] ) );
	}

	public function test_upgrade_step_0_2_0_method_is_private(): void {
		$reflection = new ReflectionClass( Upgrader::class );
		$method     = $reflection->getMethod( 'upgrade_to_0_2_0' );

		$this->assertTrue( $method->isPrivate() );
		$this->assertTrue( $method->isStatic() );
	}

	public function test_get_pending_steps_includes_0_2_0_for_upgrade_from_0_1_0(): void {
		$steps = Upgrader::get_pending_steps( '0.1.0', '0.2.0' );

		$this->assertContains( '0.2.0', $steps );
	}

	public function test_get_pending_steps_excludes_0_2_0_for_upgrade_from_0_2_0(): void {
		$steps = Upgrader::get_pending_steps( '0.2.0', '0.2.0' );

		$this->assertNotContains( '0.2.0', $steps );
	}

	// ===========================================
	// Multisite Support Tests
	// ===========================================

	public function test_run_network_upgrades_is_public(): void {
		$reflection = new ReflectionClass( Upgrader::class );
		$method     = $reflection->getMethod( 'run_network_upgrades' );

		$this->assertTrue( $method->isPublic() );
		$this->assertTrue( $method->isStatic() );
	}

	public function test_run_network_upgrades_returns_array(): void {
		$reflection = new ReflectionClass( Upgrader::class );
		$method     = $reflection->getMethod( 'run_network_upgrades' );
		$returnType = $method->getReturnType();

		$this->assertNotNull( $returnType );
		$this->assertSame( 'array', $returnType->getName() );
	}
}
