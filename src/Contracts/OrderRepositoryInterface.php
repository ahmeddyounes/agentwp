<?php
/**
 * Order repository interface.
 *
 * @package AgentWP\Contracts
 */

namespace AgentWP\Contracts;

use AgentWP\DTO\OrderDTO;
use AgentWP\DTO\OrderQuery;

/**
 * Contract for WooCommerce order repository.
 */
interface OrderRepositoryInterface {

	/**
	 * Find an order by ID.
	 *
	 * @param int $orderId The order ID.
	 * @return OrderDTO|null The order or null if not found.
	 */
	public function find( int $orderId ): ?OrderDTO;

	/**
	 * Query orders with criteria.
	 *
	 * @param OrderQuery $query The query parameters.
	 * @return OrderDTO[] Array of matching orders.
	 */
	public function query( OrderQuery $query ): array;

	/**
	 * Get order IDs matching criteria.
	 *
	 * @param OrderQuery $query The query parameters.
	 * @return int[] Array of order IDs.
	 */
	public function queryIds( OrderQuery $query ): array;

	/**
	 * Count orders matching criteria.
	 *
	 * @param OrderQuery $query The query parameters.
	 * @return int Count of matching orders.
	 */
	public function count( OrderQuery $query ): int;

	/**
	 * Get recent orders for a customer.
	 *
	 * @param int $customerId Customer ID.
	 * @param int $limit      Maximum orders to return.
	 * @return OrderDTO[] Array of orders.
	 */
	public function getRecentForCustomer( int $customerId, int $limit = 5 ): array;
}
