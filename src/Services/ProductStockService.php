<?php
/**
 * Product stock service.
 *
 * @package AgentWP\Services
 */

namespace AgentWP\Services;

use AgentWP\Contracts\DraftStorageInterface;
use AgentWP\Contracts\ProductStockServiceInterface;
use AgentWP\Infrastructure\TransientDraftStorage;

class ProductStockService implements ProductStockServiceInterface {
	private const DRAFT_TYPE = 'stock';

	private DraftStorageInterface $draftStorage;

	/**
	 * Constructor.
	 *
	 * @param DraftStorageInterface|null $draftStorage Draft storage implementation.
	 */
	public function __construct( ?DraftStorageInterface $draftStorage = null ) {
		$this->draftStorage = $draftStorage ?? new TransientDraftStorage();
	}

	/**
	 * Search products.
	 *
	 * @param string $query Query string.
	 * @return array
	 */
	public function search_products( string $query ): array {
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

		$draft_id = $this->draftStorage->generate_id( self::DRAFT_TYPE );
		$this->draftStorage->store( self::DRAFT_TYPE, $draft_id, $draft_payload );

		return array(
			'success' => true,
			'draft_id' => $draft_id,
			'draft' => $draft_payload,
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

		wc_update_product_stock( $product, $quantity );

		return array(
			'success' => true,
			'message' => "Stock updated for {$product->get_name()}.",
		);
	}
}
