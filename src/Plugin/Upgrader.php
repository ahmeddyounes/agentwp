<?php
/**
 * Plugin upgrader for version-based migrations.
 *
 * @package AgentWP\Plugin
 */

namespace AgentWP\Plugin;

/**
 * Manages plugin upgrades and version-based migrations.
 *
 * Runs upgrade steps when the installed version differs from the current version.
 * All upgrade steps are idempotent and safe to re-run.
 *
 * Usage:
 * - Call Upgrader::init() early in boot (plugins_loaded hook)
 * - Add new upgrade steps in get_upgrade_steps() when needed
 * - Each step runs only once when upgrading from a lower version
 */
class Upgrader {

	/**
	 * Option key for storing the installed version.
	 */
	const OPTION_INSTALLED_VERSION = 'agentwp_installed_version';

	/**
	 * Track if upgrader has run this request.
	 *
	 * @var bool
	 */
	private static bool $has_run = false;

	/**
	 * Initialize the upgrader.
	 *
	 * Should be called early in the plugin boot process (plugins_loaded).
	 * Checks if the installed version differs from current and runs upgrades.
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( self::$has_run ) {
			return;
		}

		self::$has_run = true;
		self::maybe_upgrade();
	}

	/**
	 * Run upgrades if needed.
	 *
	 * Compares installed version with current version and runs any pending
	 * upgrade steps. Updates the installed version after successful completion.
	 *
	 * @return bool True if upgrades were run, false if skipped.
	 */
	public static function maybe_upgrade(): bool {
		$installed_version = self::get_installed_version();
		$current_version   = self::get_current_version();

		// Fresh install or already up to date.
		if ( '' === $installed_version ) {
			// New installation - set version without running upgrades.
			self::update_installed_version( $current_version );
			return false;
		}

		if ( version_compare( $installed_version, $current_version, '>=' ) ) {
			return false;
		}

		// Run upgrade steps.
		self::run_upgrades( $installed_version, $current_version );

		// Update installed version after successful upgrade.
		self::update_installed_version( $current_version );

		/**
		 * Fires after plugin upgrades have completed.
		 *
		 * @param string $installed_version The previous installed version.
		 * @param string $current_version   The new current version.
		 */
		do_action( 'agentwp_upgraded', $installed_version, $current_version );

		return true;
	}

	/**
	 * Get the currently installed version.
	 *
	 * @return string Version string or empty if not set.
	 */
	public static function get_installed_version(): string {
		return (string) get_option( self::OPTION_INSTALLED_VERSION, '' );
	}

	/**
	 * Get the current plugin version from constant.
	 *
	 * @return string Current version string.
	 */
	public static function get_current_version(): string {
		return defined( 'AGENTWP_VERSION' ) ? AGENTWP_VERSION : '0.0.0';
	}

	/**
	 * Update the installed version option.
	 *
	 * @param string $version Version to set.
	 * @return bool True on success.
	 */
	public static function update_installed_version( string $version ): bool {
		return update_option( self::OPTION_INSTALLED_VERSION, $version, false );
	}

	/**
	 * Run upgrade steps from one version to another.
	 *
	 * Iterates through defined upgrade steps and runs any that apply
	 * to the version range being upgraded.
	 *
	 * @param string $from_version Starting version.
	 * @param string $to_version   Target version.
	 * @return void
	 */
	private static function run_upgrades( string $from_version, string $to_version ): void {
		$steps = self::get_upgrade_steps();

		foreach ( $steps as $step_version => $callback ) {
			// Skip steps already applied (version <= installed).
			if ( version_compare( $step_version, $from_version, '<=' ) ) {
				continue;
			}

			// Skip steps beyond target version.
			if ( version_compare( $step_version, $to_version, '>' ) ) {
				continue;
			}

			// Run the upgrade step.
			if ( is_callable( $callback ) ) {
				/**
				 * Fires before an individual upgrade step runs.
				 *
				 * @param string $step_version  The version this step upgrades to.
				 * @param string $from_version  The version being upgraded from.
				 */
				do_action( 'agentwp_before_upgrade_step', $step_version, $from_version );

				call_user_func( $callback );

				/**
				 * Fires after an individual upgrade step completes.
				 *
				 * @param string $step_version  The version this step upgraded to.
				 * @param string $from_version  The version being upgraded from.
				 */
				do_action( 'agentwp_after_upgrade_step', $step_version, $from_version );
			}
		}
	}

	/**
	 * Get all defined upgrade steps.
	 *
	 * Each key is a version number, and the value is a callable that performs
	 * the upgrade. Steps are processed in version order.
	 *
	 * When adding new upgrade steps:
	 * 1. Add a new entry with the target version as key
	 * 2. Implement the upgrade logic in a static method
	 * 3. Ensure the step is idempotent (safe to run multiple times)
	 *
	 * Example:
	 *   '0.2.0' => array( __CLASS__, 'upgrade_to_0_2_0' ),
	 *
	 * @return array<string, callable> Version => callback pairs.
	 */
	private static function get_upgrade_steps(): array {
		$steps = array(
			// Example upgrade step (commented out as placeholder):
			// '0.2.0' => array( __CLASS__, 'upgrade_to_0_2_0' ),
		);

		// Sort by version to ensure correct execution order.
		uksort( $steps, 'version_compare' );

		return $steps;
	}

	/**
	 * Reset the upgrader state for testing.
	 *
	 * @internal For testing purposes only.
	 * @return void
	 */
	public static function reset(): void {
		self::$has_run = false;
	}

	/**
	 * Check if an upgrade is needed.
	 *
	 * @return bool True if installed version is lower than current.
	 */
	public static function needs_upgrade(): bool {
		$installed = self::get_installed_version();
		$current   = self::get_current_version();

		if ( '' === $installed ) {
			return false; // Fresh install, not an upgrade.
		}

		return version_compare( $installed, $current, '<' );
	}

	/**
	 * Get upgrade step versions that would run for a given version range.
	 *
	 * Useful for testing and debugging upgrade paths.
	 *
	 * @param string $from_version Starting version.
	 * @param string $to_version   Target version.
	 * @return array List of version strings for steps that would run.
	 */
	public static function get_pending_steps( string $from_version, string $to_version ): array {
		$steps   = self::get_upgrade_steps();
		$pending = array();

		foreach ( array_keys( $steps ) as $step_version ) {
			if ( version_compare( $step_version, $from_version, '<=' ) ) {
				continue;
			}

			if ( version_compare( $step_version, $to_version, '>' ) ) {
				continue;
			}

			$pending[] = $step_version;
		}

		return $pending;
	}
}
