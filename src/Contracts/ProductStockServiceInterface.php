<?php
/**
 * Product stock service interface.
 *
 * @package AgentWP\Contracts
 */

namespace AgentWP\Contracts;

use AgentWP\DTO\ServiceResult;

/**
 * Interface for product stock operations.
 */
interface ProductStockServiceInterface {

	/**
	 * Search products by query string.
	 *
	 * @param string $query Search query.
	 * @return array Array of product results with id, name, sku, stock.
	 */
	public function search_products( string $query ): array;

	/**
	 * Prepare a stock update draft.
	 *
	 * @param int    $product_id Product ID.
	 * @param int    $quantity   Quantity value.
	 * @param string $operation  Operation type: 'set', 'increase', or 'decrease'.
	 * @return ServiceResult Result with draft_id on success or error.
	 */
	public function prepare_update( int $product_id, int $quantity, string $operation = 'set' ): ServiceResult;

	/**
	 * Confirm and execute a stock update.
	 *
	 * @param string $draft_id Draft ID.
	 * @return ServiceResult Result with success message or error.
	 */
	public function confirm_update( string $draft_id ): ServiceResult;
}
