<?php
/**
 * Demo WP-CLI commands.
 *
 * @package AgentWP
 */

namespace AgentWP\Demo;

use AgentWP\Plugin\SettingsManager;
use WP_CLI;

class CLI {
	/**
	 * Register WP-CLI commands.
	 *
	 * @return void
	 */
	public static function register() {
		WP_CLI::add_command( 'agentwp demo seed', array( __CLASS__, 'seed' ) );
		WP_CLI::add_command( 'agentwp demo reset', array( __CLASS__, 'reset' ) );
		WP_CLI::add_command( 'agentwp demo enable', array( __CLASS__, 'enable' ) );
		WP_CLI::add_command( 'agentwp demo set-key', array( __CLASS__, 'set_key' ) );
	}

	/**
	 * Seed demo data.
	 *
	 * ## OPTIONS
	 *
	 * [--products=<count>]
	 * : Number of products to create.
	 *
	 * [--categories=<count>]
	 * : Number of categories to create.
	 *
	 * [--customers=<count>]
	 * : Number of customers to create.
	 *
	 * [--orders=<count>]
	 * : Number of orders to create.
	 *
	 * @param array $args Arguments.
	 * @param array $assoc_args Assoc args.
	 * @return void
	 */
	public static function seed( $args, $assoc_args ) {
		unset( $args );

		$counts = array(
			'products'   => isset( $assoc_args['products'] ) ? (int) $assoc_args['products'] : Seeder::DEFAULT_PRODUCT_COUNT,
			'categories' => isset( $assoc_args['categories'] ) ? (int) $assoc_args['categories'] : Seeder::DEFAULT_CATEGORY_COUNT,
			'customers'  => isset( $assoc_args['customers'] ) ? (int) $assoc_args['customers'] : Seeder::DEFAULT_CUSTOMER_COUNT,
			'orders'     => isset( $assoc_args['orders'] ) ? (int) $assoc_args['orders'] : Seeder::DEFAULT_ORDER_COUNT,
		);

		$results = Seeder::seed_all( $counts );
		WP_CLI::success( sprintf( 'Seeded %d products, %d categories, %d customers, %d orders.', $results['products'], $results['categories'], $results['customers'], $results['orders'] ) );
	}

	/**
	 * Reset demo data.
	 *
	 * @param array $args Arguments.
	 * @param array $assoc_args Assoc args.
	 * @return void
	 */
	public static function reset( $args, $assoc_args ) {
		unset( $args, $assoc_args );

		$results = Resetter::run( true );
		WP_CLI::success( 'Demo data reset complete.' );
		if ( ! empty( $results ) ) {
			WP_CLI::log(
				sprintf(
					'Seeded %d products, %d categories, %d customers, %d orders.',
					$results['products'],
					$results['categories'],
					$results['customers'],
					$results['orders']
				)
			);
		}
	}

	/**
	 * Enable demo mode setting.
	 *
	 * @param array $args Arguments.
	 * @param array $assoc_args Assoc args.
	 * @return void
	 */
	public static function enable( $args, $assoc_args ) {
		unset( $args, $assoc_args );

		$settings              = get_option( SettingsManager::OPTION_SETTINGS, array() );
		$settings              = is_array( $settings ) ? $settings : array();
		$settings              = wp_parse_args( $settings, SettingsManager::getDefaults() );
		$settings['demo_mode'] = true;
		update_option( SettingsManager::OPTION_SETTINGS, $settings, false );
		Manager::schedule_reset();

		WP_CLI::success( 'Demo mode enabled.' );
	}

	/**
	 * Store demo API key.
	 *
	 * ## OPTIONS
	 *
	 * --key=<key>
	 * : Demo API key to store.
	 *
	 * @param array $args Arguments.
	 * @param array $assoc_args Assoc args.
	 * @return void
	 */
	public static function set_key( $args, $assoc_args ) {
		$key = isset( $assoc_args['key'] ) ? (string) $assoc_args['key'] : '';
		if ( '' === $key ) {
			WP_CLI::error( 'Missing --key.' );
		}

		if ( ! Mode::store_demo_api_key( $key ) ) {
			WP_CLI::error( 'Unable to store demo API key.' );
		}

		WP_CLI::success( 'Demo API key stored.' );
	}
}
