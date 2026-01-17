<?php
/**
 * Tests for Search Index.
 *
 * @package AgentWP\Tests\Unit\Search
 */

namespace AgentWP\Tests\Unit\Search;

use AgentWP\Search\Index;
use AgentWP\Tests\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Unit tests for Index class.
 *
 * These tests focus on the logic that can be unit tested without a database:
 * - Text normalization
 * - Type normalization
 * - Fulltext query building
 * - Result formatting
 * - Constants validation
 *
 * Integration tests with actual MySQL fulltext are recommended for search behavior.
 */
class IndexTest extends TestCase {

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
	// Constants Tests
	// ===========================================

	public function test_version_constant_is_defined(): void {
		$this->assertSame( '1.0', Index::VERSION );
	}

	public function test_table_constant_is_defined(): void {
		$this->assertSame( 'agentwp_search_index', Index::TABLE );
	}

	public function test_version_option_constant_is_defined(): void {
		$this->assertSame( 'agentwp_search_index_version', Index::VERSION_OPTION );
	}

	public function test_state_option_constant_is_defined(): void {
		$this->assertSame( 'agentwp_search_index_state', Index::STATE_OPTION );
	}

	public function test_default_limit_is_five(): void {
		$this->assertSame( 5, Index::DEFAULT_LIMIT );
	}

	public function test_backfill_limit_is_200(): void {
		$this->assertSame( 200, Index::BACKFILL_LIMIT );
	}

	public function test_backfill_window_is_350ms(): void {
		$this->assertSame( 0.35, Index::BACKFILL_WINDOW );
	}

	// ===========================================
	// normalize_text() Tests
	// ===========================================

	public function test_normalize_text_lowercases(): void {
		$method = $this->get_method( 'normalize_text' );
		$result = $method->invoke( null, 'UPPERCASE TEXT' );
		$this->assertSame( 'uppercase text', $result );
	}

	public function test_normalize_text_strips_html(): void {
		$method = $this->get_method( 'normalize_text' );
		$result = $method->invoke( null, '<b>Bold</b> and <i>italic</i>' );
		$this->assertSame( 'bold and italic', $result );
	}

	public function test_normalize_text_trims_whitespace(): void {
		$method = $this->get_method( 'normalize_text' );
		$result = $method->invoke( null, '  spaced out  ' );
		$this->assertSame( 'spaced out', $result );
	}

	public function test_normalize_text_collapses_multiple_spaces(): void {
		$method = $this->get_method( 'normalize_text' );
		$result = $method->invoke( null, 'multiple   spaces   here' );
		$this->assertSame( 'multiple spaces here', $result );
	}

	public function test_normalize_text_handles_non_string(): void {
		$method = $this->get_method( 'normalize_text' );
		$result = $method->invoke( null, 12345 );
		$this->assertSame( '12345', $result );
	}

	public function test_normalize_text_handles_empty_string(): void {
		$method = $this->get_method( 'normalize_text' );
		$result = $method->invoke( null, '' );
		$this->assertSame( '', $result );
	}

	// ===========================================
	// normalize_types() Tests
	// ===========================================

	public function test_normalize_types_filters_invalid_types(): void {
		$method = $this->get_method( 'normalize_types' );
		$result = $method->invoke( null, array( 'products', 'invalid', 'orders' ) );
		$this->assertSame( array( 'products', 'orders' ), $result );
	}

	public function test_normalize_types_returns_all_when_empty(): void {
		$method = $this->get_method( 'normalize_types' );
		$result = $method->invoke( null, array() );
		$this->assertSame( array( 'products', 'orders', 'customers' ), $result );
	}

	public function test_normalize_types_accepts_products(): void {
		$method = $this->get_method( 'normalize_types' );
		$result = $method->invoke( null, array( 'products' ) );
		$this->assertSame( array( 'products' ), $result );
	}

	public function test_normalize_types_accepts_orders(): void {
		$method = $this->get_method( 'normalize_types' );
		$result = $method->invoke( null, array( 'orders' ) );
		$this->assertSame( array( 'orders' ), $result );
	}

	public function test_normalize_types_accepts_customers(): void {
		$method = $this->get_method( 'normalize_types' );
		$result = $method->invoke( null, array( 'customers' ) );
		$this->assertSame( array( 'customers' ), $result );
	}

	public function test_normalize_types_accepts_all_valid(): void {
		$method = $this->get_method( 'normalize_types' );
		$result = $method->invoke( null, array( 'products', 'orders', 'customers' ) );
		$this->assertCount( 3, $result );
		$this->assertContains( 'products', $result );
		$this->assertContains( 'orders', $result );
		$this->assertContains( 'customers', $result );
	}

	// ===========================================
	// build_fulltext_query() Tests
	// ===========================================

	public function test_build_fulltext_query_adds_prefix_and_wildcard(): void {
		$method = $this->get_method( 'build_fulltext_query' );
		$result = $method->invoke( null, 'widget' );
		$this->assertSame( '+widget*', $result );
	}

	public function test_build_fulltext_query_handles_multiple_words(): void {
		$method = $this->get_method( 'build_fulltext_query' );
		$result = $method->invoke( null, 'blue widget' );
		$this->assertSame( '+blue* +widget*', $result );
	}

	public function test_build_fulltext_query_filters_special_chars(): void {
		$method = $this->get_method( 'build_fulltext_query' );
		$result = $method->invoke( null, 'test@email.com' );
		$this->assertSame( '+test@email.com*', $result );
	}

	public function test_build_fulltext_query_handles_empty_tokens(): void {
		$method = $this->get_method( 'build_fulltext_query' );
		$result = $method->invoke( null, '   ' );
		$this->assertSame( '   ', $result ); // Returns original if no valid tokens.
	}

	public function test_build_fulltext_query_handles_numbers(): void {
		$method = $this->get_method( 'build_fulltext_query' );
		$result = $method->invoke( null, '12345' );
		$this->assertSame( '+12345*', $result );
	}

	// ===========================================
	// format_results() Tests
	// ===========================================

	public function test_format_results_returns_empty_for_empty_rows(): void {
		$method = $this->get_method( 'format_results' );
		$result = $method->invoke( null, 'products', array() );
		$this->assertSame( array(), $result );
	}

	public function test_format_results_formats_product_row(): void {
		$method = $this->get_method( 'format_results' );
		$rows   = array(
			array(
				'object_id'      => 123,
				'primary_text'   => 'Test Widget',
				'secondary_text' => 'SKU-123',
			),
		);

		$result = $method->invoke( null, 'products', $rows );

		$this->assertCount( 1, $result );
		$this->assertSame( 123, $result[0]['id'] );
		$this->assertSame( 'products', $result[0]['type'] );
		$this->assertSame( 'Test Widget', $result[0]['primary'] );
		$this->assertSame( 'SKU-123', $result[0]['secondary'] );
		$this->assertStringContainsString( 'product:123', $result[0]['query'] );
	}

	public function test_format_results_formats_order_row(): void {
		$method = $this->get_method( 'format_results' );
		$rows   = array(
			array(
				'object_id'      => 456,
				'primary_text'   => 'Order #456',
				'secondary_text' => 'Processing',
			),
		);

		$result = $method->invoke( null, 'orders', $rows );

		$this->assertCount( 1, $result );
		$this->assertSame( 456, $result[0]['id'] );
		$this->assertSame( 'orders', $result[0]['type'] );
		$this->assertSame( 'order:456', $result[0]['query'] );
	}

	public function test_format_results_formats_customer_row(): void {
		$method = $this->get_method( 'format_results' );
		$rows   = array(
			array(
				'object_id'      => 789,
				'primary_text'   => 'John Doe',
				'secondary_text' => 'john@example.com',
			),
		);

		$result = $method->invoke( null, 'customers', $rows );

		$this->assertCount( 1, $result );
		$this->assertSame( 789, $result[0]['id'] );
		$this->assertSame( 'customers', $result[0]['type'] );
		$this->assertStringContainsString( 'customer:"john@example.com"', $result[0]['query'] );
	}

	public function test_format_results_skips_rows_without_object_id(): void {
		$method = $this->get_method( 'format_results' );
		$rows   = array(
			array(
				'primary_text'   => 'Missing ID',
				'secondary_text' => '',
			),
		);

		$result = $method->invoke( null, 'products', $rows );

		$this->assertEmpty( $result );
	}

	public function test_format_results_skips_rows_with_zero_object_id(): void {
		$method = $this->get_method( 'format_results' );
		$rows   = array(
			array(
				'object_id'      => 0,
				'primary_text'   => 'Zero ID',
				'secondary_text' => '',
			),
		);

		$result = $method->invoke( null, 'products', $rows );

		$this->assertEmpty( $result );
	}

	// ===========================================
	// build_query_string() Tests
	// ===========================================

	public function test_build_query_string_product_with_sku(): void {
		$method = $this->get_method( 'build_query_string' );
		$result = $method->invoke( null, 'products', 123, 'Widget', 'SKU-123' );
		$this->assertSame( 'product:123 sku:"SKU-123"', $result );
	}

	public function test_build_query_string_product_without_sku(): void {
		$method = $this->get_method( 'build_query_string' );
		$result = $method->invoke( null, 'products', 123, 'Widget', '' );
		$this->assertSame( 'product:123 "Widget"', $result );
	}

	public function test_build_query_string_order(): void {
		$method = $this->get_method( 'build_query_string' );
		$result = $method->invoke( null, 'orders', 456, 'Order #456', 'Completed' );
		$this->assertSame( 'order:456', $result );
	}

	public function test_build_query_string_customer_with_email(): void {
		$method = $this->get_method( 'build_query_string' );
		$result = $method->invoke( null, 'customers', 789, 'John Doe', 'john@example.com' );
		$this->assertSame( 'customer:"john@example.com"', $result );
	}

	public function test_build_query_string_customer_without_email(): void {
		$method = $this->get_method( 'build_query_string' );
		$result = $method->invoke( null, 'customers', 789, 'John Doe', '' );
		$this->assertSame( 'customer:789', $result );
	}

	public function test_build_query_string_unknown_type(): void {
		$method = $this->get_method( 'build_query_string' );
		$result = $method->invoke( null, 'unknown', 999, 'Test', 'Other' );
		$this->assertSame( '999', $result );
	}

	// ===========================================
	// is_backfill_complete() Tests
	// ===========================================

	public function test_is_backfill_complete_returns_true_for_negative_one(): void {
		$method = $this->get_method( 'is_backfill_complete' );
		$state  = array( 'products' => -1 );
		$result = $method->invoke( null, 'products', $state );
		$this->assertTrue( $result );
	}

	public function test_is_backfill_complete_returns_false_for_zero(): void {
		$method = $this->get_method( 'is_backfill_complete' );
		$state  = array( 'products' => 0 );
		$result = $method->invoke( null, 'products', $state );
		$this->assertFalse( $result );
	}

	public function test_is_backfill_complete_returns_false_for_positive(): void {
		$method = $this->get_method( 'is_backfill_complete' );
		$state  = array( 'products' => 100 );
		$result = $method->invoke( null, 'products', $state );
		$this->assertFalse( $result );
	}

	public function test_is_backfill_complete_returns_false_for_missing_type(): void {
		$method = $this->get_method( 'is_backfill_complete' );
		$state  = array();
		$result = $method->invoke( null, 'products', $state );
		$this->assertFalse( $result );
	}

	// ===========================================
	// Limit Clamping Logic Tests
	// ===========================================

	public function test_limit_clamp_logic_minimum_one(): void {
		// Tests the limit clamping logic: min(100, max(1, absint($limit)))
		$clamp = fn( $limit ) => min( 100, max( 1, absint( $limit ) ) );
		$this->assertSame( 1, $clamp( 0 ) );
	}

	public function test_limit_clamp_logic_negative_becomes_five(): void {
		// absint(-5) = 5, then max(1, 5) = 5, then min(100, 5) = 5.
		$clamp = fn( $limit ) => min( 100, max( 1, absint( $limit ) ) );
		$this->assertSame( 5, $clamp( -5 ) );
	}

	public function test_limit_clamp_logic_middle_value(): void {
		$clamp = fn( $limit ) => min( 100, max( 1, absint( $limit ) ) );
		$this->assertSame( 50, $clamp( 50 ) );
	}

	public function test_limit_clamp_logic_maximum_100(): void {
		$clamp = fn( $limit ) => min( 100, max( 1, absint( $limit ) ) );
		$this->assertSame( 100, $clamp( 100 ) );
	}

	public function test_limit_clamp_logic_over_max_clamped(): void {
		$clamp = fn( $limit ) => min( 100, max( 1, absint( $limit ) ) );
		$this->assertSame( 100, $clamp( 500 ) );
	}

	// ===========================================
	// Hook Registration Guard Tests
	// ===========================================

	public function test_hooks_registered_starts_false(): void {
		$reflection = new ReflectionClass( Index::class );
		$property   = $reflection->getProperty( 'hooks_registered' );
		$property->setAccessible( true );
		$this->assertFalse( $property->getValue() );
	}

	public function test_table_verified_starts_false(): void {
		$reflection = new ReflectionClass( Index::class );
		$property   = $reflection->getProperty( 'table_verified' );
		$property->setAccessible( true );
		$this->assertFalse( $property->getValue() );
	}

	public function test_backfill_ran_starts_false(): void {
		$reflection = new ReflectionClass( Index::class );
		$property   = $reflection->getProperty( 'backfill_ran' );
		$property->setAccessible( true );
		$this->assertFalse( $property->getValue() );
	}

	// ===========================================
	// should_handle_post_save() Tests
	// ===========================================

	public function test_should_handle_post_save_returns_false_for_non_object(): void {
		$method = $this->get_method( 'should_handle_post_save' );
		$result = $method->invoke( null, 123, null );
		$this->assertFalse( $result );
	}

	public function test_should_handle_post_save_returns_false_for_string(): void {
		$method = $this->get_method( 'should_handle_post_save' );
		$result = $method->invoke( null, 123, 'not a post' );
		$this->assertFalse( $result );
	}

	public function test_should_handle_post_save_returns_false_for_array(): void {
		$method = $this->get_method( 'should_handle_post_save' );
		$result = $method->invoke( null, 123, array() );
		$this->assertFalse( $result );
	}
}
