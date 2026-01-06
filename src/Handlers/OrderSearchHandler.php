<?php
/**
 * Handle order search requests.
 *
 * @package AgentWP
 */

namespace AgentWP\Handlers;

use AgentWP\AI\Response;
use AgentWP\Plugin;
use DateTimeImmutable;
use DateTimeZone;
use Exception;

class OrderSearchHandler {
	const DEFAULT_LIMIT = 10;
	const CACHE_TTL     = 3600;

	/**
	 * Handle an order search request.
	 *
	 * @param array $args Search parameters.
	 * @return Response
	 */
	public function handle( array $args ): Response {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return Response::error( 'WooCommerce is required to search orders.', 400 );
		}

		$normalized = $this->normalize_args( $args );
		$cache_key  = $this->build_cache_key( $normalized );
		$cached     = $this->read_cache( $cache_key );

		if ( null !== $cached ) {
			return Response::success(
				array(
					'orders' => $cached,
					'count'  => count( $cached ),
					'cached' => true,
					'query'  => $this->public_query_summary( $normalized ),
				)
			);
		}

		$orders = $this->run_query( $normalized );
		$this->write_cache( $cache_key, $orders );

		return Response::success(
			array(
				'orders' => $orders,
				'count'  => count( $orders ),
				'cached' => false,
				'query'  => $this->public_query_summary( $normalized ),
			)
		);
	}

	/**
	 * @param array $args Search parameters.
	 * @return array
	 */
	private function normalize_args( array $args ) {
		$normalized = array(
			'query'      => isset( $args['query'] ) ? sanitize_text_field( $args['query'] ) : '',
			'order_id'   => isset( $args['order_id'] ) ? absint( $args['order_id'] ) : 0,
			'email'      => isset( $args['email'] ) ? sanitize_email( $args['email'] ) : '',
			'status'     => isset( $args['status'] ) ? sanitize_text_field( $args['status'] ) : '',
			'limit'      => isset( $args['limit'] ) ? absint( $args['limit'] ) : 0,
			'date_range' => $this->normalize_date_range_input( isset( $args['date_range'] ) ? $args['date_range'] : null ),
			'orderby'    => '',
			'order'      => '',
		);

		if ( '' !== $normalized['query'] ) {
			$normalized = $this->apply_query_hints( $normalized, $normalized['query'] );
		}

		if ( '' !== $normalized['status'] ) {
			$normalized['status'] = $this->normalize_status( $normalized['status'] );
		}

		if ( 0 === $normalized['limit'] ) {
			$normalized['limit'] = self::DEFAULT_LIMIT;
		}

		if ( '' === $normalized['orderby'] ) {
			$normalized['orderby'] = 'date';
		}

		if ( '' === $normalized['order'] ) {
			$normalized['order'] = 'DESC';
		}

		return $normalized;
	}

	/**
	 * @param array  $normalized Current normalized params.
	 * @param string $query Raw query string.
	 * @return array
	 */
	private function apply_query_hints( array $normalized, $query ) {
		$query = trim( (string) $query );
		if ( '' === $query ) {
			return $normalized;
		}

		$lowered = strtolower( $query );

		if ( 0 === $normalized['order_id'] ) {
			$normalized['order_id'] = $this->extract_order_id( $lowered );
		}

		if ( '' === $normalized['email'] ) {
			$normalized['email'] = $this->extract_email( $lowered );
		}

		if ( '' === $normalized['status'] ) {
			$normalized['status'] = $this->detect_status( $lowered );
		}

		if ( null === $normalized['date_range'] ) {
			$normalized['date_range'] = $this->parse_date_range_from_query( $lowered );
		}

		if ( 0 === $normalized['limit'] && $this->contains_last_order_phrase( $lowered ) ) {
			$normalized['limit']   = 1;
			$normalized['orderby'] = 'date';
			$normalized['order']   = 'DESC';
		}

		return $normalized;
	}

	/**
	 * @param array $date_range Input date range.
	 * @return array|null
	 */
	private function normalize_date_range_input( $date_range ) {
		if ( ! is_array( $date_range ) ) {
			return null;
		}

		$start = isset( $date_range['start'] ) ? sanitize_text_field( $date_range['start'] ) : '';
		$end   = isset( $date_range['end'] ) ? sanitize_text_field( $date_range['end'] ) : '';

		return $this->normalize_date_range_values( $start, $end );
	}

	/**
	 * @param string $status Raw status string.
	 * @return string
	 */
	private function normalize_status( $status ) {
		$status = strtolower( trim( (string) $status ) );
		if ( 0 === strpos( $status, 'wc-' ) ) {
			$status = substr( $status, 3 );
		}

		return $status;
	}

	/**
	 * @param array $normalized Search params.
	 * @return string
	 */
	private function build_cache_key( array $normalized ) {
		$payload = wp_json_encode( $normalized );
		$hash    = $payload ? md5( $payload ) : md5( 'order_search' );

		return Plugin::TRANSIENT_PREFIX . 'order_search_' . $hash;
	}

	/**
	 * @param string $cache_key Transient key.
	 * @return array|null
	 */
	private function read_cache( $cache_key ) {
		if ( ! function_exists( 'get_transient' ) ) {
			return null;
		}

		$cached = get_transient( $cache_key );
		if ( false === $cached || ! is_array( $cached ) ) {
			return null;
		}

		return $cached;
	}

	/**
	 * @param string $cache_key Transient key.
	 * @param array  $orders Cached orders.
	 * @return void
	 */
	private function write_cache( $cache_key, array $orders ) {
		if ( ! function_exists( 'set_transient' ) ) {
			return;
		}

		set_transient( $cache_key, $orders, self::CACHE_TTL );
	}

	/**
	 * @param array $normalized Search parameters.
	 * @return array
	 */
	private function run_query( array $normalized ) {
		if ( ! function_exists( 'wc_get_order' ) || ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		if ( $normalized['order_id'] > 0 ) {
			$order = wc_get_order( $normalized['order_id'] );
			if ( $order ) {
				return array( $this->format_order( $order ) );
			}

			return array();
		}

		$query_args = array(
			'limit'   => $normalized['limit'],
			'orderby' => $normalized['orderby'],
			'order'   => $normalized['order'],
		);

		if ( '' !== $normalized['status'] ) {
			$query_args['status'] = $normalized['status'];
		}

		if ( is_array( $normalized['date_range'] ) ) {
			$query_args['date_created'] = $normalized['date_range']['start'] . '...' . $normalized['date_range']['end'];
		}

		if ( '' !== $normalized['email'] ) {
			$query_args['meta_query'] = array(
				'relation' => 'OR',
				array(
					'key'     => '_billing_email',
					'value'   => $normalized['email'],
					'compare' => '=',
				),
				array(
					'key'     => '_shipping_email',
					'value'   => $normalized['email'],
					'compare' => '=',
				),
			);
		}

		$orders = wc_get_orders( $query_args );
		if ( ! is_array( $orders ) ) {
			return array();
		}

		$results = array();
		foreach ( $orders as $order ) {
			if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
				continue;
			}
			$results[] = $this->format_order( $order );
		}

		return $results;
	}

	/**
	 * @param array $normalized Search params.
	 * @return array
	 */
	private function public_query_summary( array $normalized ) {
		return array(
			'order_id'   => $normalized['order_id'],
			'email'      => $normalized['email'],
			'status'     => $normalized['status'],
			'limit'      => $normalized['limit'],
			'date_range' => $normalized['date_range'],
		);
	}

	/**
	 * @param object $order Order instance.
	 * @return array
	 */
	private function format_order( $order ) {
		$date_created = method_exists( $order, 'get_date_created' ) ? $order->get_date_created() : null;
		$email        = $this->get_customer_email( $order );
		$customer     = $this->get_customer_name( $order );

		return array(
			'id'               => intval( $order->get_id() ),
			'status'           => sanitize_text_field( $order->get_status() ),
			'total'            => $order->get_total(),
			'customer_name'    => sanitize_text_field( $customer ),
			'customer_email'   => sanitize_email( $email ),
			'date_created'     => $date_created ? $date_created->date( 'c' ) : '',
			'items_summary'    => $this->format_items_summary( $order ),
			'shipping_address' => $this->format_shipping_address( $order ),
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

		if ( '' !== $name ) {
			return $name;
		}

		$first = method_exists( $order, 'get_shipping_first_name' ) ? $order->get_shipping_first_name() : '';
		$last  = method_exists( $order, 'get_shipping_last_name' ) ? $order->get_shipping_last_name() : '';

		return trim( $first . ' ' . $last );
	}

	/**
	 * @param object $order Order instance.
	 * @return string
	 */
	private function get_customer_email( $order ) {
		$email = method_exists( $order, 'get_billing_email' ) ? $order->get_billing_email() : '';
		if ( '' !== $email ) {
			return $email;
		}

		if ( method_exists( $order, 'get_meta' ) ) {
			$email = $order->get_meta( '_shipping_email' );
		}

		return is_string( $email ) ? $email : '';
	}

	/**
	 * @param object $order Order instance.
	 * @return string
	 */
	private function format_items_summary( $order ) {
		if ( ! method_exists( $order, 'get_items' ) ) {
			return '';
		}

		$items = $order->get_items();
		if ( empty( $items ) || ! is_array( $items ) ) {
			return '';
		}

		$summary = array();
		foreach ( $items as $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_name' ) ) {
				continue;
			}

			$name = sanitize_text_field( $item->get_name() );
			$qty  = method_exists( $item, 'get_quantity' ) ? intval( $item->get_quantity() ) : 1;

			if ( $qty > 1 ) {
				$summary[] = sprintf( '%dx %s', $qty, $name );
			} else {
				$summary[] = $name;
			}
		}

		return implode( ', ', $summary );
	}

	/**
	 * @param object $order Order instance.
	 * @return array
	 */
	private function format_shipping_address( $order ) {
		if ( ! method_exists( $order, 'get_address' ) ) {
			return array();
		}

		$shipping = $order->get_address( 'shipping' );
		$billing  = $order->get_address( 'billing' );

		if ( ! is_array( $shipping ) ) {
			$shipping = array();
		}

		if ( ! is_array( $billing ) ) {
			$billing = array();
		}

		$fields = array(
			'first_name',
			'last_name',
			'company',
			'address_1',
			'address_2',
			'city',
			'state',
			'postcode',
			'country',
		);

		$address = array();
		foreach ( $fields as $field ) {
			$value = isset( $shipping[ $field ] ) ? trim( (string) $shipping[ $field ] ) : '';
			if ( '' === $value && isset( $billing[ $field ] ) ) {
				$value = trim( (string) $billing[ $field ] );
			}
			$address[ $field ] = $value;
		}

		$name = trim( $address['first_name'] . ' ' . $address['last_name'] );

		return array(
			'name'      => sanitize_text_field( $name ),
			'company'   => sanitize_text_field( $address['company'] ),
			'address_1' => sanitize_text_field( $address['address_1'] ),
			'address_2' => sanitize_text_field( $address['address_2'] ),
			'city'      => sanitize_text_field( $address['city'] ),
			'state'     => sanitize_text_field( $address['state'] ),
			'postcode'  => sanitize_text_field( $address['postcode'] ),
			'country'   => sanitize_text_field( $address['country'] ),
		);
	}

	/**
	 * @param string $query Query string.
	 * @return int
	 */
	private function extract_order_id( $query ) {
		if ( preg_match( '/\border\s*#?\s*(\d+)\b/i', $query, $matches ) ) {
			return absint( $matches[1] );
		}

		if ( preg_match( '/#(\d+)/', $query, $matches ) ) {
			return absint( $matches[1] );
		}

		return 0;
	}

	/**
	 * @param string $query Query string.
	 * @return string
	 */
	private function extract_email( $query ) {
		if ( preg_match( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $query, $matches ) ) {
			return sanitize_email( $matches[0] );
		}

		return '';
	}

	/**
	 * @param string $query Query string.
	 * @return string
	 */
	private function detect_status( $query ) {
		$map = array(
			'pending'    => array( 'pending', 'awaiting payment' ),
			'processing' => array( 'processing', 'in progress' ),
			'completed'  => array( 'completed', 'complete', 'fulfilled' ),
			'on-hold'    => array( 'on hold', 'on-hold', 'hold' ),
			'cancelled'  => array( 'cancelled', 'canceled' ),
			'refunded'   => array( 'refunded', 'refund' ),
			'failed'     => array( 'failed', 'declined' ),
		);

		foreach ( $map as $status => $terms ) {
			foreach ( $terms as $term ) {
				if ( false !== strpos( $query, $term ) ) {
					return $status;
				}
			}
		}

		return '';
	}

	/**
	 * @param string $query Query string.
	 * @return array|null
	 */
	private function parse_date_range_from_query( $query ) {
		if ( false !== strpos( $query, 'yesterday' ) ) {
			return $this->relative_date_range( 'yesterday' );
		}

		if ( false !== strpos( $query, 'last week' ) ) {
			return $this->relative_date_range( 'last week' );
		}

		if ( false !== strpos( $query, 'this month' ) ) {
			return $this->relative_date_range( 'this month' );
		}

		$range = $this->extract_explicit_date_range( $query );
		if ( null !== $range ) {
			return $range;
		}

		return null;
	}

	/**
	 * @param string $phrase Relative date phrase.
	 * @return array|null
	 */
	private function relative_date_range( $phrase ) {
		$timezone = $this->get_timezone();
		$now      = new DateTimeImmutable( 'now', $timezone );
		$start    = null;
		$end      = null;

		switch ( $phrase ) {
			case 'yesterday':
				$start = $now->setTime( 0, 0, 0 )->modify( '-1 day' );
				$end   = $start->setTime( 23, 59, 59 );
				break;
			case 'last week':
				$start = $now->modify( '-7 days' )->setTime( 0, 0, 0 );
				$end   = $now->modify( '-1 day' )->setTime( 23, 59, 59 );
				break;
			case 'this month':
				$start = $now->modify( 'first day of this month' )->setTime( 0, 0, 0 );
				$end   = $now->setTime( 23, 59, 59 );
				break;
			default:
				return null;
		}

		return $this->format_date_range( $start, $end );
	}

	/**
	 * @param string $query Query string.
	 * @return array|null
	 */
	private function extract_explicit_date_range( $query ) {
		$patterns = array(
			'/\bfrom\s+([a-z0-9,\/\-\s]+?)\s+to\s+([a-z0-9,\/\-\s]+)\b/i',
			'/\bbetween\s+([a-z0-9,\/\-\s]+?)\s+and\s+([a-z0-9,\/\-\s]+)\b/i',
		);

		foreach ( $patterns as $pattern ) {
			if ( ! preg_match( $pattern, $query, $matches ) ) {
				continue;
			}

			$start = trim( $matches[1] );
			$end   = trim( $matches[2] );

			if ( false !== strpos( $start, '@' ) || false !== strpos( $end, '@' ) ) {
				continue;
			}

			$range = $this->normalize_date_range_values( $start, $end );
			if ( null !== $range ) {
				return $range;
			}
		}

		return null;
	}

	/**
	 * @param string $start Start date string.
	 * @param string $end End date string.
	 * @return array|null
	 */
	private function normalize_date_range_values( $start, $end ) {
		$start_date = $this->parse_date_string( $start, false );
		$end_date   = $this->parse_date_string( $end, true );

		if ( null === $start_date || null === $end_date ) {
			return null;
		}

		if ( $end_date < $start_date ) {
			$temp       = $start_date;
			$start_date = $end_date;
			$end_date   = $temp;
		}

		return $this->format_date_range( $start_date, $end_date );
	}

	/**
	 * @param string $date_string Input date.
	 * @param bool   $end_of_day Whether to set end of day.
	 * @return DateTimeImmutable|null
	 */
	private function parse_date_string( $date_string, $end_of_day ) {
		$date_string = trim( (string) $date_string );
		if ( '' === $date_string ) {
			return null;
		}

		$timezone = $this->get_timezone();
		$base_ts  = $this->get_base_timestamp();
		$ts       = strtotime( $date_string, $base_ts );

		if ( false === $ts ) {
			return null;
		}

		$date = ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( $timezone );
		if ( $end_of_day ) {
			$date = $date->setTime( 23, 59, 59 );
		} else {
			$date = $date->setTime( 0, 0, 0 );
		}

		return $date;
	}

	/**
	 * @param DateTimeImmutable $start Start date.
	 * @param DateTimeImmutable $end End date.
	 * @return array
	 */
	private function format_date_range( DateTimeImmutable $start, DateTimeImmutable $end ) {
		return array(
			'start' => $start->format( 'Y-m-d H:i:s' ),
			'end'   => $end->format( 'Y-m-d H:i:s' ),
		);
	}

	/**
	 * @return DateTimeZone
	 */
	private function get_timezone() {
		if ( function_exists( 'wp_timezone' ) ) {
			return wp_timezone();
		}

		$timezone = '';
		if ( function_exists( 'wp_timezone_string' ) ) {
			$timezone = wp_timezone_string();
		}

		if ( '' === $timezone && function_exists( 'get_option' ) ) {
			$timezone = (string) get_option( 'timezone_string' );
		}

		if ( '' === $timezone ) {
			$timezone = 'UTC';
		}

		try {
			return new DateTimeZone( $timezone );
		} catch ( Exception $exception ) {
			return new DateTimeZone( 'UTC' );
		}
	}

	/**
	 * @return int
	 */
	private function get_base_timestamp() {
		if ( function_exists( 'current_time' ) ) {
			return (int) current_time( 'timestamp' );
		}

		return time();
	}

	/**
	 * @param string $query Query string.
	 * @return bool
	 */
	private function contains_last_order_phrase( $query ) {
		return (bool) preg_match( '/\b(last|latest|most recent)\s+order\b/i', $query );
	}
}
