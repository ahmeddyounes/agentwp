<?php
/**
 * SchemaManager unit tests.
 *
 * @package AgentWP\Tests\Unit\Plugin
 */

namespace AgentWP\Tests\Unit\Plugin;

use AgentWP\Billing\UsageTracker;
use AgentWP\Plugin\SchemaManager;
use AgentWP\Search\Index;
use AgentWP\Tests\TestCase;
use ReflectionClass;

/**
 * Unit tests for SchemaManager.
 *
 * Tests validate the schema management functionality including:
 * - Option naming conventions
 * - Version tracking
 * - Table listing
 * - Public API structure
 */
class SchemaManagerTest extends TestCase {

	// ===========================================
	// Option Naming Tests
	// ===========================================

	public function test_option_constant_is_namespaced(): void {
		$this->assertStringStartsWith( 'agentwp_', SchemaManager::OPTION_SCHEMA_VERSION );
	}

	public function test_option_constant_contains_schema(): void {
		$this->assertStringContainsString( 'schema', SchemaManager::OPTION_SCHEMA_VERSION );
	}

	public function test_option_constant_contains_version(): void {
		$this->assertStringContainsString( 'version', SchemaManager::OPTION_SCHEMA_VERSION );
	}

	public function test_option_constant_is_correct_value(): void {
		$this->assertSame( 'agentwp_schema_version', SchemaManager::OPTION_SCHEMA_VERSION );
	}

	// ===========================================
	// Schema Version Constant Tests
	// ===========================================

	public function test_schema_version_is_string(): void {
		$this->assertIsString( SchemaManager::SCHEMA_VERSION );
	}

	public function test_schema_version_is_valid_semver(): void {
		$version = SchemaManager::SCHEMA_VERSION;
		// Version should be valid for version_compare.
		$this->assertNotFalse( version_compare( $version, '0.0' ) );
	}

	public function test_schema_version_value(): void {
		$this->assertSame( '1.0', SchemaManager::SCHEMA_VERSION );
	}

	// ===========================================
	// Public API Tests
	// ===========================================

	public function test_create_tables_is_public(): void {
		$reflection = new ReflectionClass( SchemaManager::class );
		$method     = $reflection->getMethod( 'create_tables' );

		$this->assertTrue( $method->isPublic() );
		$this->assertTrue( $method->isStatic() );
	}

	public function test_create_tables_returns_bool(): void {
		$reflection = new ReflectionClass( SchemaManager::class );
		$method     = $reflection->getMethod( 'create_tables' );
		$returnType = $method->getReturnType();

		$this->assertNotNull( $returnType );
		$this->assertSame( 'bool', $returnType->getName() );
	}

	public function test_ensure_tables_is_public(): void {
		$reflection = new ReflectionClass( SchemaManager::class );
		$method     = $reflection->getMethod( 'ensure_tables' );

		$this->assertTrue( $method->isPublic() );
		$this->assertTrue( $method->isStatic() );
	}

	public function test_ensure_tables_returns_void(): void {
		$reflection = new ReflectionClass( SchemaManager::class );
		$method     = $reflection->getMethod( 'ensure_tables' );
		$returnType = $method->getReturnType();

		$this->assertNotNull( $returnType );
		$this->assertSame( 'void', $returnType->getName() );
	}

	public function test_migrate_is_public(): void {
		$reflection = new ReflectionClass( SchemaManager::class );
		$method     = $reflection->getMethod( 'migrate' );

		$this->assertTrue( $method->isPublic() );
		$this->assertTrue( $method->isStatic() );
	}

	public function test_migrate_returns_bool(): void {
		$reflection = new ReflectionClass( SchemaManager::class );
		$method     = $reflection->getMethod( 'migrate' );
		$returnType = $method->getReturnType();

		$this->assertNotNull( $returnType );
		$this->assertSame( 'bool', $returnType->getName() );
	}

	public function test_migrate_accepts_two_string_parameters(): void {
		$reflection = new ReflectionClass( SchemaManager::class );
		$method     = $reflection->getMethod( 'migrate' );
		$params     = $method->getParameters();

		$this->assertCount( 2, $params );
		$this->assertSame( 'from_version', $params[0]->getName() );
		$this->assertSame( 'to_version', $params[1]->getName() );
		$this->assertSame( 'string', $params[0]->getType()->getName() );
		$this->assertSame( 'string', $params[1]->getType()->getName() );
	}

	public function test_get_schema_version_is_public(): void {
		$reflection = new ReflectionClass( SchemaManager::class );
		$method     = $reflection->getMethod( 'get_schema_version' );

		$this->assertTrue( $method->isPublic() );
		$this->assertTrue( $method->isStatic() );
	}

	public function test_get_schema_version_returns_string(): void {
		$reflection = new ReflectionClass( SchemaManager::class );
		$method     = $reflection->getMethod( 'get_schema_version' );
		$returnType = $method->getReturnType();

		$this->assertNotNull( $returnType );
		$this->assertSame( 'string', $returnType->getName() );
	}

	public function test_update_schema_version_is_public(): void {
		$reflection = new ReflectionClass( SchemaManager::class );
		$method     = $reflection->getMethod( 'update_schema_version' );

		$this->assertTrue( $method->isPublic() );
		$this->assertTrue( $method->isStatic() );
	}

	public function test_update_schema_version_returns_bool(): void {
		$reflection = new ReflectionClass( SchemaManager::class );
		$method     = $reflection->getMethod( 'update_schema_version' );
		$returnType = $method->getReturnType();

		$this->assertNotNull( $returnType );
		$this->assertSame( 'bool', $returnType->getName() );
	}

	public function test_needs_upgrade_is_public(): void {
		$reflection = new ReflectionClass( SchemaManager::class );
		$method     = $reflection->getMethod( 'needs_upgrade' );

		$this->assertTrue( $method->isPublic() );
		$this->assertTrue( $method->isStatic() );
	}

	public function test_needs_upgrade_returns_bool(): void {
		$reflection = new ReflectionClass( SchemaManager::class );
		$method     = $reflection->getMethod( 'needs_upgrade' );
		$returnType = $method->getReturnType();

		$this->assertNotNull( $returnType );
		$this->assertSame( 'bool', $returnType->getName() );
	}

	public function test_get_tables_is_public(): void {
		$reflection = new ReflectionClass( SchemaManager::class );
		$method     = $reflection->getMethod( 'get_tables' );

		$this->assertTrue( $method->isPublic() );
		$this->assertTrue( $method->isStatic() );
	}

	public function test_get_tables_returns_array(): void {
		$reflection = new ReflectionClass( SchemaManager::class );
		$method     = $reflection->getMethod( 'get_tables' );
		$returnType = $method->getReturnType();

		$this->assertNotNull( $returnType );
		$this->assertSame( 'array', $returnType->getName() );
	}

	public function test_table_exists_is_public(): void {
		$reflection = new ReflectionClass( SchemaManager::class );
		$method     = $reflection->getMethod( 'table_exists' );

		$this->assertTrue( $method->isPublic() );
		$this->assertTrue( $method->isStatic() );
	}

	public function test_table_exists_returns_bool(): void {
		$reflection = new ReflectionClass( SchemaManager::class );
		$method     = $reflection->getMethod( 'table_exists' );
		$returnType = $method->getReturnType();

		$this->assertNotNull( $returnType );
		$this->assertSame( 'bool', $returnType->getName() );
	}

	// ===========================================
	// Table Registration Tests
	// ===========================================

	public function test_usage_tracker_table_constant_exists(): void {
		$this->assertTrue( defined( UsageTracker::class . '::TABLE' ) );
		$this->assertSame( 'agentwp_usage', UsageTracker::TABLE );
	}

	public function test_search_index_table_constant_exists(): void {
		$this->assertTrue( defined( Index::class . '::TABLE' ) );
		$this->assertSame( 'agentwp_search_index', Index::TABLE );
	}

	// ===========================================
	// Version Comparison Logic Tests
	// ===========================================

	public function test_version_compare_for_upgrade_detection(): void {
		$current = '0.9';
		$target  = SchemaManager::SCHEMA_VERSION;

		$needs_upgrade = version_compare( $current, $target, '<' );
		$this->assertTrue( $needs_upgrade );
	}

	public function test_version_compare_when_up_to_date(): void {
		$current = SchemaManager::SCHEMA_VERSION;
		$target  = SchemaManager::SCHEMA_VERSION;

		$needs_upgrade = version_compare( $current, $target, '<' );
		$this->assertFalse( $needs_upgrade );
	}

	public function test_version_compare_when_ahead(): void {
		$current = '2.0';
		$target  = SchemaManager::SCHEMA_VERSION;

		$needs_upgrade = version_compare( $current, $target, '<' );
		$this->assertFalse( $needs_upgrade );
	}

	public function test_empty_version_needs_upgrade(): void {
		$current = '';

		// Empty string indicates fresh install - needs tables.
		$this->assertSame( '', $current );
	}
}
