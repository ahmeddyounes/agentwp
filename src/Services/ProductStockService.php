<?php
/**
 * Product stock service.
 *
 * @package AgentWP\Services
 */

namespace AgentWP\Services;

use AgentWP\Plugin;
use Exception;

class ProductStockService {
	const DRAFT_TYPE = 'stock_update';

	/**
	 * Search products.
	 *
	 * @param string $query Query string.
	 * @return array
	 */
	public function search_products( $query ) {
		// Basic search logic
		if ( ! function_exists( 'wc_get_products' ) ) return array();
		
		$args = array(
			'limit' => 5,
			's' => $query,
		);
		$products = wc_get_products( $args );
		$results = array();
		
		foreach ( $products as $product ) {
			$results[] = array(
				'id' => $product->get_id(),
				'name' => $product->get_name(),
				'sku' => $product->get_sku(),
				'stock' => $product->get_stock_quantity(),
			);
		}
		return $results;
	}

	/**
	 * Prepare stock update.
	 */
	public function prepare_update( $product_id, $quantity, $operation = 'set' ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return array( 'error' => 'Product not found.' );
		}

		$current = $product->get_stock_quantity();
		$new = $current;

		if ( 'set' === $operation ) {
			$new = $quantity;
		} elseif ( 'increase' === $operation ) {
			$new = $current + $quantity;
		} elseif ( 'decrease' === $operation ) {
			$new = max( 0, $current - $quantity );
		}

		$draft_payload = array(
			'product_id' => $product_id,
			'quantity'   => $new, // Target quantity
			'original'   => $current,
			'preview'    => array(
				'product' => $product->get_name(),
				'change'  => "{$current} -> {$new}",
			),
		);

		$draft_id = 'stock_' . wp_generate_password( 12, false );
		$this->store_draft( $draft_id, $draft_payload );

		return array(
			'success' => true,
			'draft_id' => $draft_id,
			'draft' => $draft_payload,
		);
	}

	/**
	 * Confirm update.
	 */
	public function confirm_update( $draft_id ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return array( 'error' => 'Permission denied.' );
		}

		$draft = $this->claim_draft( $draft_id );
		if ( ! $draft ) {
			return array( 'error' => 'Draft expired.' );
		}

		$product_id = $draft['product_id'];
		$quantity   = $draft['quantity'];

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return array( 'error' => 'Product not found.' );
		}

		wc_update_product_stock( $product, $quantity );

		return array(
			'success' => true,
			'message' => "Stock updated for {$product->get_name()}.",
		);
	}

	private function store_draft( $id, $data ) {
		$key = Plugin::TRANSIENT_PREFIX . 'stock_' . get_current_user_id() . '_' . $id;
		set_transient( $key, $data, 3600 );
	}

	private function claim_draft( $id ) {
		$key = Plugin::TRANSIENT_PREFIX . 'stock_' . get_current_user_id() . '_' . $id;
		$data = get_transient( $key );
		if ( $data ) delete_transient( $key );
		return $data;
	}
}
