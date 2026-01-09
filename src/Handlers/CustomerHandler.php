<?php
/**
 * Handle customer profile requests.
 *
 * @package AgentWP
 */

namespace AgentWP\Handlers;

use AgentWP\AI\Response;

class CustomerHandler {
	const RECENT_LIMIT = 5;
	const TOP_LIMIT    = 5;
	const ORDER_BATCH  = 200;
	const MAX_ORDER_IDS = 2000;
	const PRODUCT_CATEGORY_CACHE_GROUP = 'agentwp_product_categories';
	const PRODUCT_CATEGORY_CACHE_TTL   = 3600;

	/**
	 * Handle a customer profile request.
	 *
	 * @param array $args Request arguments.
	 * @return Response
	 */
	public function handle( array $args ): Response {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return Response::error( 'WooCommerce is required to fetch customer profiles.', 400 );
		}

		$normalized = $this->normalize_args( $args );
		if ( 0 === $normalized['customer_id'] && '' === $normalized['email'] ) {
			return Response::error( 'Provide a customer ID or email address.', 400 );
		}

		$paid_statuses = $this->get_paid_statuses();
		$order_data    = $this->collect_order_ids( $normalized, $paid_statuses );
		$order_ids     = $order_data['ids'];
		$metrics       = $this->build_metrics( $order_ids );
		$recent_orders = $this->get_recent_orders( $normalized, $paid_statuses );

		$payload = array_merge(
			$metrics,
			array(
				'customer'          => $this->build_customer_summary( $normalized, $recent_orders ),
				'health_thresholds' => $this->get_health_thresholds(),
				'included_statuses' => $paid_statuses,
				'recent_orders'     => $recent_orders,
				'orders_truncated'  => $order_data['truncated'],
				'orders_sampled'    => count( $order_ids ),
				'orders_limit'      => self::MAX_ORDER_IDS,
			)
		);

		return Response::success( $payload );
	}

	/**
	 * @param array $args Request args.
	 * @return array
	 */
	private function normalize_args( array $args ) {
		$email       = isset( $args['email'] ) ? sanitize_email( $args['email'] ) : '';
		$customer_id = isset( $args['customer_id'] ) ? absint( $args['customer_id'] ) : 0;

		if ( '' === $email && $customer_id > 0 && function_exists( 'get_userdata' ) ) {
			$user = get_userdata( $customer_id );
			if ( $user && isset( $user->user_email ) ) {
				$email = sanitize_email( $user->user_email );
			}
		}

		return array(
			'email'       => $email,
			'customer_id' => $customer_id,
		);
	}

	/**
	 * @param array $normalized Normalized request args.
	 * @param array $statuses Paid statuses.
	 * @return array
	 */
	private function collect_order_ids( array $normalized, array $statuses ) {
		$order_ids = array();
		$truncated = false;
		$remaining = self::MAX_ORDER_IDS;

		if ( $normalized['customer_id'] > 0 ) {
			$order_ids = array_merge(
				$order_ids,
				$this->query_order_ids(
					array(
						'customer' => $normalized['customer_id'],
						'status'   => $statuses,
					),
					$remaining,
					$truncated
				)
			);
			$remaining = max( 0, self::MAX_ORDER_IDS - count( $order_ids ) );
		}

		if ( '' !== $normalized['email'] && 0 === $normalized['customer_id'] && $remaining > 0 ) {
			$order_ids = array_merge(
				$order_ids,
				$this->query_order_ids(
					array(
						'billing_email' => $normalized['email'],
						'status'        => $statuses,
					),
					$remaining,
					$truncated
				)
			);
		}

		$order_ids = array_map( 'absint', $order_ids );
		$order_ids = array_filter( array_unique( $order_ids ) );
		sort( $order_ids );

		if ( count( $order_ids ) > self::MAX_ORDER_IDS ) {
			$order_ids = array_slice( $order_ids, 0, self::MAX_ORDER_IDS );
			$truncated = true;
		}

		return array(
			'ids'       => $order_ids,
			'truncated' => $truncated,
		);
	}

	/**
	 * @param array $query_args Query arguments.
	 * @return array
	 */
	private function query_order_ids( array $query_args, $remaining, &$truncated ) {
		$ids   = array();
		$page  = 1;
		$limit = min( self::ORDER_BATCH, $remaining );
		if ( $limit <= 0 ) {
			$truncated = true;
			return $ids;
		}

		while ( true ) {
			$args = array_merge(
				$query_args,
				array(
					'limit'  => $limit,
					'paged'  => $page,
					'return' => 'ids',
					'orderby' => 'date',
					'order'   => 'DESC',
				)
			);

			$batch = wc_get_orders( $args );
			if ( ! is_array( $batch ) || empty( $batch ) ) {
				break;
			}

			$ids = array_merge( $ids, $batch );

			if ( count( $batch ) < $limit ) {
				break;
			}

			if ( count( $ids ) >= $remaining ) {
				$ids       = array_slice( $ids, 0, $remaining );
				$truncated = true;
				break;
			}

			$page++;
		}

		return $ids;
	}

	/**
	 * Batch load orders to avoid N+1 queries.
	 *
	 * @param array $order_ids Order IDs to load.
	 * @return array Array of order objects.
	 */
	private function batch_load_orders( array $order_ids ) {
		if ( empty( $order_ids ) || ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$orders = array();
		$chunks = array_chunk( $order_ids, self::ORDER_BATCH );

		foreach ( $chunks as $chunk ) {
			$batch = wc_get_orders(
				array(
					'include' => $chunk,
					'limit'   => count( $chunk ),
					'orderby' => 'none',
				)
			);

			if ( is_array( $batch ) ) {
				$orders = array_merge( $orders, $batch );
			}
		}

		return $orders;
	}

	/**
	 * @param array $order_ids Order IDs to summarize.
	 * @return array
	 */
	private function build_metrics( array $order_ids ) {
		$total_orders = count( $order_ids );
		$total_spent  = 0.0;
		$first_ts     = null;
		$last_ts      = null;
		$first_date   = '';
		$last_date    = '';

		$product_totals  = array();
		$category_totals = array();
		$category_cache  = array();

		// Batch load orders to avoid N+1 queries.
		$orders = $this->batch_load_orders( $order_ids );

		foreach ( $orders as $order ) {
			if ( ! $order || ! method_exists( $order, 'get_total' ) ) {
				continue;
			}

			$total_spent += $this->normalize_amount( $order->get_total() );

			$date_created = method_exists( $order, 'get_date_created' ) ? $order->get_date_created() : null;
			if ( $date_created && method_exists( $date_created, 'getTimestamp' ) ) {
				$timestamp = $date_created->getTimestamp();
				if ( null === $first_ts || $timestamp < $first_ts ) {
					$first_ts   = $timestamp;
					$first_date = $this->format_datetime( $date_created );
				}

				if ( null === $last_ts || $timestamp > $last_ts ) {
					$last_ts   = $timestamp;
					$last_date = $this->format_datetime( $date_created );
				}
			}

			$this->accumulate_item_totals( $order, $product_totals, $category_totals, $category_cache );
		}

		$average_order_value = 0.0;
		if ( $total_orders > 0 ) {
			$average_order_value = $this->normalize_amount( $total_spent / $total_orders );
		}

		$days_since_last_order = $this->calculate_days_since( $last_ts );
		$health_status         = $this->determine_health_status( $days_since_last_order, $total_orders );
		$ltv_projection         = $this->calculate_ltv_projection( $total_orders, $average_order_value, $first_ts );

		return array(
			'total_orders'                  => $total_orders,
			'total_spent'                   => $this->normalize_amount( $total_spent ),
			'total_spent_formatted'         => $this->format_currency( $total_spent ),
			'average_order_value'           => $average_order_value,
			'average_order_value_formatted' => $this->format_currency( $average_order_value ),
			'first_order_date'              => $first_date,
			'last_order_date'               => $last_date,
			'days_since_last_order'         => $days_since_last_order,
			'favorite_products'             => $this->format_top_products( $product_totals ),
			'favorite_categories'           => $this->format_top_categories( $category_totals ),
			'estimated_ltv'                 => $ltv_projection['estimated_ltv'],
			'estimated_ltv_formatted'       => $this->format_currency( $ltv_projection['estimated_ltv'] ),
			'ltv_projection'                => $ltv_projection['projection'],
			'health_status'                 => $health_status,
		);
	}

	/**
	 * @param array $normalized Normalized args.
	 * @param array $recent_orders Recent order summaries.
	 * @return array
	 */
	private function build_customer_summary( array $normalized, array $recent_orders ) {
		$name  = '';
		$email = $normalized['email'];

		if ( $normalized['customer_id'] > 0 && function_exists( 'get_userdata' ) ) {
			$user = get_userdata( $normalized['customer_id'] );
			if ( $user ) {
				$first = isset( $user->first_name ) ? $user->first_name : '';
				$last  = isset( $user->last_name ) ? $user->last_name : '';
				$name  = trim( $first . ' ' . $last );
				if ( '' === $name && isset( $user->display_name ) ) {
					$name = (string) $user->display_name;
				}
				if ( '' === $email && isset( $user->user_email ) ) {
					$email = sanitize_email( $user->user_email );
				}
			}
		}

		if ( '' === $name && ! empty( $recent_orders ) && isset( $recent_orders[0]['customer_name'] ) ) {
			$name = sanitize_text_field( $recent_orders[0]['customer_name'] );
		}

		return array(
			'customer_id' => $normalized['customer_id'],
			'email'       => $email,
			'name'        => $name,
			'is_guest'    => 0 === $normalized['customer_id'],
		);
	}

	/**
	 * @param array $normalized Normalized args.
	 * @param array $statuses Status list.
	 * @return array
	 */
	private function get_recent_orders( array $normalized, array $statuses ) {
		if ( ! function_exists( 'wc_get_orders' ) || ! function_exists( 'wc_get_order' ) ) {
			return array();
		}

		$args = array(
			'limit'   => self::RECENT_LIMIT,
			'orderby' => 'date',
			'order'   => 'DESC',
			'status'  => $statuses,
			'return'  => 'ids',
		);

		if ( $normalized['customer_id'] > 0 ) {
			$args['customer'] = $normalized['customer_id'];
		} elseif ( '' !== $normalized['email'] ) {
			$args['billing_email'] = $normalized['email'];
		}

		$order_ids = wc_get_orders( $args );
		if ( ! is_array( $order_ids ) ) {
			return array();
		}

		$recent = array();
		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order || ! method_exists( $order, 'get_id' ) ) {
				continue;
			}

			$recent[] = $this->format_order_summary( $order );
		}

		return $recent;
	}

	/**
	 * @param object $order Order instance.
	 * @return array
	 */
	private function format_order_summary( $order ) {
		$date_created = method_exists( $order, 'get_date_created' ) ? $order->get_date_created() : null;
		$total        = method_exists( $order, 'get_total' ) ? $this->normalize_amount( $order->get_total() ) : 0.0;

		return array(
			'id'              => intval( $order->get_id() ),
			'status'          => sanitize_text_field( $order->get_status() ),
			'total'           => $total,
			'total_formatted' => $this->format_currency( $total ),
			'currency'        => sanitize_text_field( $order->get_currency() ),
			'date_created'    => $this->format_datetime( $date_created ),
			'customer_name'   => $this->get_customer_name( $order ),
			'customer_email'  => $this->get_customer_email( $order ),
			'items_summary'   => $this->format_items_summary( $order ),
		);
	}

	/**
	 * @param object $order Order instance.
	 * @return string
	 */
	private function get_customer_name( $order ) {
		$first = method_exists( $order, 'get_billing_first_name' ) ? $order->get_billing_first_name() : '';
		$last  = method_exists( $order, 'get_billing_last_name' ) ? $order->get_billing_last_name() : '';
		$name  = trim( $first . ' ' . $last );

		if ( '' === $name && method_exists( $order, 'get_shipping_first_name' ) ) {
			$first = $order->get_shipping_first_name();
			$last  = method_exists( $order, 'get_shipping_last_name' ) ? $order->get_shipping_last_name() : '';
			$name  = trim( $first . ' ' . $last );
		}

		return sanitize_text_field( $name );
	}

	/**
	 * @param object $order Order instance.
	 * @return string
	 */
	private function get_customer_email( $order ) {
		$email = method_exists( $order, 'get_billing_email' ) ? $order->get_billing_email() : '';
		if ( '' === $email && method_exists( $order, 'get_meta' ) ) {
			$email = $order->get_meta( '_shipping_email' );
		}

		return sanitize_email( $email );
	}

	/**
	 * @param object $order Order instance.
	 * @return array
	 */
	private function format_items_summary( $order ) {
		if ( ! method_exists( $order, 'get_items' ) ) {
			return array();
		}

		$items = $order->get_items();
		if ( ! is_array( $items ) ) {
			return array();
		}

		$summary = array();
		foreach ( $items as $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_name' ) ) {
				continue;
			}

			$name = sanitize_text_field( $item->get_name() );
			$qty  = method_exists( $item, 'get_quantity' ) ? intval( $item->get_quantity() ) : 1;

			if ( '' === $name ) {
				continue;
			}

			$summary[] = array(
				'name'     => $name,
				'quantity' => $qty,
			);
		}

		return $summary;
	}

	/**
	 * @param object $order Order instance.
	 * @param array  $product_totals Product totals accumulator.
	 * @param array  $category_totals Category totals accumulator.
	 * @param array  $category_cache Category cache.
	 * @return void
	 */
	private function accumulate_item_totals( $order, array &$product_totals, array &$category_totals, array &$category_cache ) {
		if ( ! method_exists( $order, 'get_items' ) ) {
			return;
		}

		$items = $order->get_items( 'line_item' );
		if ( ! is_array( $items ) ) {
			return;
		}

		foreach ( $items as $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_product_id' ) ) {
				continue;
			}

			$product_id = absint( $item->get_product_id() );
			if ( 0 === $product_id ) {
				continue;
			}

			$quantity = method_exists( $item, 'get_quantity' ) ? intval( $item->get_quantity() ) : 1;
			if ( $quantity < 1 ) {
				continue;
			}

			if ( ! isset( $product_totals[ $product_id ] ) ) {
				$product_totals[ $product_id ] = array(
					'product_id' => $product_id,
					'name'       => sanitize_text_field( $item->get_name() ),
					'quantity'   => 0,
				);
			}

			$product_totals[ $product_id ]['quantity'] += $quantity;

			$categories = $this->get_product_categories( $product_id, $category_cache );
			foreach ( $categories as $term_id => $name ) {
				if ( ! isset( $category_totals[ $term_id ] ) ) {
					$category_totals[ $term_id ] = array(
						'category_id' => $term_id,
						'name'        => $name,
						'quantity'    => 0,
					);
				}

				$category_totals[ $term_id ]['quantity'] += $quantity;
			}
		}
	}

	/**
	 * @param int   $product_id Product ID.
	 * @param array $cache Category cache.
	 * @return array
	 */
	private function get_product_categories( $product_id, array &$cache ) {
		$product_id = absint( $product_id );
		if ( 0 === $product_id ) {
			return array();
		}

		if ( isset( $cache[ $product_id ] ) ) {
			return $cache[ $product_id ];
		}

		if ( function_exists( 'wp_cache_get' ) ) {
			$cached = wp_cache_get( $product_id, self::PRODUCT_CATEGORY_CACHE_GROUP );
			if ( is_array( $cached ) ) {
				$cache[ $product_id ] = $cached;
				return $cached;
			}
		}

		$categories = array();
		if ( function_exists( 'wc_get_product_terms' ) ) {
			$terms = wc_get_product_terms( $product_id, 'product_cat', array( 'fields' => 'all' ) );
			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					if ( ! is_object( $term ) || ! isset( $term->term_id ) ) {
						continue;
					}
					$categories[ (int) $term->term_id ] = sanitize_text_field( $term->name );
				}
			}
		}

		$cache[ $product_id ] = $categories;
		if ( function_exists( 'wp_cache_set' ) ) {
			wp_cache_set( $product_id, $categories, self::PRODUCT_CATEGORY_CACHE_GROUP, self::PRODUCT_CATEGORY_CACHE_TTL );
		}

		return $categories;
	}

	/**
	 * @param array $totals Product totals.
	 * @return array
	 */
	private function format_top_products( array $totals ) {
		if ( empty( $totals ) ) {
			return array();
		}

		usort(
			$totals,
			function ( $left, $right ) {
				$left_qty  = isset( $left['quantity'] ) ? (int) $left['quantity'] : 0;
				$right_qty = isset( $right['quantity'] ) ? (int) $right['quantity'] : 0;
				if ( $left_qty === $right_qty ) {
					return 0;
				}

				return ( $left_qty > $right_qty ) ? -1 : 1;
			}
		);

		return array_slice( array_values( $totals ), 0, self::TOP_LIMIT );
	}

	/**
	 * @param array $totals Category totals.
	 * @return array
	 */
	private function format_top_categories( array $totals ) {
		if ( empty( $totals ) ) {
			return array();
		}

		usort(
			$totals,
			function ( $left, $right ) {
				$left_qty  = isset( $left['quantity'] ) ? (int) $left['quantity'] : 0;
				$right_qty = isset( $right['quantity'] ) ? (int) $right['quantity'] : 0;
				if ( $left_qty === $right_qty ) {
					return 0;
				}

				return ( $left_qty > $right_qty ) ? -1 : 1;
			}
		);

		return array_slice( array_values( $totals ), 0, self::TOP_LIMIT );
	}

	/**
	 * @param int|null $last_ts Last order timestamp.
	 * @return int|null
	 */
	private function calculate_days_since( $last_ts ) {
		if ( null === $last_ts ) {
			return null;
		}

		$now_ts  = $this->get_now_timestamp();
		$diff    = max( 0, $now_ts - $last_ts );
		$seconds = defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400;

		return (int) floor( $diff / $seconds );
	}

	/**
	 * Simple 12-month projection based on orders per month and average order value.
	 *
	 * @param int      $total_orders Order count.
	 * @param float    $average_order_value Average order value.
	 * @param int|null $first_ts First order timestamp.
	 * @return array
	 */
	private function calculate_ltv_projection( $total_orders, $average_order_value, $first_ts ) {
		$orders_per_month = 0.0;
		$days_since_first = 0;

		if ( $total_orders > 0 && null !== $first_ts ) {
			$now_ts  = $this->get_now_timestamp();
			$diff    = max( 0, $now_ts - $first_ts );
			$seconds = defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400;
			$days_since_first = (int) max( 1, floor( $diff / $seconds ) );
			$months_since_first = max( 1, $days_since_first / 30 );
			$orders_per_month = $total_orders / $months_since_first;
		}

		$orders_per_month = round( $orders_per_month, 2 );
		$projection_months = 12;
		$estimated_ltv = $this->normalize_amount( $average_order_value * $orders_per_month * $projection_months );

		return array(
			'estimated_ltv' => $estimated_ltv,
			'projection'    => array(
				'projection_months'     => $projection_months,
				'orders_per_month'      => $orders_per_month,
				'days_since_first_order' => $days_since_first,
			),
		);
	}

	/**
	 * @param int|null $days_since_last Order recency in days.
	 * @param int      $total_orders Total orders.
	 * @return string
	 */
	private function determine_health_status( $days_since_last, $total_orders ) {
		if ( null === $days_since_last || 0 === $total_orders ) {
			return 'churned';
		}

		$thresholds = $this->get_health_thresholds();

		if ( $days_since_last <= $thresholds['active'] ) {
			return 'active';
		}

		if ( $days_since_last <= $thresholds['at_risk'] ) {
			return 'at_risk';
		}

		return 'churned';
	}

	/**
	 * @return array
	 */
	private function get_health_thresholds() {
		$thresholds = array(
			'active'  => 60,
			'at_risk' => 180,
		);

		if ( function_exists( 'apply_filters' ) ) {
			$thresholds = apply_filters( 'agentwp_customer_health_thresholds', $thresholds );
		}

		$active  = isset( $thresholds['active'] ) ? absint( $thresholds['active'] ) : 60;
		$at_risk = isset( $thresholds['at_risk'] ) ? absint( $thresholds['at_risk'] ) : 180;

		if ( $active <= 0 ) {
			$active = 60;
		}

		if ( $at_risk <= $active ) {
			$at_risk = $active + 1;
		}

		return array(
			'active'  => $active,
			'at_risk' => $at_risk,
		);
	}

	/**
	 * @return array
	 */
	private function get_paid_statuses() {
		$statuses = function_exists( 'wc_get_is_paid_statuses' )
			? wc_get_is_paid_statuses()
			: array( 'processing', 'completed', 'on-hold' );

		$statuses = array_filter( array_map( 'sanitize_text_field', (array) $statuses ) );
		if ( empty( $statuses ) ) {
			return array( 'processing', 'completed', 'on-hold' );
		}

		return array_values( array_unique( $statuses ) );
	}

	/**
	 * @param mixed $amount Amount input.
	 * @return float
	 */
	private function normalize_amount( $amount ) {
		if ( function_exists( 'wc_format_decimal' ) ) {
			return (float) wc_format_decimal( $amount, $this->get_price_decimals() );
		}

		return (float) $amount;
	}

	/**
	 * @return int
	 */
	private function get_price_decimals() {
		if ( function_exists( 'wc_get_price_decimals' ) ) {
			return (int) wc_get_price_decimals();
		}

		return 2;
	}

	/**
	 * @param float $value Currency amount.
	 * @return string
	 */
	private function format_currency( $value ) {
		$amount = $this->normalize_amount( $value );
		if ( function_exists( 'wc_price' ) ) {
			return wc_price( $amount );
		}

		return number_format( $amount, $this->get_price_decimals(), '.', '' );
	}

	/**
	 * @param mixed $date Date instance.
	 * @return string
	 */
	private function format_datetime( $date ) {
		if ( ! $date || ! method_exists( $date, 'format' ) ) {
			return '';
		}

		return $date->format( 'c' );
	}

	/**
	 * @return int
	 */
	private function get_now_timestamp() {
		if ( function_exists( 'current_time' ) ) {
			return (int) current_time( 'timestamp' );
		}

		return time();
	}
}
