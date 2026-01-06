<?php
/**
 * Handle product stock searches and updates.
 *
 * @package AgentWP
 */

namespace AgentWP\Handlers;

use AgentWP\AI\Response;
use AgentWP\Plugin;

class StockHandler {
	const DRAFT_TYPE    = 'stock_update';
	const DEFAULT_LIMIT = 10;

	/**
	 * Handle stock-related requests.
	 *
	 * @param array $args Request args.
	 * @return Response
	 */
	public function handle( array $args ): Response {
		if ( isset( $args['query'] ) || isset( $args['sku'] ) ) {
			return $this->search_products( $args );
		}

		if ( isset( $args['draft_id'] ) ) {
			return $this->confirm_stock_update( $args['draft_id'] );
		}

		return $this->prepare_stock_update( $args );
	}

	/**
	 * Search for products by name, SKU, or ID.
	 *
	 * @param array $args Search parameters.
	 * @return Response
	 */
	public function search_products( array $args ): Response {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return Response::error( 'WooCommerce is required to search products.', 400 );
		}

		$query = isset( $args['query'] ) ? sanitize_text_field( $args['query'] ) : '';
		$sku   = isset( $args['sku'] ) ? sanitize_text_field( $args['sku'] ) : '';

		if ( '' === $query && '' === $sku ) {
			return Response::error( 'Missing product search query.', 400 );
		}

		$products = $this->find_products( $query, $sku );

		return Response::success(
			array(
				'products' => $products,
				'count'    => count( $products ),
				'query'    => array(
					'query' => $query,
					'sku'   => $sku,
				),
			)
		);
	}

	/**
	 * Prepare a draft stock update without applying it.
	 *
	 * @param array $args Request args.
	 * @return Response
	 */
	public function prepare_stock_update( array $args ): Response {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return Response::error( 'WooCommerce is required to prepare stock updates.', 400 );
		}

		$product_id = isset( $args['product_id'] ) ? absint( $args['product_id'] ) : 0;
		if ( 0 === $product_id ) {
			return Response::error( 'Missing product ID for stock update.', 400 );
		}

		$operation = isset( $args['operation'] ) ? $this->normalize_operation( $args['operation'] ) : '';
		if ( '' === $operation ) {
			return Response::error( 'Missing or invalid stock operation.', 400 );
		}

		$quantity = $this->normalize_quantity( isset( $args['quantity'] ) ? $args['quantity'] : null );
		if ( null === $quantity ) {
			return Response::error( 'Stock quantity must be a non-negative integer.', 400 );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return Response::error( 'Product not found for stock update.', 404 );
		}

		$manage_stock      = method_exists( $product, 'managing_stock' ) ? $product->managing_stock() : false;
		$backorders_allowed = method_exists( $product, 'backorders_allowed' ) ? $product->backorders_allowed() : false;
		$current_stock     = $this->normalize_stock_quantity(
			method_exists( $product, 'get_stock_quantity' ) ? $product->get_stock_quantity() : null
		);
		$new_stock = $this->calculate_new_stock( $current_stock, $quantity, $operation );

		if ( 'decrease' === $operation && $new_stock < 0 && ! $backorders_allowed ) {
			return Response::error( 'Cannot decrease stock below zero unless backorders are allowed.', 400 );
		}

		$warnings = array();
		if ( ! $manage_stock && 'set' === $operation ) {
			$warnings[] = 'Stock management is disabled for this product; quantity changes may not affect availability.';
		}

		$product_payload = $this->format_product( $product, true );

		$draft_payload = array(
			'product_id'         => $product_id,
			'quantity'           => $quantity,
			'operation'          => $operation,
			'current_stock'      => $current_stock,
			'new_stock'          => $new_stock,
			'manage_stock'       => (bool) $manage_stock,
			'backorders_allowed' => (bool) $backorders_allowed,
			'product'            => $product_payload,
			'warnings'           => $warnings,
			'preview'            => array(
				'name'          => isset( $product_payload['name'] ) ? $product_payload['name'] : '',
				'sku'           => isset( $product_payload['sku'] ) ? $product_payload['sku'] : '',
				'current_stock' => $current_stock,
				'new_stock'     => $new_stock,
			),
		);

		$draft_id   = $this->generate_draft_id();
		$ttl        = $this->get_draft_ttl_seconds();
		$expires_at = gmdate( 'c', time() + $ttl );
		$stored     = $this->store_draft(
			$draft_id,
			array(
				'id'         => $draft_id,
				'type'       => self::DRAFT_TYPE,
				'payload'    => $draft_payload,
				'expires_at' => $expires_at,
			),
			$ttl
		);

		if ( ! $stored ) {
			return Response::error( 'Unable to store stock update draft.', 500 );
		}

		return Response::success(
			array(
				'draft_id'   => $draft_id,
				'draft'      => $draft_payload,
				'expires_at' => $expires_at,
			)
		);
	}

	/**
	 * Confirm and apply a draft stock update.
	 *
	 * @param string $draft_id Draft identifier.
	 * @return Response
	 */
	public function confirm_stock_update( $draft_id ): Response {
		if ( ! function_exists( 'wc_get_product' ) || ! function_exists( 'wc_update_product_stock' ) ) {
			return Response::error( 'WooCommerce is required to update product stock.', 400 );
		}

		$draft_id = is_string( $draft_id ) ? trim( $draft_id ) : '';
		if ( '' === $draft_id ) {
			return Response::error( 'Missing stock update draft ID.', 400 );
		}

		$draft = $this->load_draft( $draft_id );
		if ( null === $draft ) {
			return Response::error( 'Stock update draft not found or expired.', 404 );
		}

		if ( isset( $draft['type'] ) && self::DRAFT_TYPE !== $draft['type'] ) {
			return Response::error( 'Draft type mismatch for stock update confirmation.', 400 );
		}

		$payload = isset( $draft['payload'] ) && is_array( $draft['payload'] ) ? $draft['payload'] : $draft;

		$product_id = isset( $payload['product_id'] ) ? absint( $payload['product_id'] ) : 0;
		if ( 0 === $product_id ) {
			return Response::error( 'Stock update draft is missing the product ID.', 400 );
		}

		$operation = isset( $payload['operation'] ) ? $this->normalize_operation( $payload['operation'] ) : '';
		if ( '' === $operation ) {
			return Response::error( 'Stock update draft has an invalid operation.', 400 );
		}

		$quantity = $this->normalize_quantity( isset( $payload['quantity'] ) ? $payload['quantity'] : null );
		if ( null === $quantity ) {
			return Response::error( 'Stock update draft has an invalid quantity.', 400 );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return Response::error( 'Product not found for stock update confirmation.', 404 );
		}

		$manage_stock      = method_exists( $product, 'managing_stock' ) ? $product->managing_stock() : false;
		$backorders_allowed = method_exists( $product, 'backorders_allowed' ) ? $product->backorders_allowed() : false;
		$current_stock     = $this->normalize_stock_quantity(
			method_exists( $product, 'get_stock_quantity' ) ? $product->get_stock_quantity() : null
		);
		$new_stock = $this->calculate_new_stock( $current_stock, $quantity, $operation );

		if ( 'decrease' === $operation && $new_stock < 0 && ! $backorders_allowed ) {
			return Response::error( 'Cannot decrease stock below zero unless backorders are allowed.', 400 );
		}

		$updated_stock = wc_update_product_stock( $product, $quantity, $operation );
		if ( is_wp_error( $updated_stock ) ) {
			return Response::error( $updated_stock->get_error_message(), 400 );
		}
		if ( false === $updated_stock ) {
			return Response::error( 'Unable to update product stock.', 500 );
		}

		$this->delete_draft( $draft_id );

		$refreshed = wc_get_product( $product_id );
		$product_payload = $refreshed ? $this->format_product( $refreshed, true ) : array();

		return Response::success(
			array(
				'draft_id'       => $draft_id,
				'product_id'     => $product_id,
				'previous_stock' => $current_stock,
				'new_stock'      => is_numeric( $updated_stock ) ? intval( $updated_stock ) : $new_stock,
				'manage_stock'   => (bool) $manage_stock,
				'product'        => $product_payload,
			)
		);
	}

	/**
	 * @param string $query Search string.
	 * @param string $sku SKU search.
	 * @return array
	 */
	private function find_products( $query, $sku ) {
		$products = array();

		$sku = trim( (string) $sku );
		if ( '' !== $sku && function_exists( 'wc_get_product_id_by_sku' ) ) {
			$product_id = absint( wc_get_product_id_by_sku( $sku ) );
			if ( $product_id > 0 ) {
				$product = wc_get_product( $product_id );
				if ( $product ) {
					$products[] = $this->format_product( $product, true );
				}
			}

			return $products;
		}

		$query = trim( (string) $query );
		if ( '' === $query ) {
			return $products;
		}

		$maybe_id = absint( $query );
		if ( $maybe_id > 0 ) {
			$product = wc_get_product( $maybe_id );
			if ( $product ) {
				$products[] = $this->format_product( $product, true );
				return $products;
			}
		}

		if ( function_exists( 'wc_get_product_id_by_sku' ) ) {
			$product_id = absint( wc_get_product_id_by_sku( $query ) );
			if ( $product_id > 0 ) {
				$product = wc_get_product( $product_id );
				if ( $product ) {
					$products[] = $this->format_product( $product, true );
					return $products;
				}
			}
		}

		if ( ! function_exists( 'get_posts' ) ) {
			return $products;
		}

		$matches = get_posts(
			array(
				'post_type'      => array( 'product' ),
				'post_status'    => array( 'publish', 'private' ),
				's'              => $query,
				'posts_per_page' => self::DEFAULT_LIMIT,
				'fields'         => 'ids',
			)
		);

		if ( ! is_array( $matches ) ) {
			return $products;
		}

		foreach ( $matches as $product_id ) {
			$product_id = absint( $product_id );
			if ( 0 === $product_id ) {
				continue;
			}

			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				continue;
			}

			$products[] = $this->format_product( $product, true );
		}

		return $products;
	}

	/**
	 * @param mixed $product Product instance.
	 * @param bool  $include_variations Whether to include variations.
	 * @return array
	 */
	private function format_product( $product, $include_variations = false ) {
		if ( ! $product || ! method_exists( $product, 'get_id' ) ) {
			return array();
		}

		$stock_quantity = method_exists( $product, 'get_stock_quantity' ) ? $product->get_stock_quantity() : null;
		$sku            = method_exists( $product, 'get_sku' ) ? $product->get_sku() : '';
		$stock_status   = method_exists( $product, 'get_stock_status' ) ? $product->get_stock_status() : '';

		$payload = array(
			'id'                 => intval( $product->get_id() ),
			'name'               => sanitize_text_field( $this->get_product_name( $product ) ),
			'sku'                => sanitize_text_field( (string) $sku ),
			'current_stock'      => $this->normalize_stock_quantity( $stock_quantity ),
			'stock_status'       => sanitize_text_field( (string) $stock_status ),
			'manage_stock'       => method_exists( $product, 'managing_stock' ) ? (bool) $product->managing_stock() : false,
			'backorders_allowed' => method_exists( $product, 'backorders_allowed' ) ? (bool) $product->backorders_allowed() : false,
		);

		if ( method_exists( $product, 'is_type' ) && $product->is_type( 'variation' ) && method_exists( $product, 'get_parent_id' ) ) {
			$payload['parent_id'] = intval( $product->get_parent_id() );
		}

		if ( $include_variations && method_exists( $product, 'is_type' ) && $product->is_type( 'variable' ) ) {
			$payload['variations'] = $this->format_variations( $product );
		}

		return $payload;
	}

	/**
	 * @param mixed $product Variable product.
	 * @return array
	 */
	private function format_variations( $product ) {
		if ( ! $product || ! method_exists( $product, 'get_children' ) ) {
			return array();
		}

		$variations = array();
		$children   = $product->get_children();

		if ( ! is_array( $children ) ) {
			return $variations;
		}

		foreach ( $children as $variation_id ) {
			$variation_id = absint( $variation_id );
			if ( 0 === $variation_id ) {
				continue;
			}

			$variation = wc_get_product( $variation_id );
			if ( ! $variation ) {
				continue;
			}

			$variations[] = $this->format_product( $variation, false );
		}

		return $variations;
	}

	/**
	 * @param mixed $product Product instance.
	 * @return string
	 */
	private function get_product_name( $product ) {
		if ( method_exists( $product, 'get_formatted_name' ) ) {
			return (string) $product->get_formatted_name();
		}

		if ( method_exists( $product, 'get_name' ) ) {
			return (string) $product->get_name();
		}

		return '';
	}

	/**
	 * @param mixed $quantity Raw quantity.
	 * @return int
	 */
	private function normalize_stock_quantity( $quantity ) {
		if ( is_numeric( $quantity ) ) {
			return intval( $quantity );
		}

		return 0;
	}

	/**
	 * @param mixed $operation Raw operation.
	 * @return string
	 */
	private function normalize_operation( $operation ) {
		$operation = is_string( $operation ) ? strtolower( trim( $operation ) ) : '';
		$allowed   = array( 'set', 'increase', 'decrease' );

		if ( in_array( $operation, $allowed, true ) ) {
			return $operation;
		}

		return '';
	}

	/**
	 * @param mixed $quantity Raw quantity.
	 * @return int|null
	 */
	private function normalize_quantity( $quantity ) {
		if ( null === $quantity || '' === $quantity ) {
			return null;
		}

		if ( is_int( $quantity ) ) {
			return $quantity >= 0 ? $quantity : null;
		}

		$validated = filter_var(
			$quantity,
			FILTER_VALIDATE_INT,
			array(
				'options' => array(
					'min_range' => 0,
				),
			)
		);

		if ( false === $validated ) {
			return null;
		}

		return intval( $validated );
	}

	/**
	 * @param int    $current_stock Current stock.
	 * @param int    $quantity Quantity.
	 * @param string $operation Operation.
	 * @return int
	 */
	private function calculate_new_stock( $current_stock, $quantity, $operation ) {
		switch ( $operation ) {
			case 'increase':
				return $current_stock + $quantity;
			case 'decrease':
				return $current_stock - $quantity;
			case 'set':
			default:
				return $quantity;
		}
	}

	/**
	 * @return string
	 */
	private function generate_draft_id() {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}

		return uniqid( 'draft_', true );
	}

	/**
	 * @param string $draft_id Draft identifier.
	 * @return string
	 */
	private function build_draft_key( $draft_id ) {
		return Plugin::TRANSIENT_PREFIX . 'draft_' . $draft_id;
	}

	/**
	 * @return int
	 */
	private function get_draft_ttl_seconds() {
		$ttl_minutes = null;

		if ( function_exists( 'get_option' ) ) {
			$option_ttl = get_option( Plugin::OPTION_DRAFT_TTL, null );
			if ( null !== $option_ttl && '' !== $option_ttl ) {
				$ttl_minutes = intval( $option_ttl );
			}
		}

		if ( null === $ttl_minutes && function_exists( 'get_option' ) ) {
			$settings = get_option( Plugin::OPTION_SETTINGS, array() );
			if ( is_array( $settings ) && isset( $settings['draft_ttl_minutes'] ) ) {
				$ttl_minutes = intval( $settings['draft_ttl_minutes'] );
			}
		}

		if ( ! is_int( $ttl_minutes ) || $ttl_minutes <= 0 ) {
			$ttl_minutes = 10;
		}

		$minute_seconds = defined( 'MINUTE_IN_SECONDS' ) ? MINUTE_IN_SECONDS : 60;

		return $ttl_minutes * $minute_seconds;
	}

	/**
	 * @param string $draft_id Draft identifier.
	 * @param array  $draft Draft payload.
	 * @param int    $ttl_seconds Expiration seconds.
	 * @return bool
	 */
	private function store_draft( $draft_id, array $draft, $ttl_seconds ) {
		if ( ! function_exists( 'set_transient' ) ) {
			return false;
		}

		return set_transient( $this->build_draft_key( $draft_id ), $draft, $ttl_seconds );
	}

	/**
	 * @param string $draft_id Draft identifier.
	 * @return array|null
	 */
	private function load_draft( $draft_id ) {
		if ( ! function_exists( 'get_transient' ) ) {
			return null;
		}

		$draft = get_transient( $this->build_draft_key( $draft_id ) );
		if ( false === $draft || ! is_array( $draft ) ) {
			return null;
		}

		return $draft;
	}

	/**
	 * @param string $draft_id Draft identifier.
	 * @return void
	 */
	private function delete_draft( $draft_id ) {
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( $this->build_draft_key( $draft_id ) );
		}
	}
}
