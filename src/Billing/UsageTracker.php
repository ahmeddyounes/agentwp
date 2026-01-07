<?php
/**
 * Track OpenAI usage and costs.
 *
 * @package AgentWP
 */

namespace AgentWP\Billing;

use AgentWP\AI\Model;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

class UsageTracker {
	const TABLE          = 'agentwp_usage';
	const VERSION        = '1.0';
	const VERSION_OPTION = 'agentwp_usage_version';
	const RETENTION_DAYS = 90;
	const TOKEN_SCALE    = 1000000;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'ensure_table' ) );
	}

	/**
	 * Activation handler.
	 *
	 * @return void
	 */
	public static function activate() {
		self::ensure_table();
		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	/**
	 * Record a usage entry.
	 *
	 * @param string $model Model name.
	 * @param int    $input_tokens Input tokens.
	 * @param int    $output_tokens Output tokens.
	 * @param string $intent_type Intent identifier.
	 * @param string $timestamp Optional timestamp (UTC).
	 * @return void
	 */
	public static function log_usage( $model, $input_tokens, $output_tokens, $intent_type, $timestamp = '' ) {
		global $wpdb;
		if ( ! $wpdb ) {
			return;
		}

		self::ensure_table();

		$input_tokens  = max( 0, (int) $input_tokens );
		$output_tokens = max( 0, (int) $output_tokens );
		$total_tokens  = $input_tokens + $output_tokens;
		$model         = self::normalize_model( $model );
		$intent_type   = self::normalize_intent( $intent_type );

		if ( '' === $timestamp ) {
			$timestamp = gmdate( 'Y-m-d H:i:s' );
		}

		self::purge_old_rows();

		$table = self::get_table_name();
		$wpdb->insert(
			$table,
			array(
				'intent_type'  => $intent_type,
				'model'        => $model,
				'input_tokens' => $input_tokens,
				'output_tokens' => $output_tokens,
				'total_tokens' => $total_tokens,
				'created_at'   => $timestamp,
			),
			array( '%s', '%s', '%d', '%d', '%d', '%s' )
		);
	}

	/**
	 * Get usage stats for a period.
	 *
	 * @param string $period day|week|month.
	 * @return array
	 */
	public static function get_usage_summary( $period ) {
		global $wpdb;

		self::ensure_table();
		self::purge_old_rows();

		list( $start, $end ) = self::get_period_range( $period );

		$summary = array(
			'period'              => $period,
			'period_start'        => $start->format( 'Y-m-d H:i:s' ),
			'period_end'          => $end->format( 'Y-m-d H:i:s' ),
			'total_tokens'        => 0,
			'total_cost_usd'      => 0,
			'breakdown_by_intent' => array(),
			'daily_trend'         => array(),
		);

		if ( ! $wpdb ) {
			return $summary;
		}

		$table = self::get_table_name();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT intent_type, model, input_tokens, output_tokens, created_at FROM {$table} WHERE created_at >= %s AND created_at <= %s ORDER BY created_at ASC",
				$summary['period_start'],
				$summary['period_end']
			),
			ARRAY_A
		);

		$daily = array();
		$cursor = $start;
		while ( $cursor <= $end ) {
			$key           = $cursor->format( 'Y-m-d' );
			$daily[ $key ] = array(
				'date'           => $key,
				'total_tokens'   => 0,
				'total_cost_usd' => 0,
			);
			$cursor = $cursor->modify( '+1 day' );
		}

		$breakdown = array();
		foreach ( $rows as $row ) {
			$input_tokens  = isset( $row['input_tokens'] ) ? (int) $row['input_tokens'] : 0;
			$output_tokens = isset( $row['output_tokens'] ) ? (int) $row['output_tokens'] : 0;
			$total_tokens  = $input_tokens + $output_tokens;
			$model         = isset( $row['model'] ) ? $row['model'] : '';
			$cost          = self::calculate_cost( $model, $input_tokens, $output_tokens );

			$summary['total_tokens'] += $total_tokens;
			$summary['total_cost_usd'] += $cost;

			$intent = self::normalize_intent( isset( $row['intent_type'] ) ? $row['intent_type'] : '' );
			if ( ! isset( $breakdown[ $intent ] ) ) {
				$breakdown[ $intent ] = array(
					'intent_type'    => $intent,
					'total_tokens'   => 0,
					'total_cost_usd' => 0,
				);
			}
			$breakdown[ $intent ]['total_tokens'] += $total_tokens;
			$breakdown[ $intent ]['total_cost_usd'] += $cost;

			$date_key = '';
			if ( isset( $row['created_at'] ) && is_string( $row['created_at'] ) ) {
				$date_key = substr( $row['created_at'], 0, 10 );
			}
			if ( isset( $daily[ $date_key ] ) ) {
				$daily[ $date_key ]['total_tokens'] += $total_tokens;
				$daily[ $date_key ]['total_cost_usd'] += $cost;
			}
		}

		$summary['total_cost_usd'] = round( $summary['total_cost_usd'], 6 );

		$breakdown_list = array_values( $breakdown );
		foreach ( $breakdown_list as &$item ) {
			$item['total_cost_usd'] = round( $item['total_cost_usd'], 6 );
		}
		unset( $item );

		usort(
			$breakdown_list,
			function ( $a, $b ) {
				return ( $b['total_tokens'] ?? 0 ) <=> ( $a['total_tokens'] ?? 0 );
			}
		);

		foreach ( $daily as &$day ) {
			$day['total_cost_usd'] = round( $day['total_cost_usd'], 6 );
		}
		unset( $day );

		$summary['breakdown_by_intent'] = $breakdown_list;
		$summary['daily_trend']         = array_values( $daily );

		return $summary;
	}

	/**
	 * Ensure the usage table exists.
	 *
	 * @return void
	 */
	public static function ensure_table() {
		global $wpdb;
		if ( ! $wpdb ) {
			return;
		}

		$installed_version = get_option( self::VERSION_OPTION, '' );
		$table             = self::get_table_name();

		if ( $installed_version === self::VERSION && self::table_exists( $table ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			intent_type varchar(64) NOT NULL,
			model varchar(64) NOT NULL,
			input_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
			output_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
			total_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY created_at_idx (created_at),
			KEY intent_idx (intent_type),
			KEY model_idx (model)
		) {$charset_collate};";

		dbDelta( $sql );
		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	/**
	 * @return string
	 */
	private static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * @param string $table Table name.
	 * @return bool
	 */
	private static function table_exists( $table ) {
		global $wpdb;

		$like = $wpdb->esc_like( $table );
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
	}

	/**
	 * @param string $model Model identifier.
	 * @return string
	 */
	private static function normalize_model( $model ) {
		$model = is_string( $model ) ? strtolower( trim( $model ) ) : '';
		if ( '' === $model ) {
			return Model::GPT_4O_MINI;
		}

		if ( 0 === strpos( $model, 'gpt-4o-mini' ) ) {
			return Model::GPT_4O_MINI;
		}

		if ( 0 === strpos( $model, 'gpt-4o' ) ) {
			return Model::GPT_4O;
		}

		return Model::normalize( $model );
	}

	/**
	 * @param string $intent Intent identifier.
	 * @return string
	 */
	private static function normalize_intent( $intent ) {
		$intent = is_string( $intent ) ? sanitize_text_field( $intent ) : '';
		$intent = strtoupper( trim( $intent ) );

		return '' !== $intent ? $intent : 'UNKNOWN';
	}

	/**
	 * @param string $model Model identifier.
	 * @param int    $input_tokens Input tokens.
	 * @param int    $output_tokens Output tokens.
	 * @return float
	 */
	private static function calculate_cost( $model, $input_tokens, $output_tokens ) {
		$model = self::normalize_model( $model );

		$pricing = array(
			Model::GPT_4O      => array(
				'input'  => 5,
				'output' => 15,
			),
			Model::GPT_4O_MINI => array(
				'input'  => 0.15,
				'output' => 0.6,
			),
		);

		if ( ! isset( $pricing[ $model ] ) ) {
			return 0;
		}

		$input_cost  = ( $input_tokens / self::TOKEN_SCALE ) * $pricing[ $model ]['input'];
		$output_cost = ( $output_tokens / self::TOKEN_SCALE ) * $pricing[ $model ]['output'];

		return (float) ( $input_cost + $output_cost );
	}

	/**
	 * @param string $period day|week|month.
	 * @return array{DateTimeImmutable, DateTimeImmutable}
	 */
	private static function get_period_range( $period ) {
		$period = is_string( $period ) ? strtolower( $period ) : 'month';
		$now    = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );

		switch ( $period ) {
			case 'day':
				$start = $now->setTime( 0, 0, 0 );
				break;
			case 'week':
				$start = $now->sub( new DateInterval( 'P6D' ) )->setTime( 0, 0, 0 );
				break;
			case 'month':
			default:
				$start = $now->modify( 'first day of this month' )->setTime( 0, 0, 0 );
				break;
		}

		$cutoff = $now->sub( new DateInterval( 'P' . self::RETENTION_DAYS . 'D' ) );
		if ( $start < $cutoff ) {
			$start = $cutoff->setTime( 0, 0, 0 );
		}

		return array( $start, $now );
	}

	/**
	 * Remove entries older than retention window.
	 *
	 * @return void
	 */
	private static function purge_old_rows() {
		global $wpdb;
		if ( ! $wpdb ) {
			return;
		}

		$cutoff = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )
			->sub( new DateInterval( 'P' . self::RETENTION_DAYS . 'D' ) )
			->format( 'Y-m-d H:i:s' );

		$table = self::get_table_name();
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < %s",
				$cutoff
			)
		);
	}
}
