<?php
/**
 * Demo reset routines.
 *
 * @package AgentWP
 */

namespace AgentWP\Demo;

use AgentWP\Plugin\SettingsManager;

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
		$iterations = 0;

		do {
			$order_ids = wc_get_orders(
				array(
					'limit'  => $batch_size,
					'page'   => 1,
					'return' => 'ids',
				)
			);

			// wc_get_orders() can return non-array on error.
			if ( ! is_array( $order_ids ) || empty( $order_ids ) ) {
				break;
			}

			foreach ( $order_ids as $order_id_raw ) {
				$order_id = self::normalize_id( $order_id_raw );
				if ( ! $order_id ) {
					continue;
				}
				if ( function_exists( 'wc_delete_order' ) ) {
					wc_delete_order( $order_id, true );
				} else {
					wp_delete_post( $order_id, true );
				}
			}

			$iterations++;
		} while ( count( $order_ids ) === $batch_size && $iterations < 1000 );
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
		$iterations = 0;

		do {
			$product_ids = wc_get_products(
				array(
					'limit'  => $batch_size,
					'page'   => 1,
					'return' => 'ids',
				)
			);

			// wc_get_products() can return non-array on error.
			if ( ! is_array( $product_ids ) || empty( $product_ids ) ) {
				break;
			}

			foreach ( $product_ids as $product_id_raw ) {
				$product_id = self::normalize_id( $product_id_raw );
				if ( ! $product_id ) {
					continue;
				}
				wp_delete_post( $product_id, true );
			}

			$iterations++;
		} while ( count( $product_ids ) === $batch_size && $iterations < 1000 );

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
		if ( ! class_exists( 'WP_Query' ) ) {
			return;
		}

		$batch_size = 100;
		$iterations = 0;

		do {
			$query = new \WP_Query(
				array(
					'post_type'        => 'shop_coupon',
					'posts_per_page'   => $batch_size,
					'paged'            => 1,
					'fields'           => 'ids',
					'no_found_rows'    => true,
					'suppress_filters' => false,
				)
			);

			$coupons = is_array( $query->posts ) ? $query->posts : array();

				if ( empty( $coupons ) ) {
					break;
				}

				foreach ( $coupons as $coupon_id_raw ) {
					$coupon_id = self::normalize_id( $coupon_id_raw );
					if ( ! $coupon_id ) {
						continue;
					}
					wp_delete_post( $coupon_id, true );
				}

			$iterations++;
		} while ( count( $coupons ) === $batch_size && $iterations < 1000 );
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
		$iterations = 0;

		do {
			$users = get_users(
				array(
					'role__in' => array( 'customer', 'subscriber' ),
					'fields'   => array( 'ID' ),
					'number'   => $batch_size,
					'paged'    => 1,
				)
			);

			if ( ! is_array( $users ) || empty( $users ) ) {
				break;
			}

			foreach ( $users as $user ) {
				$user_id = self::normalize_id( $user );

				if ( ! $user_id ) {
					continue;
				}

				wp_delete_user( $user_id );
			}

			$iterations++;
		} while ( count( $users ) === $batch_size && $iterations < 1000 );
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
		$iterations = 0;

		do {
			$comments = get_comments(
				array(
					'status' => 'all',
					'fields' => 'ids',
					'number' => $batch_size,
					'paged'  => 1,
				)
			);

			if ( ! is_array( $comments ) || empty( $comments ) ) {
				break;
			}

			foreach ( $comments as $comment_id_raw ) {
				$comment_id = self::normalize_id( $comment_id_raw );
				if ( ! $comment_id ) {
					continue;
				}
				wp_delete_comment( $comment_id, true );
			}

			$iterations++;
		} while ( count( $comments ) === $batch_size && $iterations < 1000 );
	}

	/**
	 * Normalize mixed ID values returned by WordPress and WooCommerce query APIs.
	 *
	 * @param mixed $value Raw ID value (may be scalar or object).
	 * @return int Normalized ID or 0 when unavailable.
	 */
	private static function normalize_id( $value ): int {
		if ( is_numeric( $value ) ) {
			return absint( $value );
		}

		if ( is_object( $value ) ) {
			if ( method_exists( $value, 'get_id' ) ) {
				return absint( $value->get_id() );
			}

			if ( isset( $value->ID ) ) {
				return absint( $value->ID );
			}

			if ( isset( $value->comment_ID ) ) {
				return absint( $value->comment_ID );
			}
		}

		return 0;
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

		$settings              = SettingsManager::getDefaults();
		$settings['demo_mode'] = true;

		update_option( SettingsManager::OPTION_SETTINGS, $settings, false );
		update_option( SettingsManager::OPTION_USAGE_STATS, SettingsManager::getDefaultUsageStats(), false );
		update_option( SettingsManager::OPTION_BUDGET_LIMIT, SettingsManager::DEFAULT_BUDGET_LIMIT, false );
		update_option( SettingsManager::OPTION_DRAFT_TTL, SettingsManager::DEFAULT_DRAFT_TTL, false );
		delete_option( SettingsManager::OPTION_API_KEY );
		delete_option( SettingsManager::OPTION_API_KEY_LAST4 );
	}
}
