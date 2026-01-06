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

delete_option( 'agentwp_settings' );
delete_option( 'agentwp_api_key' );
delete_option( 'agentwp_api_key_last4' );
delete_option( 'agentwp_budget_limit' );
delete_option( 'agentwp_draft_ttl_minutes' );
delete_option( 'agentwp_usage_stats' );

if ( is_multisite() ) {
	delete_site_option( 'agentwp_settings' );
	delete_site_option( 'agentwp_api_key' );
	delete_site_option( 'agentwp_api_key_last4' );
	delete_site_option( 'agentwp_budget_limit' );
	delete_site_option( 'agentwp_draft_ttl_minutes' );
	delete_site_option( 'agentwp_usage_stats' );
}

$transient_like = $wpdb->esc_like( 'agentwp_' ) . '%';

$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		"_transient_{$transient_like}",
		"_transient_timeout_{$transient_like}"
	)
);

if ( is_multisite() ) {
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s OR meta_key LIKE %s",
			"_site_transient_{$transient_like}",
			"_site_transient_timeout_{$transient_like}"
		)
	);
}
