<?php
/**
 * Uninstall helper unit tests.
 *
 * @package AgentWP\Tests\Unit\Plugin
 */

namespace AgentWP\Tests\Unit\Plugin;

use AgentWP\Plugin\Uninstall;
use AgentWP\Tests\TestCase;

/**
 * Unit tests for uninstall helpers.
 */
class UninstallTest extends TestCase {

	public function test_option_keys_include_expected_values(): void {
		$keys = Uninstall::get_option_keys();

		$expected = array(
			'agentwp_settings',
			'agentwp_api_key',
			'agentwp_api_key_last4',
			'agentwp_demo_api_key',
			'agentwp_demo_api_key_last4',
			'agentwp_budget_limit',
			'agentwp_draft_ttl_minutes',
			'agentwp_usage_stats',
			'agentwp_memory_limit',
			'agentwp_memory_ttl',
			'agentwp_usage_version',
			'agentwp_usage_purge_last_run',
			'agentwp_search_index_version',
			'agentwp_search_index_state',
			'agentwp_search_index_backfill_heartbeat',
			'agentwp_schema_version',
			'agentwp_installed_version',
			'order_cache_version',
		);

		$missing = array_diff( $expected, $keys );

		$this->assertSame( array(), $missing );
	}

	public function test_option_keys_are_unique_non_empty_strings(): void {
		$keys = Uninstall::get_option_keys();

		$this->assertSame( $keys, array_values( array_unique( $keys ) ) );

		foreach ( $keys as $key ) {
			$this->assertIsString( $key );
			$this->assertNotSame( '', $key );
		}
	}

	public function test_get_site_ids_returns_empty_when_not_multisite(): void {
		$ids = Uninstall::get_site_ids( fn() => false );

		$this->assertSame( array(), $ids );
	}

	public function test_get_site_ids_returns_empty_when_cannot_switch(): void {
		$ids = Uninstall::get_site_ids(
			fn() => true,
			fn() => array( 1, 2 ),
			fn() => false
		);

		$this->assertSame( array(), $ids );
	}

	public function test_get_site_ids_returns_site_ids_when_multisite(): void {
		$ids = Uninstall::get_site_ids(
			fn() => true,
			fn() => array( '1', 2, 3 ),
			fn() => true
		);

		$this->assertSame( array( 1, 2, 3 ), $ids );
	}
}
