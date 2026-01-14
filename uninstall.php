<?php
/**
 * Plugin uninstall cleanup.
 *
 * @package AgentWP
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

if ( ! $wpdb ) {
	return;
}

/* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog */

$option_keys = array(
	'agentwp_settings',
	'agentwp_api_key',
	'agentwp_api_key_last4',
	'agentwp_demo_api_key',
	'agentwp_demo_api_key_last4',
	'agentwp_budget_limit',
	'agentwp_draft_ttl_minutes',
	'agentwp_usage_stats',
	'agentwp_usage_version',
	'agentwp_search_index_version',
	'agentwp_search_index_state',
	'agentwp_order_cache_version',
);

/**
 * Delete per-site AgentWP data (options, tables, transients, cron hooks).
 *
 * @param array $option_keys Option keys to delete.
 * @return void
 */
$cleanup_site = static function ( array $option_keys ) use ( $wpdb ) {
	foreach ( $option_keys as $key ) {
		delete_option( $key );
	}

	if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
		wp_clear_scheduled_hook( 'agentwp_demo_daily_reset' );
	}

	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'agentwp_bulk_process' );
	}

		$usage_table  = $wpdb->prefix . 'agentwp_usage';
		$search_table = $wpdb->prefix . 'agentwp_search_index';

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
};

if ( is_multisite() && function_exists( 'get_sites' ) && function_exists( 'switch_to_blog' ) && function_exists( 'restore_current_blog' ) ) {
	$site_ids = get_sites( array( 'fields' => 'ids' ) );
	foreach ( $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );
		$cleanup_site( $option_keys );
		restore_current_blog();
	}
} else {
	$cleanup_site( $option_keys );
}

if ( function_exists( 'delete_metadata' ) ) {
	delete_metadata( 'user', 0, 'agentwp_command_history', '', true );
	delete_metadata( 'user', 0, 'agentwp_command_favorites', '', true );
	delete_metadata( 'user', 0, 'agentwp_theme_preference', '', true );
}

if ( is_multisite() ) {
	foreach ( $option_keys as $key ) {
		delete_site_option( $key );
	}

	$transient_like = $wpdb->esc_like( 'agentwp_' ) . '%';
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s OR meta_key LIKE %s",
			"_site_transient_{$transient_like}",
			"_site_transient_timeout_{$transient_like}"
		)
	);
}

/* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog */
