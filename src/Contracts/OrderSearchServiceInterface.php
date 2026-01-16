<?php
/**
 * Order search service interface.
 *
 * @package AgentWP\Contracts
 */

namespace AgentWP\Contracts;

/**
 * Interface for order search operations.
 */
interface OrderSearchServiceInterface {

	/**
	 * Handle an order search request.
	 *
	 * @param array $args Search parameters including query, order_id, email, status, limit, date_range.
	 * @return array Search results with orders array, count, cached flag, and query summary.
	 */
	public function handle( array $args );
}
