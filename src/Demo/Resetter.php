<?php
/**
 * Demo reset routines.
 *
 * @package AgentWP
 */

namespace AgentWP\Demo;

use AgentWP\Plugin;

class Resetter {
	/**
	 * Reset demo data and settings.
	 *
	 * @param bool $force Run even if demo mode is disabled.
	 * @return array
	 */
	public static function run( $force = false ) {
		if ( ! $force && ! Mode::is_enabled() ) {
			return array();
		}

		self::delete_orders();
		self::delete_products();
		self::delete_coupons();
		self::delete_customers();
		self::delete_comments();
		self::reset_settings();

		return Seeder::seed_all();
	}

	/**
	 * Delete WooCommerce orders and refunds.
	 *
	 * @return void
	 */
	private static function delete_orders() {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return;
		}

		$order_ids = wc_get_orders(
			array(
				'limit'  => -1,
				'return' => 'ids',
			)
		);

		foreach ( $order_ids as $order_id ) {
			if ( function_exists( 'wc_delete_order' ) ) {
				wc_delete_order( $order_id, true );
			} else {
				wp_delete_post( $order_id, true );
			}
		}
	}

	/**
	 * Delete demo products and categories.
	 *
	 * @return void
	 */
	private static function delete_products() {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return;
		}

		$product_ids = wc_get_products(
			array(
				'limit'  => -1,
				'return' => 'ids',
			)
		);

		foreach ( $product_ids as $product_id ) {
			wp_delete_post( $product_id, true );
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);

		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				if ( 'uncategorized' === $term->slug ) {
					continue;
				}
				wp_delete_term( $term->term_id, 'product_cat' );
			}
		}
	}

	/**
	 * Delete coupons.
	 *
	 * @return void
	 */
	private static function delete_coupons() {
		$coupons = get_posts(
			array(
				'post_type'      => 'shop_coupon',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		foreach ( $coupons as $coupon_id ) {
			wp_delete_post( $coupon_id, true );
		}
	}

	/**
	 * Delete customer accounts.
	 *
	 * @return void
	 */
	private static function delete_customers() {
		$users = get_users(
			array(
				'role__in' => array( 'customer', 'subscriber' ),
				'fields'   => array( 'ID' ),
			)
		);

		foreach ( $users as $user ) {
			wp_delete_user( $user->ID );
		}
	}

	/**
	 * Delete comments and reviews.
	 *
	 * @return void
	 */
	private static function delete_comments() {
		$comments = get_comments(
			array(
				'status' => 'all',
				'fields' => 'ids',
			)
		);

		foreach ( $comments as $comment_id ) {
			wp_delete_comment( $comment_id, true );
		}
	}

	/**
	 * Reset AgentWP settings and usage stats.
	 *
	 * @return void
	 */
	private static function reset_settings() {
		if ( ! function_exists( 'update_option' ) ) {
			return;
		}

		$settings              = Plugin::get_default_settings();
		$settings['demo_mode'] = true;

		update_option( Plugin::OPTION_SETTINGS, $settings, false );
		update_option( Plugin::OPTION_USAGE_STATS, Plugin::get_default_usage_stats(), false );
		update_option( Plugin::OPTION_BUDGET_LIMIT, 0, false );
		update_option( Plugin::OPTION_DRAFT_TTL, 10, false );
		delete_option( Plugin::OPTION_API_KEY );
		delete_option( Plugin::OPTION_API_KEY_LAST4 );
	}
}
