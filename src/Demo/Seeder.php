<?php
/**
 * Demo data seeding helpers.
 *
 * @package AgentWP
 */

namespace AgentWP\Demo;

use WC_DateTime;
use WC_Product_Simple;

class Seeder {
	const DEFAULT_CATEGORY_COUNT = 10;
	const DEFAULT_PRODUCT_COUNT  = 100;
	const DEFAULT_CUSTOMER_COUNT = 200;
	const DEFAULT_ORDER_COUNT    = 500;

	/**
	 * Seed all demo data.
	 *
	 * @param array $counts Optional counts.
	 * @return array
	 */
	public static function seed_all( array $counts = array() ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return array(
				'categories' => 0,
				'products'   => 0,
				'customers'  => 0,
				'orders'     => 0,
			);
		}

		$counts = wp_parse_args(
			$counts,
			array(
				'categories' => self::DEFAULT_CATEGORY_COUNT,
				'products'   => self::DEFAULT_PRODUCT_COUNT,
				'customers'  => self::DEFAULT_CUSTOMER_COUNT,
				'orders'     => self::DEFAULT_ORDER_COUNT,
			)
		);

		$category_ids = self::seed_categories( (int) $counts['categories'] );
		$product_ids  = self::seed_products( (int) $counts['products'], $category_ids );
		$customer_ids = self::seed_customers( (int) $counts['customers'] );
		$order_ids    = self::seed_orders( (int) $counts['orders'], $product_ids, $customer_ids );

		return array(
			'categories' => count( $category_ids ),
			'products'   => count( $product_ids ),
			'customers'  => count( $customer_ids ),
			'orders'     => count( $order_ids ),
		);
	}

	/**
	 * Seed product categories.
	 *
	 * @param int $count Number of categories.
	 * @return array
	 */
	public static function seed_categories( $count ) {
		$count = max( 1, (int) $count );
		$names = self::get_category_names();
		$ids   = array();

		for ( $index = 0; $index < $count; $index++ ) {
			$name = isset( $names[ $index ] ) ? $names[ $index ] : sprintf( 'Collection %d', $index + 1 );
			$term = term_exists( $name, 'product_cat' );
			if ( is_array( $term ) && isset( $term['term_id'] ) ) {
				$ids[] = (int) $term['term_id'];
				continue;
			}

			$result = wp_insert_term(
				$name,
				'product_cat',
				array(
					'description' => 'Demo collection',
				)
			);

			if ( is_array( $result ) && isset( $result['term_id'] ) ) {
				$ids[] = (int) $result['term_id'];
			}
		}

		return $ids;
	}

	/**
	 * Seed demo products.
	 *
	 * @param int   $count Number of products.
	 * @param array $category_ids Category IDs.
	 * @return array
	 */
	public static function seed_products( $count, array $category_ids ) {
		$count = max( 1, (int) $count );
		$ids   = array();

		for ( $index = 0; $index < $count; $index++ ) {
			$product = new WC_Product_Simple();
			$product->set_name( self::build_product_name( $index ) );
			$product->set_regular_price( (string) wp_rand( 12, 220 ) );
			$product->set_description( 'Curated demo item for AgentWP walkthroughs.' );
			$product->set_short_description( 'Demo catalog item.' );
			$product->set_status( 'publish' );
			$product->set_catalog_visibility( 'visible' );
			$product->set_stock_status( 'instock' );
			$product->set_sku( sprintf( 'demo-%03d', $index + 1 ) );
			$product->save();

			$product_id = $product->get_id();
			if ( $product_id && ! empty( $category_ids ) ) {
				$category_id = $category_ids[ $index % count( $category_ids ) ];
				wp_set_object_terms( $product_id, array( $category_id ), 'product_cat' );
			}

			$ids[] = $product_id;
		}

		// Re-index to ensure contiguous keys after filtering.
		return array_values( array_filter( $ids ) );
	}

	/**
	 * Seed demo customers.
	 *
	 * @param int $count Number of customers.
	 * @return array
	 */
	public static function seed_customers( $count ) {
		$count = max( 1, (int) $count );
		$ids   = array();

		$first_names = array( 'Avery', 'Jordan', 'Riley', 'Quinn', 'Sawyer', 'Morgan', 'Reese', 'Rowan', 'Kai', 'Ari' );
		$last_names  = array( 'Parker', 'Reed', 'Garcia', 'Lee', 'Nguyen', 'Khan', 'Brooks', 'Wright', 'Patel', 'Kim' );
		$cities      = array( 'San Francisco', 'Austin', 'Seattle', 'Denver', 'Chicago', 'Miami', 'Boston', 'Portland', 'Nashville', 'Phoenix' );

		for ( $index = 0; $index < $count; $index++ ) {
			$first = $first_names[ $index % count( $first_names ) ];
			$last  = $last_names[ $index % count( $last_names ) ];
			$login = sprintf( 'demo_customer_%03d', $index + 1 );
			$email = sprintf( 'customer%03d@example.com', $index + 1 );

			if ( username_exists( $login ) || email_exists( $email ) ) {
				$user = get_user_by( 'login', $login );
				if ( $user ) {
					$ids[] = $user->ID;
				}
				continue;
			}

			$user_id = wp_insert_user(
				array(
					'user_login' => $login,
					'user_pass'  => wp_generate_password( 12, false ),
					'user_email' => $email,
					'first_name' => $first,
					'last_name'  => $last,
					'role'       => 'customer',
				)
			);

			if ( is_wp_error( $user_id ) ) {
				continue;
			}

			$city = $cities[ $index % count( $cities ) ];
			update_user_meta( $user_id, 'billing_first_name', $first );
			update_user_meta( $user_id, 'billing_last_name', $last );
			update_user_meta( $user_id, 'billing_address_1', '100 Market St' );
			update_user_meta( $user_id, 'billing_city', $city );
			update_user_meta( $user_id, 'billing_state', 'CA' );
			update_user_meta( $user_id, 'billing_postcode', '94105' );
			update_user_meta( $user_id, 'billing_country', 'US' );
			update_user_meta( $user_id, 'billing_phone', '555-0100' );
			update_user_meta( $user_id, 'billing_email', $email );

			update_user_meta( $user_id, 'shipping_first_name', $first );
			update_user_meta( $user_id, 'shipping_last_name', $last );
			update_user_meta( $user_id, 'shipping_address_1', '100 Market St' );
			update_user_meta( $user_id, 'shipping_city', $city );
			update_user_meta( $user_id, 'shipping_state', 'CA' );
			update_user_meta( $user_id, 'shipping_postcode', '94105' );
			update_user_meta( $user_id, 'shipping_country', 'US' );

			$ids[] = $user_id;
		}

		return $ids;
	}

	/**
	 * Seed demo orders.
	 *
	 * @param int   $count Number of orders.
	 * @param array $product_ids Product IDs.
	 * @param array $customer_ids Customer IDs.
	 * @return array
	 */
	public static function seed_orders( $count, array $product_ids, array $customer_ids ) {
		$count = max( 1, (int) $count );
		if ( empty( $product_ids ) ) {
			return array();
		}

		$ids      = array();
		$timezone = wp_timezone();
		$now      = current_time( 'timestamp' );
		$start    = $now - ( 180 * DAY_IN_SECONDS );

		for ( $index = 0; $index < $count; $index++ ) {
			$customer_id = ! empty( $customer_ids ) ? $customer_ids[ wp_rand( 0, count( $customer_ids ) - 1 ) ] : 0;
			$order       = wc_create_order(
				array(
					'customer_id' => $customer_id,
				)
			);

			if ( ! $order ) {
				continue;
			}

			$item_count = wp_rand( 1, 3 );
			for ( $item_index = 0; $item_index < $item_count; $item_index++ ) {
				$product_id = $product_ids[ wp_rand( 0, count( $product_ids ) - 1 ) ];
				$product    = wc_get_product( $product_id );
				if ( $product ) {
					$order->add_product( $product, wp_rand( 1, 3 ) );
				}
			}

			$address = self::get_order_address( $customer_id );
			if ( $address ) {
				$order->set_address( $address, 'billing' );
				$order->set_address( $address, 'shipping' );
			}

			$created_at = wp_rand( $start, $now );
			$created    = new WC_DateTime( "@{$created_at}" );
			$created->setTimezone( $timezone );
			$order->set_date_created( $created );

			$status = self::pick_status();
			$order->calculate_totals();
			$order->set_status( $status );

			if ( in_array( $status, array( 'completed', 'processing' ), true ) ) {
				$paid_at = min( $now, $created_at + wp_rand( 0, 7 ) * DAY_IN_SECONDS );
				$paid    = new WC_DateTime( "@{$paid_at}" );
				$paid->setTimezone( $timezone );
				$order->set_date_paid( $paid );
			}

			if ( 'completed' === $status ) {
				$completed_at = min( $now, $created_at + wp_rand( 1, 10 ) * DAY_IN_SECONDS );
				$completed    = new WC_DateTime( "@{$completed_at}" );
				$completed->setTimezone( $timezone );
				$order->set_date_completed( $completed );
			}

			$order->save();
			$ids[] = $order->get_id();
		}

		// Re-index to ensure contiguous keys after filtering.
		return array_values( array_filter( $ids ) );
	}

	/**
	 * Build demo product name.
	 *
	 * @param int $index Index.
	 * @return string
	 */
	private static function build_product_name( $index ) {
		$adjectives = array( 'Lunar', 'Pacific', 'Velvet', 'Summit', 'Echo', 'Nova', 'Cedar', 'Signal', 'Atlas', 'Prism' );
		$nouns      = array( 'Backpack', 'Sweater', 'Desk Lamp', 'Tea Set', 'Workout Mat', 'Earbuds', 'Planner', 'Travel Mug', 'Yoga Block', 'Wireless Charger' );

		$adjective = $adjectives[ $index % count( $adjectives ) ];
		$noun      = $nouns[ $index % count( $nouns ) ];

		return sprintf( '%s %s', $adjective, $noun );
	}

	/**
	 * Get category names.
	 *
	 * @return array
	 */
	private static function get_category_names() {
		return array(
			'Apparel',
			'Home & Living',
			'Beauty',
			'Electronics',
			'Accessories',
			'Fitness',
			'Outdoors',
			'Kitchen',
			'Office',
			'Pets',
		);
	}

	/**
	 * Pick order status with weights.
	 *
	 * @return string
	 */
	private static function pick_status() {
		$choices = array(
			'completed'  => 55,
			'processing' => 20,
			'on-hold'    => 10,
			'refunded'   => 8,
			'cancelled'  => 7,
		);

		$total  = array_sum( $choices );
		$target = wp_rand( 1, $total );

		foreach ( $choices as $status => $weight ) {
			$target -= $weight;
			if ( $target <= 0 ) {
				return $status;
			}
		}

		return 'completed';
	}

	/**
	 * Resolve order address for customer.
	 *
	 * @param int $customer_id Customer ID.
	 * @return array
	 */
	private static function get_order_address( $customer_id ) {
		if ( $customer_id <= 0 ) {
			return array(
				'first_name' => 'Demo',
				'last_name'  => 'Customer',
				'email'      => 'customer@example.com',
				'phone'      => '555-0100',
				'address_1'  => '100 Market St',
				'city'       => 'San Francisco',
				'state'      => 'CA',
				'postcode'   => '94105',
				'country'    => 'US',
			);
		}

		return array(
			'first_name' => get_user_meta( $customer_id, 'billing_first_name', true ),
			'last_name'  => get_user_meta( $customer_id, 'billing_last_name', true ),
			'email'      => get_user_meta( $customer_id, 'billing_email', true ),
			'phone'      => get_user_meta( $customer_id, 'billing_phone', true ),
			'address_1'  => get_user_meta( $customer_id, 'billing_address_1', true ),
			'city'       => get_user_meta( $customer_id, 'billing_city', true ),
			'state'      => get_user_meta( $customer_id, 'billing_state', true ),
			'postcode'   => get_user_meta( $customer_id, 'billing_postcode', true ),
			'country'    => get_user_meta( $customer_id, 'billing_country', true ),
		);
	}
}
