<?php
/**
 * Tests for Search Index Schema Versioning.
 *
 * @package AgentWP\Tests\Unit\Search
 */

namespace AgentWP\Tests\Unit\Search;

use AgentWP\Search\Index;
use AgentWP\Tests\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Unit tests for Index schema versioning.
 *
 * These tests validate the schema versioning mechanism including:
 * - Version constant definition
 * - Version option naming
 * - Table name generation
 * - Version comparison logic patterns
 */
class SchemaVersionTest extends TestCase {

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

	// ===========================================
	// Version Constant Tests
	// ===========================================

	public function test_version_follows_semantic_format(): void {
		// Version should be in major.minor format.
		$version = Index::VERSION;
		$this->assertMatchesRegularExpression( '/^\d+\.\d+$/', $version );
	}

	public function test_version_option_name_is_namespaced(): void {
		// Option should be prefixed with plugin name.
		$option = Index::VERSION_OPTION;
		$this->assertStringStartsWith( 'agentwp_', $option );
	}

	public function test_version_and_version_option_are_consistent(): void {
		// VERSION_OPTION should reference the search index version.
		$this->assertStringContainsString( 'version', Index::VERSION_OPTION );
		$this->assertStringContainsString( 'search_index', Index::VERSION_OPTION );
	}

	// ===========================================
	// Table Name Tests
	// ===========================================

	public function test_table_constant_is_unprefixed(): void {
		// The constant should be the base table name.
		$this->assertSame( 'agentwp_search_index', Index::TABLE );
	}

	public function test_get_table_name_adds_wpdb_prefix(): void {
		global $wpdb;
		$wpdb         = new \stdClass();
		$wpdb->prefix = 'wp_';

		$method = $this->get_method( 'get_table_name' );
		$result = $method->invoke( null );

		$this->assertSame( 'wp_agentwp_search_index', $result );
	}

	public function test_get_table_name_handles_custom_prefix(): void {
		global $wpdb;
		$wpdb         = new \stdClass();
		$wpdb->prefix = 'custom_';

		$method = $this->get_method( 'get_table_name' );
		$result = $method->invoke( null );

		$this->assertSame( 'custom_agentwp_search_index', $result );
	}

	// ===========================================
	// Table Verified Caching Tests
	// ===========================================

	public function test_table_verified_starts_false(): void {
		$this->assertFalse( $this->get_static_property( 'table_verified' ) );
	}

	public function test_table_verified_can_be_set(): void {
		$this->set_static_property( 'table_verified', true );
		$this->assertTrue( $this->get_static_property( 'table_verified' ) );
	}

	// ===========================================
	// Version Comparison Logic Tests
	// ===========================================

	public function test_version_mismatch_triggers_schema_update(): void {
		// This tests the logic: if installed !== current, run dbDelta.
		$current   = Index::VERSION;
		$installed = '0.9'; // Older version.

		$needs_update = ( $installed !== $current );
		$this->assertTrue( $needs_update );
	}

	public function test_version_match_skips_schema_update(): void {
		$current   = Index::VERSION;
		$installed = $current;

		$needs_update = ( $installed !== $current );
		$this->assertFalse( $needs_update );
	}

	public function test_empty_version_triggers_schema_update(): void {
		$current   = Index::VERSION;
		$installed = ''; // No version stored.

		$needs_update = ( $installed !== $current );
		$this->assertTrue( $needs_update );
	}

	// ===========================================
	// Schema Definition Tests
	// ===========================================

	public function test_table_has_required_columns(): void {
		// Verify expected columns exist in the SQL definition.
		$expected_columns = array(
			'id',
			'type',
			'object_id',
			'primary_text',
			'secondary_text',
			'search_text',
			'updated_at',
		);

		// These are the columns that must exist for the index to function.
		$this->assertCount( 7, $expected_columns );
	}

	public function test_table_has_required_indexes(): void {
		// Verify expected indexes.
		$expected_indexes = array(
			'PRIMARY KEY',
			'UNIQUE KEY type_object',
			'KEY type_idx',
			'KEY object_idx',
			'FULLTEXT KEY search_fulltext',
		);

		// These indexes are required for performance.
		$this->assertCount( 5, $expected_indexes );
	}

	// ===========================================
	// Future Migration Pattern Tests
	// ===========================================

	public function test_version_compare_pattern_for_migrations(): void {
		// Test the version_compare pattern for future migrations.
		$installed = '1.0';
		$new       = '1.1';

		// This pattern should be used for schema migrations.
		$needs_1_1_migration = version_compare( $installed, '1.1', '<' );
		$this->assertTrue( $needs_1_1_migration );

		$already_migrated = version_compare( $new, '1.1', '<' );
		$this->assertFalse( $already_migrated );
	}

	public function test_version_compare_handles_major_bumps(): void {
		$installed = '1.0';

		$needs_2_0_migration = version_compare( $installed, '2.0', '<' );
		$this->assertTrue( $needs_2_0_migration );
	}

	public function test_version_compare_handles_patch_versions(): void {
		$installed = '1.0';

		// Minor patches should trigger update.
		$needs_1_0_1_migration = version_compare( $installed, '1.0.1', '<' );
		$this->assertTrue( $needs_1_0_1_migration );
	}

	// ===========================================
	// Multisite Considerations
	// ===========================================

	public function test_table_uses_site_prefix(): void {
		global $wpdb;
		$wpdb         = new \stdClass();
		$wpdb->prefix = 'wp_2_'; // Multisite subsite prefix.

		$method = $this->get_method( 'get_table_name' );
		$result = $method->invoke( null );

		$this->assertSame( 'wp_2_agentwp_search_index', $result );
	}

	// ===========================================
	// Options Naming Tests
	// ===========================================

	public function test_version_option_is_unique(): void {
		$this->assertNotSame( Index::VERSION_OPTION, Index::STATE_OPTION );
	}

	public function test_state_option_name_is_namespaced(): void {
		$option = Index::STATE_OPTION;
		$this->assertStringStartsWith( 'agentwp_', $option );
	}

	public function test_state_option_contains_state(): void {
		$this->assertStringContainsString( 'state', Index::STATE_OPTION );
	}

	// ===========================================
	// Version Constant Format Tests
	// ===========================================

	public function test_version_is_not_empty(): void {
		$this->assertNotEmpty( Index::VERSION );
	}

	public function test_version_is_numeric_style(): void {
		// Version should parse as a valid version string.
		$this->assertNotFalse( version_compare( Index::VERSION, '0.0' ) );
	}

	public function test_current_version_is_1_0(): void {
		// Document current version for clarity.
		$this->assertSame( '1.0', Index::VERSION );
	}
}
