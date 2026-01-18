<?php
/**
 * Upgrader migration tests.
 *
 * @package AgentWP\Tests\Unit\Plugin
 */

namespace AgentWP\Tests\Unit\Plugin;

use AgentWP\Plugin\SettingsManager;
use AgentWP\Plugin\Upgrader;
use AgentWP\Tests\TestCase;
use ReflectionClass;

/**
 * Tests for upgrade step implementations.
 *
 * These tests verify the structure and metadata of upgrade steps.
 * Actual migration behavior is tested in integration tests with real WordPress.
 */
class UpgraderMigrationTest extends TestCase {

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

	// ===========================================
	// Upgrade Step Structure Tests
	// ===========================================

	public function test_upgrade_steps_are_sorted_by_version(): void {
		$reflection = new ReflectionClass( Upgrader::class );
		$method     = $reflection->getMethod( 'get_upgrade_steps' );
		$method->setAccessible( true );

		$steps = $method->invoke( null );
		$versions = array_keys( $steps );

		$sorted = $versions;
		usort( $sorted, 'version_compare' );

		$this->assertSame( $sorted, $versions, 'Upgrade steps should be sorted by version' );
	}

	public function test_all_upgrade_steps_have_valid_method_references(): void {
		$reflection = new ReflectionClass( Upgrader::class );
		$method     = $reflection->getMethod( 'get_upgrade_steps' );
		$method->setAccessible( true );

		$steps = $method->invoke( null );

		foreach ( $steps as $version => $callback ) {
			$this->assertIsArray(
				$callback,
				"Upgrade step for version {$version} should be an array callback"
			);
			$this->assertCount(
				2,
				$callback,
				"Upgrade step for version {$version} should have class and method"
			);
			$this->assertTrue(
				$reflection->hasMethod( $callback[1] ),
				"Upgrade step for version {$version} should reference an existing method"
			);
		}
	}

	public function test_all_upgrade_step_methods_are_private(): void {
		$reflection = new ReflectionClass( Upgrader::class );
		$stepsMethod = $reflection->getMethod( 'get_upgrade_steps' );
		$stepsMethod->setAccessible( true );

		$steps = $stepsMethod->invoke( null );

		foreach ( $steps as $version => $callback ) {
			$stepMethod = $reflection->getMethod( $callback[1] );
			$this->assertTrue(
				$stepMethod->isPrivate(),
				"Upgrade step method for version {$version} should be private"
			);
			$this->assertTrue(
				$stepMethod->isStatic(),
				"Upgrade step method for version {$version} should be static"
			);
		}
	}

	// ===========================================
	// 0.1.1 Upgrade Step Structure Tests
	// ===========================================

	public function test_upgrade_0_1_1_step_exists(): void {
		$reflection = new ReflectionClass( Upgrader::class );
		$method     = $reflection->getMethod( 'get_upgrade_steps' );
		$method->setAccessible( true );

		$steps = $method->invoke( null );

		$this->assertArrayHasKey( '0.1.1', $steps );
	}

	public function test_upgrade_0_1_1_method_exists(): void {
		$reflection = new ReflectionClass( Upgrader::class );

		$this->assertTrue( $reflection->hasMethod( 'upgrade_to_0_1_1' ) );
	}

	public function test_upgrade_0_1_1_method_is_private_static(): void {
		$reflection = new ReflectionClass( Upgrader::class );
		$method     = $reflection->getMethod( 'upgrade_to_0_1_1' );

		$this->assertTrue( $method->isPrivate() );
		$this->assertTrue( $method->isStatic() );
	}

	public function test_upgrade_0_1_1_method_returns_void(): void {
		$reflection = new ReflectionClass( Upgrader::class );
		$method     = $reflection->getMethod( 'upgrade_to_0_1_1' );
		$returnType = $method->getReturnType();

		$this->assertNotNull( $returnType );
		$this->assertSame( 'void', $returnType->getName() );
	}

	// ===========================================
	// Settings Manager Constants Tests
	// ===========================================

	public function test_memory_limit_constant_exists(): void {
		$this->assertTrue( defined( SettingsManager::class . '::OPTION_MEMORY_LIMIT' ) );
		$this->assertSame( 'agentwp_memory_limit', SettingsManager::OPTION_MEMORY_LIMIT );
	}

	public function test_memory_ttl_constant_exists(): void {
		$this->assertTrue( defined( SettingsManager::class . '::OPTION_MEMORY_TTL' ) );
		$this->assertSame( 'agentwp_memory_ttl', SettingsManager::OPTION_MEMORY_TTL );
	}

	public function test_memory_limit_default_is_valid(): void {
		$this->assertIsInt( SettingsManager::DEFAULT_MEMORY_LIMIT );
		$this->assertGreaterThan( 0, SettingsManager::DEFAULT_MEMORY_LIMIT );
	}

	public function test_memory_ttl_default_is_valid(): void {
		$this->assertIsInt( SettingsManager::DEFAULT_MEMORY_TTL );
		$this->assertGreaterThan( 0, SettingsManager::DEFAULT_MEMORY_TTL );
	}

	public function test_memory_limit_default_value(): void {
		$this->assertSame( 5, SettingsManager::DEFAULT_MEMORY_LIMIT );
	}

	public function test_memory_ttl_default_value(): void {
		$this->assertSame( 1800, SettingsManager::DEFAULT_MEMORY_TTL );
	}

	// ===========================================
	// Pending Steps Detection Tests
	// ===========================================

	public function test_pending_steps_includes_0_1_1_when_upgrading_from_0_1_0(): void {
		$steps = Upgrader::get_pending_steps( '0.1.0', '1.0.0' );

		$this->assertContains( '0.1.1', $steps );
	}

	public function test_pending_steps_excludes_0_1_1_when_already_at_0_1_1(): void {
		$steps = Upgrader::get_pending_steps( '0.1.1', '1.0.0' );

		$this->assertNotContains( '0.1.1', $steps );
	}

	public function test_pending_steps_excludes_0_1_1_when_upgrading_from_higher_version(): void {
		$steps = Upgrader::get_pending_steps( '0.2.0', '1.0.0' );

		$this->assertNotContains( '0.1.1', $steps );
	}
}
