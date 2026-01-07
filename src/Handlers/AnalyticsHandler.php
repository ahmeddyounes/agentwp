<?php
/**
 * Handle sales analytics reports.
 *
 * @package AgentWP
 */

namespace AgentWP\Handlers;

use AgentWP\AI\Response;
use AgentWP\Plugin;
use DateTimeImmutable;
use DateTimeZone;
use Exception;

class AnalyticsHandler {
	const CACHE_TTL_TODAY   = 300;
	const CACHE_TTL_DEFAULT = 3600;
	const TOP_LIMIT         = 5;

	/**
	 * Handle analytics requests.
	 *
	 * @param array $args Request args.
	 * @return Response
	 */
	public function handle( array $args ): Response {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return Response::error( 'WooCommerce is required to fetch analytics.', 400 );
		}

		global $wpdb;
		if ( ! $wpdb ) {
			return Response::error( 'Database is unavailable for analytics.', 500 );
		}

		$period = isset( $args['period'] ) ? sanitize_text_field( $args['period'] ) : '';
		$period = strtolower( trim( $period ) );

		$valid_periods = array( 'today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'custom' );
		if ( ! in_array( $period, $valid_periods, true ) ) {
			return Response::error( 'Invalid period for sales report.', 400 );
		}

		$start_input = isset( $args['start_date'] ) ? sanitize_text_field( $args['start_date'] ) : '';
		$end_input   = isset( $args['end_date'] ) ? sanitize_text_field( $args['end_date'] ) : '';
		$compare     = $this->normalize_bool( isset( $args['compare_previous'] ) ? $args['compare_previous'] : false );

		$range = $this->resolve_period_range( $period, $start_input, $end_input );
		if ( null === $range ) {
			return Response::error( 'Invalid date range for sales report.', 400 );
		}

		$order_stats_table = $wpdb->prefix . 'wc_order_stats';
		if ( ! $this->table_exists( $order_stats_table ) ) {
			return Response::error( 'WooCommerce analytics tables are unavailable.', 500 );
		}

		$cache_key = $this->build_cache_key(
			array(
				'period'           => $period,
				'start'            => $range['start_mysql'],
				'end'              => $range['end_mysql'],
				'compare_previous' => $compare,
			)
		);
		$cached    = $this->read_cache( $cache_key );
		if ( null !== $cached ) {
			$cached['cached'] = true;
			return Response::success( $cached );
		}

		$statuses = $this->get_paid_statuses();
		$current  = $this->build_report( $period, $range, $statuses );

		if ( $compare ) {
			$previous_range = $this->build_previous_range( $range );
			$previous       = $this->build_report( $period, $previous_range, $statuses );
			$payload        = array(
				'period'            => $period,
				'compare_previous'  => true,
				'current_period'    => $current,
				'previous_period'   => $previous,
				'percentage_change' => $this->build_percentage_change( $current, $previous ),
			);
		} else {
			$payload = $current;
		}

		$payload['cached'] = false;

		$this->write_cache( $cache_key, $payload, $this->get_cache_ttl( $range, $period ) );

		return Response::success( $payload );
	}

	/**
	 * @param string $period Period key.
	 * @param array  $range Date range data.
	 * @param array  $statuses Status list.
	 * @return array
	 */
	private function build_report( $period, array $range, array $statuses ) {
		$totals = $this->query_totals( $range, $statuses );

		$total_revenue = $this->normalize_amount( $totals['total_revenue'] );
		$net_revenue   = $this->normalize_amount( $totals['net_revenue'] );
		$order_count   = absint( $totals['order_count'] );
		$items_sold    = absint( $totals['items_sold'] );

		$refund_total = $total_revenue - $net_revenue;
		if ( $refund_total < 0 ) {
			$refund_total = 0;
		}

		$average_order_value = 0.0;
		if ( $order_count > 0 ) {
			$average_order_value = $this->normalize_amount( $total_revenue / $order_count );
		}

		return array(
			'period'             => $period,
			'start_date'         => $range['start_date'],
			'end_date'           => $range['end_date'],
			'total_revenue'      => $total_revenue,
			'total_revenue_formatted' => $this->format_currency( $total_revenue ),
			'order_count'        => $order_count,
			'average_order_value' => $average_order_value,
			'average_order_value_formatted' => $this->format_currency( $average_order_value ),
			'items_sold'         => $items_sold,
			'refund_total'       => $this->normalize_amount( $refund_total ),
			'refund_total_formatted' => $this->format_currency( $refund_total ),
			'net_revenue'        => $net_revenue,
			'net_revenue_formatted' => $this->format_currency( $net_revenue ),
			'top_products'       => $this->query_top_products( $range, $statuses ),
			'top_categories'     => $this->query_top_categories( $range, $statuses ),
		);
	}

	/**
	 * @param array $range Date range data.
	 * @param array $statuses Status list.
	 * @return array
	 */
	private function query_totals( array $range, array $statuses ) {
		global $wpdb;

		$table             = $wpdb->prefix . 'wc_order_stats';
		$has_total_sales   = $this->table_has_column( $table, 'total_sales' );
		$has_items_sold    = $this->table_has_column( $table, 'num_items_sold' );
		$total_column      = $has_total_sales ? 'total_sales' : 'net_total';
		$items_select      = $has_items_sold ? 'COALESCE(SUM(num_items_sold), 0) AS items_sold' : '0 AS items_sold';
		$parent_filter_sql = $this->table_has_column( $table, 'parent_id' ) ? ' AND parent_id = 0' : '';

		$params = array( $range['start_mysql'], $range['end_mysql'] );
		$status_clause = $this->build_status_clause( $statuses, $params );

		$sql = "
			SELECT
				COALESCE(SUM({$total_column}), 0) AS total_revenue,
				COALESCE(SUM(net_total), 0) AS net_revenue,
				{$items_select},
				COUNT(order_id) AS order_count
			FROM {$table}
			WHERE date_created >= %s
				AND date_created <= %s
				{$parent_filter_sql}
				{$status_clause}
		";

		$row = $wpdb->get_row( $wpdb->prepare( $sql, $params ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			$row = array(
				'total_revenue' => 0,
				'net_revenue'   => 0,
				'items_sold'    => 0,
				'order_count'   => 0,
			);
		}

		if ( ! $has_items_sold ) {
			$row['items_sold'] = $this->query_items_sold( $range, $statuses );
		}

		return $row;
	}

	/**
	 * @param array $range Date range data.
	 * @param array $statuses Status list.
	 * @return int
	 */
	private function query_items_sold( array $range, array $statuses ) {
		global $wpdb;

		$stats_table  = $wpdb->prefix . 'wc_order_stats';
		$items_table  = $wpdb->prefix . 'wc_order_product_lookup';
		$parent_filter_sql = $this->table_has_column( $stats_table, 'parent_id' ) ? ' AND stats.parent_id = 0' : '';

		if ( ! $this->table_exists( $items_table ) ) {
			return 0;
		}

		$params = array( $range['start_mysql'], $range['end_mysql'] );
		$status_clause = $this->build_status_clause( $statuses, $params, 'stats.status' );

		$sql = "
			SELECT COALESCE(SUM(lookup.product_qty), 0)
			FROM {$items_table} AS lookup
			INNER JOIN {$stats_table} AS stats
				ON lookup.order_id = stats.order_id
			WHERE stats.date_created >= %s
				AND stats.date_created <= %s
				{$parent_filter_sql}
				{$status_clause}
		";

		$total = $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
		return absint( $total );
	}

	/**
	 * @param array $range Date range data.
	 * @param array $statuses Status list.
	 * @return array
	 */
	private function query_top_products( array $range, array $statuses ) {
		global $wpdb;

		$stats_table = $wpdb->prefix . 'wc_order_stats';
		$table       = $wpdb->prefix . 'wc_order_product_lookup';

		if ( ! $this->table_exists( $table ) ) {
			return array();
		}

		$revenue_column = $this->get_product_revenue_column( $table );
		$revenue_select = $revenue_column
			? "COALESCE(SUM(lookup.{$revenue_column}), 0) AS net_revenue"
			: '0 AS net_revenue';
		$order_by = $revenue_column ? 'net_revenue' : 'items_sold';
		$parent_filter_sql = $this->table_has_column( $stats_table, 'parent_id' ) ? ' AND stats.parent_id = 0' : '';

		$params = array( $range['start_mysql'], $range['end_mysql'] );
		$status_clause = $this->build_status_clause( $statuses, $params, 'stats.status' );

		$sql = "
			SELECT
				lookup.product_id AS product_id,
				COALESCE(SUM(lookup.product_qty), 0) AS items_sold,
				{$revenue_select},
				COALESCE(posts.post_title, '') AS product_name
			FROM {$table} AS lookup
			INNER JOIN {$stats_table} AS stats
				ON lookup.order_id = stats.order_id
			LEFT JOIN {$wpdb->posts} AS posts
				ON posts.ID = lookup.product_id
			WHERE stats.date_created >= %s
				AND stats.date_created <= %s
				{$parent_filter_sql}
				{$status_clause}
				AND lookup.product_id > 0
			GROUP BY lookup.product_id
			ORDER BY {$order_by} DESC
			LIMIT %d
		";

		$params[] = self::TOP_LIMIT;

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		if ( empty( $rows ) ) {
			return array();
		}

		$results = array();
		foreach ( $rows as $row ) {
			$revenue = $this->normalize_amount( isset( $row['net_revenue'] ) ? $row['net_revenue'] : 0 );
			$results[] = array(
				'product_id' => absint( $row['product_id'] ),
				'name'       => sanitize_text_field( $row['product_name'] ),
				'items_sold' => absint( $row['items_sold'] ),
				'net_revenue' => $revenue,
				'net_revenue_formatted' => $this->format_currency( $revenue ),
			);
		}

		return $results;
	}

	/**
	 * @param array $range Date range data.
	 * @param array $statuses Status list.
	 * @return array
	 */
	private function query_top_categories( array $range, array $statuses ) {
		global $wpdb;

		$stats_table = $wpdb->prefix . 'wc_order_stats';
		$table       = $wpdb->prefix . 'wc_order_product_lookup';

		if ( ! $this->table_exists( $table ) ) {
			return array();
		}

		$revenue_column = $this->get_product_revenue_column( $table );
		$revenue_select = $revenue_column
			? "COALESCE(SUM(lookup.{$revenue_column}), 0) AS net_revenue"
			: '0 AS net_revenue';
		$order_by = $revenue_column ? 'net_revenue' : 'items_sold';
		$parent_filter_sql = $this->table_has_column( $stats_table, 'parent_id' ) ? ' AND stats.parent_id = 0' : '';

		$params = array( $range['start_mysql'], $range['end_mysql'] );
		$status_clause = $this->build_status_clause( $statuses, $params, 'stats.status' );

		$sql = "
			SELECT
				terms.term_id AS term_id,
				terms.name AS category_name,
				COALESCE(SUM(lookup.product_qty), 0) AS items_sold,
				{$revenue_select}
			FROM {$table} AS lookup
			INNER JOIN {$stats_table} AS stats
				ON lookup.order_id = stats.order_id
			INNER JOIN {$wpdb->term_relationships} AS rel
				ON rel.object_id = lookup.product_id
			INNER JOIN {$wpdb->term_taxonomy} AS tax
				ON tax.term_taxonomy_id = rel.term_taxonomy_id
			INNER JOIN {$wpdb->terms} AS terms
				ON terms.term_id = tax.term_id
			WHERE stats.date_created >= %s
				AND stats.date_created <= %s
				{$parent_filter_sql}
				{$status_clause}
				AND lookup.product_id > 0
				AND tax.taxonomy = 'product_cat'
			GROUP BY terms.term_id
			ORDER BY {$order_by} DESC
			LIMIT %d
		";

		$params[] = self::TOP_LIMIT;

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		if ( empty( $rows ) ) {
			return array();
		}

		$results = array();
		foreach ( $rows as $row ) {
			$revenue = $this->normalize_amount( isset( $row['net_revenue'] ) ? $row['net_revenue'] : 0 );
			$results[] = array(
				'term_id'    => absint( $row['term_id'] ),
				'name'       => sanitize_text_field( $row['category_name'] ),
				'items_sold' => absint( $row['items_sold'] ),
				'net_revenue' => $revenue,
				'net_revenue_formatted' => $this->format_currency( $revenue ),
			);
		}

		return $results;
	}

	/**
	 * @param array $current Current report.
	 * @param array $previous Previous report.
	 * @return array
	 */
	private function build_percentage_change( array $current, array $previous ) {
		$metrics = array(
			'total_revenue',
			'order_count',
			'average_order_value',
			'items_sold',
			'refund_total',
			'net_revenue',
		);

		$changes = array();
		foreach ( $metrics as $metric ) {
			$current_value  = $this->extract_metric_value( $current, $metric );
			$previous_value = $this->extract_metric_value( $previous, $metric );
			$changes[ $metric ] = $this->calculate_percentage_change( $current_value, $previous_value );
		}

		return $changes;
	}

	/**
	 * @param array  $report Report data.
	 * @param string $metric Metric key.
	 * @return float
	 */
	private function extract_metric_value( array $report, $metric ) {
		if ( ! isset( $report[ $metric ] ) ) {
			return 0.0;
		}

		$value = $report[ $metric ];
		if ( is_array( $value ) && isset( $value['amount'] ) ) {
			return (float) $value['amount'];
		}

		return (float) $value;
	}

	/**
	 * @param float $current Current value.
	 * @param float $previous Previous value.
	 * @return float|null
	 */
	private function calculate_percentage_change( $current, $previous ) {
		$current  = (float) $current;
		$previous = (float) $previous;

		if ( 0.0 === $previous ) {
			return 0.0 === $current ? 0.0 : null;
		}

		$change = ( ( $current - $previous ) / $previous ) * 100;

		return (float) $this->normalize_amount( $change );
	}

	/**
	 * @param string $period Period key.
	 * @param string $start_input Start date input.
	 * @param string $end_input End date input.
	 * @return array|null
	 */
	private function resolve_period_range( $period, $start_input, $end_input ) {
		$timezone = $this->get_timezone();
		$now      = new DateTimeImmutable( 'now', $timezone );

		switch ( $period ) {
			case 'today':
				$start = $now->setTime( 0, 0, 0 );
				$end   = $now;
				break;
			case 'yesterday':
				$start = $now->modify( '-1 day' )->setTime( 0, 0, 0 );
				$end   = $start->setTime( 23, 59, 59 );
				break;
			case 'this_week':
				$start = $this->start_of_week( $now, $timezone );
				$end   = $now;
				break;
			case 'last_week':
				$current_week_start = $this->start_of_week( $now, $timezone );
				$end   = $current_week_start->modify( '-1 second' );
				$start = $current_week_start->modify( '-7 days' )->setTime( 0, 0, 0 );
				break;
			case 'this_month':
				$start = $now->modify( 'first day of this month' )->setTime( 0, 0, 0 );
				$end   = $now;
				break;
			case 'last_month':
				$start = $now->modify( 'first day of last month' )->setTime( 0, 0, 0 );
				$end   = $now->modify( 'last day of last month' )->setTime( 23, 59, 59 );
				break;
			case 'custom':
				$start_date = $this->parse_date_input( $start_input, $timezone );
				$end_date   = $this->parse_date_input( $end_input, $timezone );
				if ( null === $start_date || null === $end_date ) {
					return null;
				}

				$start = $start_date->setTime( 0, 0, 0 );
				$end   = $end_date->setTime( 23, 59, 59 );
				break;
			default:
				return null;
		}

		if ( $end < $start ) {
			$temp  = $start;
			$start = $end;
			$end   = $temp;
		}

		return $this->format_range( $start, $end );
	}

	/**
	 * @param array $range Current range data.
	 * @return array
	 */
	private function build_previous_range( array $range ) {
		$start = $range['start'];
		$end   = $range['end'];

		$duration = max( 0, $end->getTimestamp() - $start->getTimestamp() );
		$prev_end = $start->modify( '-1 second' );
		$prev_start = $prev_end->modify( '-' . $duration . ' seconds' );

		return $this->format_range( $prev_start, $prev_end );
	}

	/**
	 * @param DateTimeImmutable $start Start date.
	 * @param DateTimeImmutable $end End date.
	 * @return array
	 */
	private function format_range( DateTimeImmutable $start, DateTimeImmutable $end ) {
		return array(
			'start'       => $start,
			'end'         => $end,
			'start_mysql' => $start->format( 'Y-m-d H:i:s' ),
			'end_mysql'   => $end->format( 'Y-m-d H:i:s' ),
			'start_date'  => $start->format( 'Y-m-d' ),
			'end_date'    => $end->format( 'Y-m-d' ),
		);
	}

	/**
	 * @param DateTimeImmutable $now Current time.
	 * @param DateTimeZone      $timezone Timezone.
	 * @return DateTimeImmutable
	 */
	private function start_of_week( DateTimeImmutable $now, DateTimeZone $timezone ) {
		$week_start = 1;
		if ( function_exists( 'get_option' ) ) {
			$week_start = intval( get_option( 'start_of_week', 1 ) );
		}

		$weekday = intval( $now->format( 'w' ) );
		$diff    = ( $weekday - $week_start + 7 ) % 7;

		return $now->modify( '-' . $diff . ' days' )->setTime( 0, 0, 0 )->setTimezone( $timezone );
	}

	/**
	 * @param string       $value Date string.
	 * @param DateTimeZone $timezone Timezone.
	 * @return DateTimeImmutable|null
	 */
	private function parse_date_input( $value, DateTimeZone $timezone ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}

		$date = DateTimeImmutable::createFromFormat( 'Y-m-d', $value, $timezone );
		if ( false === $date ) {
			return null;
		}

		return $date;
	}

	/**
	 * @param array $statuses Status list.
	 * @param array $params Parameters to append to.
	 * @param string $column Column name.
	 * @return string
	 */
	private function build_status_clause( array $statuses, array &$params, $column = 'status' ) {
		if ( empty( $statuses ) ) {
			return '';
		}

		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		foreach ( $statuses as $status ) {
			$params[] = $status;
		}

		return " AND {$column} IN ({$placeholders})";
	}

	/**
	 * @return array
	 */
	private function get_paid_statuses() {
		$statuses = function_exists( 'wc_get_is_paid_statuses' )
			? wc_get_is_paid_statuses()
			: array( 'processing', 'completed', 'on-hold' );

		$normalized = array();
		foreach ( $statuses as $status ) {
			$status = sanitize_text_field( $status );
			$status = trim( $status );
			if ( '' === $status ) {
				continue;
			}

			$normalized[] = $status;
			if ( 0 === strpos( $status, 'wc-' ) ) {
				$normalized[] = substr( $status, 3 );
			} else {
				$normalized[] = 'wc-' . $status;
			}
		}

		$normalized = array_unique( $normalized );
		sort( $normalized );

		return $normalized;
	}

	/**
	 * @param mixed $value Input.
	 * @return bool
	 */
	private function normalize_bool( $value ) {
		if ( function_exists( 'rest_sanitize_boolean' ) ) {
			return rest_sanitize_boolean( $value );
		}

		return (bool) $value;
	}

	/**
	 * @param mixed $amount Input amount.
	 * @return float
	 */
	private function normalize_amount( $amount ) {
		$amount   = is_numeric( $amount ) ? (float) $amount : 0.0;
		$decimals = $this->get_price_decimals();

		if ( function_exists( 'wc_format_decimal' ) ) {
			return (float) wc_format_decimal( $amount, $decimals );
		}

		return (float) round( $amount, $decimals );
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
	 * @param mixed $value Input.
	 * @return string
	 */
	private function format_currency( $value ) {
		if ( '' === $value || null === $value ) {
			return '';
		}

		if ( function_exists( 'wc_price' ) ) {
			return wp_strip_all_tags( wc_price( $value ) );
		}

		return (string) $value;
	}

	/**
	 * @param array $payload Cache payload.
	 * @return string
	 */
	private function build_cache_key( array $payload ) {
		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $payload ) : json_encode( $payload );
		$hash    = $encoded ? md5( $encoded ) : md5( 'sales_report' );

		return Plugin::TRANSIENT_PREFIX . 'sales_report_' . $hash;
	}

	/**
	 * @param string $cache_key Cache key.
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
	 * @param string $cache_key Cache key.
	 * @param array  $payload Cache payload.
	 * @param int    $ttl Cache TTL.
	 * @return void
	 */
	private function write_cache( $cache_key, array $payload, $ttl ) {
		if ( ! function_exists( 'set_transient' ) ) {
			return;
		}

		set_transient( $cache_key, $payload, $ttl );
	}

	/**
	 * @param array  $range Date range.
	 * @param string $period Period.
	 * @return int
	 */
	private function get_cache_ttl( array $range, $period ) {
		if ( 'today' === $period || $this->range_is_today( $range ) ) {
			return self::CACHE_TTL_TODAY;
		}

		return self::CACHE_TTL_DEFAULT;
	}

	/**
	 * @param array $range Date range.
	 * @return bool
	 */
	private function range_is_today( array $range ) {
		$timezone = $this->get_timezone();
		$today    = ( new DateTimeImmutable( 'now', $timezone ) )->format( 'Y-m-d' );

		return $range['start']->format( 'Y-m-d' ) === $today && $range['end']->format( 'Y-m-d' ) === $today;
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
	 * @param string $table Table name.
	 * @return bool
	 */
	private function table_exists( $table ) {
		global $wpdb;

		$like  = $wpdb->esc_like( $table );
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
		return $found === $table;
	}

	/**
	 * @param string $table Table name.
	 * @param string $column Column name.
	 * @return bool
	 */
	private function table_has_column( $table, $column ) {
		static $cache = array();
		$key = $table . ':' . $column;

		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}

		global $wpdb;
		$result = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW COLUMNS FROM %i LIKE %s',
				$table,
				$column
			)
		);
		$cache[ $key ] = ! empty( $result );

		return $cache[ $key ];
	}

	/**
	 * @param string $table Table name.
	 * @return string|null
	 */
	private function get_product_revenue_column( $table ) {
		if ( $this->table_has_column( $table, 'product_net_revenue' ) ) {
			return 'product_net_revenue';
		}

		if ( $this->table_has_column( $table, 'product_gross_revenue' ) ) {
			return 'product_gross_revenue';
		}

		return null;
	}
}
