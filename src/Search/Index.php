<?php
/**
 * Search index management.
 *
 * @package AgentWP
 */

namespace AgentWP\Search;

class Index {
	const TABLE           = 'agentwp_search_index';
	const VERSION         = '1.0';
	const VERSION_OPTION  = 'agentwp_search_index_version';
	const STATE_OPTION    = 'agentwp_search_index_state';
	const DEFAULT_LIMIT   = 5;
	const BACKFILL_LIMIT  = 200;
	const BACKFILL_WINDOW = 0.35;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'ensure_table' ) );
		add_action( 'init', array( __CLASS__, 'maybe_backfill' ), 15 );

		add_action( 'save_post_product', array( __CLASS__, 'handle_product_save' ), 10, 3 );
		add_action( 'save_post_shop_order', array( __CLASS__, 'handle_order_save' ), 10, 3 );
		add_action( 'before_delete_post', array( __CLASS__, 'handle_post_delete' ) );
		add_action( 'user_register', array( __CLASS__, 'handle_user_register' ) );
		add_action( 'profile_update', array( __CLASS__, 'handle_user_update' ), 10, 2 );
	}

	/**
	 * Plugin activation handler for the search index.
	 *
	 * @return void
	 */
	public static function activate() {
		self::ensure_table();
		update_option( self::VERSION_OPTION, self::VERSION, false );
		self::maybe_reset_state();
	}

	/**
	 * Ensure search index table exists.
	 *
	 * @return void
	 */
	public static function ensure_table() {
		global $wpdb;

		$installed_version = get_option( self::VERSION_OPTION, '' );
		$table             = self::get_table_name();

		if ( $installed_version === self::VERSION && self::table_exists( $table ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			type varchar(32) NOT NULL,
			object_id bigint(20) unsigned NOT NULL,
			primary_text varchar(255) NOT NULL,
			secondary_text varchar(255) NOT NULL DEFAULT '',
			search_text longtext NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY type_object (type, object_id),
			KEY type_idx (type),
			KEY object_idx (object_id),
			FULLTEXT KEY search_fulltext (search_text, primary_text, secondary_text)
		) {$charset_collate};";

		dbDelta( $sql );

		update_option( self::VERSION_OPTION, self::VERSION, false );
		self::maybe_reset_state();
	}

	/**
	 * Search indexed data.
	 *
	 * @param string $query Search query.
	 * @param array  $types Types to search.
	 * @param int    $limit Result limit.
	 * @return array
	 */
	public static function search( $query, array $types, $limit = self::DEFAULT_LIMIT ) {
		$limit  = max( 1, absint( $limit ) );
		$query  = sanitize_text_field( (string) $query );
		$query  = trim( $query );
		$types  = self::normalize_types( $types );
		$result = array();

		if ( '' === $query ) {
			foreach ( $types as $type ) {
				$result[ $type ] = array();
			}
			return $result;
		}

		self::ensure_table();
		self::maybe_backfill();

		foreach ( $types as $type ) {
			$result[ $type ] = self::search_type( $type, $query, $limit );
		}

		return $result;
	}

	/**
	 * Handle product save.
	 *
	 * @param int     $post_id Post ID.
	 * @param \WP_Post $post Post instance.
	 * @param bool    $update Whether this is an existing post being updated.
	 * @return void
	 */
	public static function handle_product_save( $post_id, $post, $update ) {
		if ( ! self::should_handle_post_save( $post_id, $post ) ) {
			return;
		}

		self::index_product( $post_id );
	}

	/**
	 * Handle order save.
	 *
	 * @param int     $post_id Post ID.
	 * @param \WP_Post $post Post instance.
	 * @param bool    $update Whether this is an existing post being updated.
	 * @return void
	 */
	public static function handle_order_save( $post_id, $post, $update ) {
		if ( ! self::should_handle_post_save( $post_id, $post ) ) {
			return;
		}

		self::index_order( $post_id );
	}

	/**
	 * Remove index entry when a post is deleted.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function handle_post_delete( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		$type = '';
		if ( 'product' === $post->post_type ) {
			$type = 'products';
		} elseif ( 'shop_order' === $post->post_type ) {
			$type = 'orders';
		}

		if ( '' === $type ) {
			return;
		}

		self::delete_index_entry( $type, $post_id );
	}

	/**
	 * Handle user registration.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public static function handle_user_register( $user_id ) {
		self::index_customer( $user_id );
	}

	/**
	 * Handle user profile updates.
	 *
	 * @param int     $user_id User ID.
	 * @param \WP_User $old_user_data Previous user data.
	 * @return void
	 */
	public static function handle_user_update( $user_id, $old_user_data ) {
		self::index_customer( $user_id );
	}

	/**
	 * Index a product.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	public static function index_product( $product_id ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product || ! method_exists( $product, 'get_id' ) ) {
			return;
		}

		$name = (string) $product->get_name();
		$sku  = (string) $product->get_sku();

		$primary   = $name ? $name : sprintf( 'Product #%d', $product->get_id() );
		$secondary = $sku ? $sku : '';

		$search_text = self::normalize_text(
			implode(
				' ',
				array_filter(
					array(
						$name,
						$sku,
						(string) $product->get_id(),
					)
				)
			)
		);

		self::upsert_index_entry( 'products', $product->get_id(), $primary, $secondary, $search_text );
	}

	/**
	 * Index an order.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public static function index_order( $order_id ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || ! method_exists( $order, 'get_id' ) ) {
			return;
		}

		$order_id     = $order->get_id();
		$customer     = trim( $order->get_formatted_billing_full_name() );
		$email        = (string) $order->get_billing_email();
		$status       = (string) $order->get_status();
		$status_label = function_exists( 'wc_get_order_status_name' ) ? wc_get_order_status_name( $status ) : $status;

		$primary   = sprintf( 'Order #%d', $order_id );
		$secondary = $status_label ? $status_label : '';

		$search_text = self::normalize_text(
			implode(
				' ',
				array_filter(
					array(
						(string) $order_id,
						$primary,
						$customer,
						$email,
						$status_label,
					)
				)
			)
		);

		self::upsert_index_entry( 'orders', $order_id, $primary, $secondary, $search_text );
	}

	/**
	 * Index a customer.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public static function index_customer( $user_id ) {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return;
		}

		if ( ! in_array( 'customer', (array) $user->roles, true ) ) {
			self::delete_index_entry( 'customers', $user_id );
			return;
		}

		$name = trim( $user->display_name );
		if ( '' === $name ) {
			$first = (string) get_user_meta( $user_id, 'first_name', true );
			$last  = (string) get_user_meta( $user_id, 'last_name', true );
			$name  = trim( $first . ' ' . $last );
		}

		$primary   = $name ? $name : sprintf( 'Customer #%d', $user_id );
		$secondary = (string) $user->user_email;

		$search_text = self::normalize_text(
			implode(
				' ',
				array_filter(
					array(
						(string) $user_id,
						$name,
						$user->user_email,
					)
				)
			)
		);

		self::upsert_index_entry( 'customers', $user_id, $primary, $secondary, $search_text );
	}

	/**
	 * Query the index for a specific type.
	 *
	 * @param string $type Result type.
	 * @param string $query Search query.
	 * @param int    $limit Result limit.
	 * @return array
	 */
	private static function search_type( $type, $query, $limit ) {
		global $wpdb;

		$limit      = max( 1, absint( $limit ) );
		$table      = self::get_table_name();
		$normalized = self::normalize_text( $query );
		$rows       = array();

		if ( self::supports_fulltext() && strlen( $normalized ) >= 3 ) {
			$against = self::build_fulltext_query( $normalized );
			$sql     = $wpdb->prepare(
				"SELECT type, object_id, primary_text, secondary_text FROM {$table}
				WHERE type = %s AND MATCH(search_text, primary_text, secondary_text) AGAINST (%s IN BOOLEAN MODE)
				LIMIT %d",
				$type,
				$against,
				$limit
			);
			$rows    = $wpdb->get_results( $sql, ARRAY_A );
		} else {
			$like = '%' . $wpdb->esc_like( $normalized ) . '%';
			$sql  = $wpdb->prepare(
				"SELECT type, object_id, primary_text, secondary_text FROM {$table}
				WHERE type = %s AND search_text LIKE %s
				LIMIT %d",
				$type,
				$like,
				$limit
			);
			$rows = $wpdb->get_results( $sql, ARRAY_A );
		}

		if ( empty( $rows ) && ! self::is_backfill_complete( $type ) ) {
			$rows = self::search_source( $type, $query, $limit );
		}

		return self::format_results( $type, $rows );
	}

	/**
	 * Search data sources directly as a fallback.
	 *
	 * @param string $type Result type.
	 * @param string $query Search query.
	 * @param int    $limit Result limit.
	 * @return array
	 */
	private static function search_source( $type, $query, $limit ) {
		$results = array();

		if ( 'products' === $type && function_exists( 'wc_get_products' ) ) {
			$args = array(
				'limit'  => $limit,
				'status' => array( 'publish', 'private' ),
				's'      => $query,
			);

			$products = wc_get_products( $args );
			if ( is_array( $products ) ) {
				foreach ( $products as $product ) {
					if ( ! $product || ! method_exists( $product, 'get_id' ) ) {
						continue;
					}
					$results[] = array(
						'type'           => 'products',
						'object_id'      => $product->get_id(),
						'primary_text'   => (string) $product->get_name(),
						'secondary_text' => (string) $product->get_sku(),
					);
					self::index_product( $product->get_id() );
				}
			}

			if ( count( $results ) < $limit ) {
				$sku_id = absint( wc_get_product_id_by_sku( $query ) );
				if ( $sku_id ) {
					self::index_product( $sku_id );
					$results[] = array(
						'type'           => 'products',
						'object_id'      => $sku_id,
						'primary_text'   => (string) get_the_title( $sku_id ),
						'secondary_text' => (string) get_post_meta( $sku_id, '_sku', true ),
					);
				}
			}
		}

		if ( 'orders' === $type && function_exists( 'wc_get_order' ) && function_exists( 'wc_get_orders' ) ) {
			$order_id = absint( $query );
			if ( $order_id ) {
				$order = wc_get_order( $order_id );
				if ( $order ) {
					self::index_order( $order_id );
					$results[] = array(
						'type'           => 'orders',
						'object_id'      => $order_id,
						'primary_text'   => sprintf( 'Order #%d', $order_id ),
						'secondary_text' => function_exists( 'wc_get_order_status_name' ) ? wc_get_order_status_name( $order->get_status() ) : (string) $order->get_status(),
					);
				}
			}
		}

		if ( 'customers' === $type ) {
			$users = get_users(
				array(
					'number'  => $limit,
					'search'  => '*' . $query . '*',
					'role'    => 'customer',
					'orderby' => 'display_name',
				)
			);
			if ( is_array( $users ) ) {
				foreach ( $users as $user ) {
					if ( ! $user ) {
						continue;
					}
					self::index_customer( $user->ID );
					$results[] = array(
						'type'           => 'customers',
						'object_id'      => $user->ID,
						'primary_text'   => (string) $user->display_name,
						'secondary_text' => (string) $user->user_email,
					);
				}
			}
		}

		return $results;
	}

	/**
	 * Format raw index rows for output.
	 *
	 * @param string $type Result type.
	 * @param array  $rows Raw rows.
	 * @return array
	 */
	private static function format_results( $type, array $rows ) {
		$results = array();

		foreach ( $rows as $row ) {
			$object_id = isset( $row['object_id'] ) ? absint( $row['object_id'] ) : 0;
			$primary   = isset( $row['primary_text'] ) ? (string) $row['primary_text'] : '';
			$secondary = isset( $row['secondary_text'] ) ? (string) $row['secondary_text'] : '';

			if ( ! $object_id ) {
				continue;
			}

			$results[] = array(
				'id'        => $object_id,
				'type'      => $type,
				'primary'   => $primary,
				'secondary' => $secondary,
				'query'     => self::build_query_string( $type, $object_id, $primary, $secondary ),
			);
		}

		return $results;
	}

	/**
	 * Build a structured query string for an indexed item.
	 *
	 * @param string $type Type key.
	 * @param int    $object_id Object ID.
	 * @param string $primary Primary text.
	 * @param string $secondary Secondary text.
	 * @return string
	 */
	private static function build_query_string( $type, $object_id, $primary, $secondary ) {
		if ( 'products' === $type ) {
			if ( '' !== $secondary ) {
				return sprintf( 'product:%d sku:"%s"', $object_id, $secondary );
			}
			return sprintf( 'product:%d "%s"', $object_id, $primary );
		}

		if ( 'orders' === $type ) {
			return sprintf( 'order:%d', $object_id );
		}

		if ( 'customers' === $type ) {
			if ( '' !== $secondary ) {
				return sprintf( 'customer:"%s"', $secondary );
			}
			return sprintf( 'customer:%d', $object_id );
		}

		return (string) $object_id;
	}

	/**
	 * Remove an index entry.
	 *
	 * @param string $type Type key.
	 * @param int    $object_id Object ID.
	 * @return void
	 */
	private static function delete_index_entry( $type, $object_id ) {
		global $wpdb;

		$table = self::get_table_name();
		$wpdb->delete(
			$table,
			array(
				'type'      => $type,
				'object_id' => absint( $object_id ),
			),
			array( '%s', '%d' )
		);
	}

	/**
	 * Upsert an index entry.
	 *
	 * @param string $type Type key.
	 * @param int    $object_id Object ID.
	 * @param string $primary Primary label.
	 * @param string $secondary Secondary label.
	 * @param string $search_text Search text.
	 * @return void
	 */
	private static function upsert_index_entry( $type, $object_id, $primary, $secondary, $search_text ) {
		global $wpdb;

		$table = self::get_table_name();

		$wpdb->replace(
			$table,
			array(
				'type'           => $type,
				'object_id'      => absint( $object_id ),
				'primary_text'   => sanitize_text_field( $primary ),
				'secondary_text' => sanitize_text_field( $secondary ),
				'search_text'    => $search_text,
				'updated_at'     => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Determine if post save should be handled.
	 *
	 * @param int     $post_id Post ID.
	 * @param \WP_Post $post Post instance.
	 * @return bool
	 */
	private static function should_handle_post_save( $post_id, $post ) {
		if ( ! $post || ! is_object( $post ) ) {
			return false;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return false;
		}

		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Normalize type inputs.
	 *
	 * @param array $types Type inputs.
	 * @return array
	 */
	private static function normalize_types( array $types ) {
		$allowed = array( 'products', 'orders', 'customers' );
		$types   = array_map( 'sanitize_text_field', $types );
		$types   = array_filter( $types );
		$types   = array_values( array_intersect( $types, $allowed ) );

		if ( empty( $types ) ) {
			return $allowed;
		}

		return $types;
	}

	/**
	 * Normalize text for searching.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private static function normalize_text( $text ) {
		$text = wp_strip_all_tags( (string) $text );
		$text = remove_accents( $text );
		$text = strtolower( $text );
		$text = preg_replace( '/\s+/', ' ', $text );

		return trim( $text );
	}

	/**
	 * Build fulltext query string.
	 *
	 * @param string $normalized Normalized query.
	 * @return string
	 */
	private static function build_fulltext_query( $normalized ) {
		$tokens = preg_split( '/\s+/', $normalized );
		$tokens = array_filter( $tokens );

		if ( empty( $tokens ) ) {
			return $normalized;
		}

		$parts = array();
		foreach ( $tokens as $token ) {
			$token = preg_replace( '/[^a-z0-9@._\-]/', '', $token );
			if ( '' === $token ) {
				continue;
			}
			$parts[] = '+' . $token . '*';
		}

		return $parts ? implode( ' ', $parts ) : $normalized;
	}

	/**
	 * Detect if fulltext index is available.
	 *
	 * @return bool
	 */
	private static function supports_fulltext() {
		static $supported = null;

		if ( null !== $supported ) {
			return $supported;
		}

		global $wpdb;

		$table = self::get_table_name();
		if ( ! self::table_exists( $table ) ) {
			$supported = false;
			return $supported;
		}

		$indexes = $wpdb->get_results(
			$wpdb->prepare(
				'SHOW INDEX FROM %i WHERE Index_type = %s',
				$table,
				'FULLTEXT'
			),
			ARRAY_A
		);
		$supported = ! empty( $indexes );

		return $supported;
	}

	/**
	 * Backfill index incrementally.
	 *
	 * @return void
	 */
	public static function maybe_backfill() {
		if ( ! is_admin() ) {
			return;
		}

		$state = self::get_state();
		$start = microtime( true );

		foreach ( array( 'products', 'orders', 'customers' ) as $type ) {
			if ( self::is_backfill_complete( $type, $state ) ) {
				continue;
			}

			$state = self::backfill_type( $type, $state );

			if ( microtime( true ) - $start >= self::BACKFILL_WINDOW ) {
				break;
			}
		}

		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * Backfill entries for a single type.
	 *
	 * @param string $type Type key.
	 * @param array  $state Current state.
	 * @return array
	 */
	private static function backfill_type( $type, array $state ) {
		$cursor = isset( $state[ $type ] ) ? (int) $state[ $type ] : 0;
		$ids    = self::fetch_ids( $type, $cursor, self::BACKFILL_LIMIT );

		if ( empty( $ids ) ) {
			$state[ $type ] = -1;
			return $state;
		}

		foreach ( $ids as $id ) {
			if ( 'products' === $type ) {
				self::index_product( $id );
			} elseif ( 'orders' === $type ) {
				self::index_order( $id );
			} elseif ( 'customers' === $type ) {
				self::index_customer( $id );
			}
		}

		$state[ $type ] = max( $ids );
		return $state;
	}

	/**
	 * Fetch IDs for backfill.
	 *
	 * @param string $type Type key.
	 * @param int    $after_id Start after ID.
	 * @param int    $limit Limit.
	 * @return array
	 */
	private static function fetch_ids( $type, $after_id, $limit ) {
		global $wpdb;

		$limit = max( 1, absint( $limit ) );

		if ( 'products' === $type ) {
			return $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts}
					WHERE post_type = %s
					AND post_status IN ('publish', 'private', 'draft')
					AND ID > %d
					ORDER BY ID ASC
					LIMIT %d",
					'product',
					$after_id,
					$limit
				)
			);
		}

		if ( 'orders' === $type ) {
			return $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts}
					WHERE post_type = %s
					AND post_status NOT IN ('trash', 'auto-draft')
					AND ID > %d
					ORDER BY ID ASC
					LIMIT %d",
					'shop_order',
					$after_id,
					$limit
				)
			);
		}

		if ( 'customers' === $type ) {
			return $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->users}
					WHERE ID > %d
					ORDER BY ID ASC
					LIMIT %d",
					$after_id,
					$limit
				)
			);
		}

		return array();
	}

	/**
	 * Get index state from options.
	 *
	 * @return array
	 */
	private static function get_state() {
		$state = get_option( self::STATE_OPTION, array() );
		$state = is_array( $state ) ? $state : array();

		foreach ( array( 'products', 'orders', 'customers' ) as $type ) {
			if ( ! isset( $state[ $type ] ) ) {
				$state[ $type ] = 0;
			}
		}

		return $state;
	}

	/**
	 * Reset state if missing.
	 *
	 * @return void
	 */
	private static function maybe_reset_state() {
		$state = get_option( self::STATE_OPTION, null );
		if ( null === $state ) {
			update_option(
				self::STATE_OPTION,
				array(
					'products'  => 0,
					'orders'    => 0,
					'customers' => 0,
				),
				false
			);
		}
	}

	/**
	 * Check if backfill is complete.
	 *
	 * @param string $type Type key.
	 * @param array  $state Optional state.
	 * @return bool
	 */
	private static function is_backfill_complete( $type, array $state = array() ) {
		if ( empty( $state ) ) {
			$state = self::get_state();
		}

		return isset( $state[ $type ] ) && intval( $state[ $type ] ) === -1;
	}

	/**
	 * Get table name with prefix.
	 *
	 * @return string
	 */
	private static function get_table_name() {
		global $wpdb;

		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Check if table exists.
	 *
	 * @param string $table Table name.
	 * @return bool
	 */
	private static function table_exists( $table ) {
		global $wpdb;

		$like = $wpdb->esc_like( $table );
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
	}
}
