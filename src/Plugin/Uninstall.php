<?php
/**
 * Uninstall cleanup helpers.
 *
 * @package AgentWP\Plugin
 */

namespace AgentWP\Plugin;

/**
 * Handles uninstall cleanup for single-site and multisite.
 */
final class Uninstall {

	/**
	 * Option keys to remove on uninstall.
	 *
	 * @var string[]
	 */
	public const OPTION_KEYS = array(
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
		'agentwp_order_cache_version',
	);

	/**
	 * Run uninstall cleanup.
	 *
	 * @return void
	 */
	public static function run(): void {
		global $wpdb;

		if ( ! $wpdb ) {
			return;
		}

		$option_keys = self::get_option_keys();
		$site_ids    = self::get_site_ids();

		if ( ! empty( $site_ids ) ) {
			foreach ( $site_ids as $site_id ) {
				// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog -- Required for multisite uninstall.
				switch_to_blog( (int) $site_id );
				self::cleanup_site( $option_keys );
				restore_current_blog();
			}
		} else {
			self::cleanup_site( $option_keys );
		}

		self::cleanup_user_meta();

		if ( self::is_multisite_enabled() ) {
			self::cleanup_network_options( $option_keys );
		}
	}

	/**
	 * Get option keys to remove on uninstall.
	 *
	 * @return string[]
	 */
	public static function get_option_keys(): array {
		return self::OPTION_KEYS;
	}

	/**
	 * Resolve site IDs for multisite cleanup.
	 *
	 * @param callable|null $is_multisite Optional multisite checker.
	 * @param callable|null $get_sites Optional site list fetcher.
	 * @param callable|null $can_switch Optional switch guard checker.
	 * @return int[]
	 */
	public static function get_site_ids(
		?callable $is_multisite = null,
		?callable $get_sites = null,
		?callable $can_switch = null
	): array {
		$is_multisite = $is_multisite ?? ( function_exists( 'is_multisite' ) ? 'is_multisite' : null );
		if ( ! $is_multisite || ! $is_multisite() ) {
			return array();
		}

		$get_sites = $get_sites ?? ( function_exists( 'get_sites' ) ? 'get_sites' : null );
		if ( ! $get_sites ) {
			return array();
		}

		$can_switch = $can_switch ?? array( __CLASS__, 'can_switch_blogs' );
		if ( ! $can_switch() ) {
			return array();
		}

		$site_ids = $get_sites( array( 'fields' => 'ids' ) );
		if ( ! is_array( $site_ids ) ) {
			return array();
		}

		return array_map( 'intval', $site_ids );
	}

	/**
	 * Delete per-site AgentWP data (options, tables, transients, cron hooks).
	 *
	 * @param array $option_keys Option keys to delete.
	 * @return void
	 */
	private static function cleanup_site( array $option_keys ): void {
		global $wpdb;

		if ( ! $wpdb ) {
			return;
		}

		foreach ( $option_keys as $key ) {
			delete_option( $key );
		}

		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( 'agentwp_demo_daily_reset' );
			wp_clear_scheduled_hook( 'agentwp_usage_purge' );
			wp_clear_scheduled_hook( 'agentwp_search_backfill' );
		}

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'agentwp_bulk_process' );
		}

		$usage_table  = $wpdb->prefix . 'agentwp_usage';
		$search_table = $wpdb->prefix . 'agentwp_search_index';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are derived from $wpdb->prefix.
		$wpdb->query( "DROP TABLE IF EXISTS {$usage_table}" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are derived from $wpdb->prefix.
		$wpdb->query( "DROP TABLE IF EXISTS {$search_table}" );

		$transient_like = $wpdb->esc_like( 'agentwp_' ) . '%';
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				"_transient_{$transient_like}",
				"_transient_timeout_{$transient_like}"
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
	}

	/**
	 * Remove AgentWP user meta values.
	 *
	 * @return void
	 */
	private static function cleanup_user_meta(): void {
		if ( function_exists( 'delete_metadata' ) ) {
			delete_metadata( 'user', 0, 'agentwp_command_history', '', true );
			delete_metadata( 'user', 0, 'agentwp_command_favorites', '', true );
			delete_metadata( 'user', 0, 'agentwp_theme_preference', '', true );
		}
	}

	/**
	 * Remove network-level options and transients.
	 *
	 * @param array $option_keys Option keys to delete.
	 * @return void
	 */
	private static function cleanup_network_options( array $option_keys ): void {
		global $wpdb;

		if ( ! $wpdb ) {
			return;
		}

		foreach ( $option_keys as $key ) {
			delete_site_option( $key );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$transient_like = $wpdb->esc_like( 'agentwp_' ) . '%';
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s OR meta_key LIKE %s",
				"_site_transient_{$transient_like}",
				"_site_transient_timeout_{$transient_like}"
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Check if multisite functions are available and enabled.
	 *
	 * @return bool
	 */
	private static function is_multisite_enabled(): bool {
		return function_exists( 'is_multisite' ) && is_multisite();
	}

	/**
	 * Check if blog switching functions are available.
	 *
	 * @return bool
	 */
	private static function can_switch_blogs(): bool {
		return function_exists( 'switch_to_blog' ) && function_exists( 'restore_current_blog' );
	}
}
