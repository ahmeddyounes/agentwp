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
	 * Uses batched queries to prevent memory exhaustion on large datasets.
	 *
	 * @return void
	 */
	private static function delete_orders() {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return;
		}

		$batch_size = 100;
		$page       = 1;

		do {
			$order_ids = wc_get_orders(
				array(
					'limit'  => $batch_size,
					'page'   => $page,
					'return' => 'ids',
				)
			);

			// wc_get_orders() can return non-array on error.
			if ( ! is_array( $order_ids ) || empty( $order_ids ) ) {
				break;
			}

			foreach ( $order_ids as $order_id ) {
				if ( function_exists( 'wc_delete_order' ) ) {
					wc_delete_order( $order_id, true );
				} else {
					wp_delete_post( $order_id, true );
				}
			}

			// Since we're deleting items, we don't increment page -
			// the next query will get new items at page 1.
			// But set a safety limit to prevent infinite loops.
			$page++;
		} while ( count( $order_ids ) === $batch_size && $page < 1000 );
	}

	/**
	 * Delete demo products and categories.
	 *
	 * Uses batched queries to prevent memory exhaustion on large datasets.
	 *
	 * @return void
	 */
	private static function delete_products() {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return;
		}

		$batch_size = 100;
		$page       = 1;

		do {
			$product_ids = wc_get_products(
				array(
					'limit'  => $batch_size,
					'page'   => $page,
					'return' => 'ids',
				)
			);

			// wc_get_products() can return non-array on error.
			if ( ! is_array( $product_ids ) || empty( $product_ids ) ) {
				break;
			}

			foreach ( $product_ids as $product_id ) {
				wp_delete_post( $product_id, true );
			}

			// Since we're deleting items, next query will get new items.
			// Safety limit to prevent infinite loops.
			$page++;
		} while ( count( $product_ids ) === $batch_size && $page < 1000 );

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
	 * Uses batched queries to prevent memory exhaustion on large datasets.
	 *
	 * @return void
	 */
	private static function delete_coupons() {
		$batch_size = 100;
		$page       = 1;

		do {
			$coupons = get_posts(
				array(
					'post_type'      => 'shop_coupon',
					'posts_per_page' => $batch_size,
					'paged'          => $page,
					'fields'         => 'ids',
				)
			);

			if ( empty( $coupons ) ) {
				break;
			}

			foreach ( $coupons as $coupon_id ) {
				wp_delete_post( $coupon_id, true );
			}

			// Safety limit to prevent infinite loops.
			$page++;
		} while ( count( $coupons ) === $batch_size && $page < 1000 );
	}

	/**
	 * Delete customer accounts.
	 *
	 * Uses batched queries to prevent memory exhaustion on large datasets.
	 *
	 * @return void
	 */
	private static function delete_customers() {
		$batch_size = 100;
		$page       = 1;

		do {
			$users = get_users(
				array(
					'role__in' => array( 'customer', 'subscriber' ),
					'fields'   => array( 'ID' ),
					'number'   => $batch_size,
					'paged'    => $page,
				)
			);

			if ( empty( $users ) ) {
				break;
			}

			foreach ( $users as $user ) {
				wp_delete_user( $user->ID );
			}

			// Safety limit to prevent infinite loops.
			$page++;
		} while ( count( $users ) === $batch_size && $page < 1000 );
	}

	/**
	 * Delete comments and reviews.
	 *
	 * Uses batched queries to prevent memory exhaustion on large datasets.
	 *
	 * @return void
	 */
	private static function delete_comments() {
		$batch_size = 100;
		$page       = 1;

		do {
			$comments = get_comments(
				array(
					'status' => 'all',
					'fields' => 'ids',
					'number' => $batch_size,
					'paged'  => $page,
				)
			);

			if ( empty( $comments ) ) {
				break;
			}

			foreach ( $comments as $comment_id ) {
				wp_delete_comment( $comment_id, true );
			}

			// Safety limit to prevent infinite loops.
			$page++;
		} while ( count( $comments ) === $batch_size && $page < 1000 );
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
