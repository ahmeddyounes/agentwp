<?php
/**
 * Centralized schema management for database tables.
 *
 * @package AgentWP\Plugin
 */

namespace AgentWP\Plugin;

use AgentWP\Billing\UsageTracker;
use AgentWP\Search\Index;

/**
 * Manages database schema creation and upgrades.
 *
 * This class provides a single entry point for all schema operations,
 * ensuring tables are created/upgraded consistently during:
 * - First-time plugin activation (via activate())
 * - Plugin upgrades (via Upgrader steps)
 * - Runtime fallback (via ensure_tables())
 *
 * Individual table classes (UsageTracker, Index) retain their ensure_table()
 * methods for runtime safety, but SchemaManager is the canonical source
 * for upgrade operations.
 */
class SchemaManager {

	/**
	 * Option key for tracking schema version.
	 */
	const OPTION_SCHEMA_VERSION = 'agentwp_schema_version';

	/**
	 * Current schema version.
	 *
	 * Bump this when adding new tables or modifying existing schemas.
	 * Format: MAJOR.MINOR where MAJOR indicates breaking changes.
	 */
	const SCHEMA_VERSION = '1.0';

	/**
	 * Create all tables for first-time installation.
	 *
	 * Called during plugin activation for new installations.
	 * This is idempotent and safe to call multiple times.
	 *
	 * @return bool True if all tables were created successfully.
	 */
	public static function create_tables(): bool {
		$success = true;

		// Create usage tracking table and schedule purge cron.
		if ( class_exists( 'AgentWP\\Billing\\UsageTracker' ) ) {
			if ( ! UsageTracker::ensure_table() ) {
				$success = false;
			}
			UsageTracker::schedule_purge();
		}

		// Create search index table.
		if ( class_exists( 'AgentWP\\Search\\Index' ) ) {
			Index::ensure_table();
		}

		if ( $success ) {
			self::update_schema_version( self::SCHEMA_VERSION );
		}

		return $success;
	}

	/**
	 * Ensure all tables exist (runtime safety check).
	 *
	 * This method is called during normal plugin operation to ensure
	 * tables exist even if activation didn't run properly.
	 * Each table class has its own version check for efficiency.
	 *
	 * @return void
	 */
	public static function ensure_tables(): void {
		if ( class_exists( 'AgentWP\\Billing\\UsageTracker' ) ) {
			UsageTracker::ensure_table();
		}

		if ( class_exists( 'AgentWP\\Search\\Index' ) ) {
			Index::ensure_table();
		}
	}

	/**
	 * Run schema migrations for a specific target version.
	 *
	 * This method is called by Upgrader steps when the schema needs
	 * to be updated. Each migration is version-gated and idempotent.
	 *
	 * @param string $from_version Version upgrading from.
	 * @param string $to_version   Version upgrading to.
	 * @return bool True if migrations completed successfully.
	 */
	public static function migrate( string $from_version, string $to_version ): bool {
		unset( $from_version, $to_version );

		$current_schema = self::get_schema_version();

		// If schema is already at or beyond target, skip.
		if ( '' !== $current_schema && version_compare( $current_schema, self::SCHEMA_VERSION, '>=' ) ) {
			return true;
		}

		// Run table creation/updates.
		$success = self::create_tables();

		return $success;
	}

	/**
	 * Get the current schema version from the database.
	 *
	 * @return string Version string or empty if not set.
	 */
	public static function get_schema_version(): string {
		return (string) get_option( self::OPTION_SCHEMA_VERSION, '' );
	}

	/**
	 * Update the schema version in the database.
	 *
	 * @param string $version Version to set.
	 * @return bool True on success.
	 */
	public static function update_schema_version( string $version ): bool {
		return update_option( self::OPTION_SCHEMA_VERSION, $version, false );
	}

	/**
	 * Check if schema needs upgrading.
	 *
	 * @return bool True if current schema is older than target.
	 */
	public static function needs_upgrade(): bool {
		$current = self::get_schema_version();

		if ( '' === $current ) {
			return true;
		}

		return version_compare( $current, self::SCHEMA_VERSION, '<' );
	}

	/**
	 * Get list of all managed tables.
	 *
	 * @return array<string, string> Map of table key => full table name.
	 */
	public static function get_tables(): array {
		global $wpdb;

		return array(
			'usage'        => $wpdb->prefix . UsageTracker::TABLE,
			'search_index' => $wpdb->prefix . Index::TABLE,
		);
	}

	/**
	 * Check if a specific table exists.
	 *
	 * @param string $table_name Full table name.
	 * @return bool True if table exists.
	 */
	public static function table_exists( string $table_name ): bool {
		global $wpdb;

		$like = $wpdb->esc_like( $table_name );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check.
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
	}
}
