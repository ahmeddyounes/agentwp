<?php
/**
 * Product stock service.
 *
 * @package AgentWP\Services
 */

namespace AgentWP\Services;

use AgentWP\Contracts\DraftStorageInterface;
use AgentWP\Contracts\ProductStockServiceInterface;

class ProductStockService implements ProductStockServiceInterface {
	private const DRAFT_TYPE = 'stock';

	private DraftStorageInterface $draftStorage;

	/**
	 * Constructor.
	 *
	 * @param DraftStorageInterface $draftStorage Draft storage implementation.
	 */
	public function __construct( DraftStorageInterface $draftStorage ) {
		$this->draftStorage = $draftStorage;
	}

	/**
	 * Search products.
	 *
	 * @param string $query Query string.
	 * @return array
	 */
	public function search_products( string $query ): array {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array();
		}

		$args = array(
			'limit' => 5,
			's'     => $query,
		);
		$products = wc_get_products( $args );
		$results  = array();

		foreach ( $products as $product ) {
			if ( is_int( $product ) && function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $product );
			}

			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			$stock_quantity = method_exists( $product, 'get_stock_quantity' )
				? $product->get_stock_quantity()
				: null;

			$results[] = array(
				'id'    => $product->get_id(),
				'name'  => $product->get_name(),
				'sku'   => $product->get_sku(),
				'stock' => is_numeric( $stock_quantity ) ? (int) $stock_quantity : 0,
			);
		}

		return $results;
	}

	/**
	 * Prepare stock update.
	 */
	public function prepare_update( int $product_id, int $quantity, string $operation = 'set' ): array {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return array( 'error' => 'Permission denied.' );
		}

		if ( $product_id <= 0 ) {
			return array( 'error' => 'Invalid product ID.' );
		}

		if ( $quantity < 0 ) {
			return array( 'error' => 'Quantity cannot be negative.' );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return array( 'error' => 'Product not found.' );
		}

		$current_stock = method_exists( $product, 'get_stock_quantity' ) ? $product->get_stock_quantity() : null;
		$current       = is_numeric( $current_stock ) ? (int) $current_stock : 0;
		$new           = $current;

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

		$draft_id = $this->draftStorage->generate_id( self::DRAFT_TYPE );
		$this->draftStorage->store( self::DRAFT_TYPE, $draft_id, $draft_payload );

		return array(
			'success'  => true,
			'draft_id' => $draft_id,
			'draft'    => $draft_payload,
		);
	}

	/**
	 * Confirm update.
	 */
	public function confirm_update( string $draft_id ): array {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return array( 'error' => 'Permission denied.' );
		}

		$draft = $this->draftStorage->claim( self::DRAFT_TYPE, $draft_id );
		if ( ! $draft ) {
			return array( 'error' => 'Draft expired.' );
		}

		$product_id = $draft['product_id'];
		$quantity   = $draft['quantity'];

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return array( 'error' => 'Product not found.' );
		}

		if ( ! function_exists( 'wc_update_product_stock' ) ) {
			return array( 'error' => 'Stock update unavailable.', 'code' => 500 );
		}

		wc_update_product_stock( $product, $quantity );

		return array(
			'success' => true,
			'product_id' => (int) $product_id,
			'new_stock' => (int) $quantity,
			'message' => "Stock updated for {$product->get_name()}.",
		);
	}
}
