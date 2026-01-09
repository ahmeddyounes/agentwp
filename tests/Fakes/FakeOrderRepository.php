<?php
/**
 * Fake order repository for testing.
 *
 * @package AgentWP\Tests\Fakes
 */

namespace AgentWP\Tests\Fakes;

use AgentWP\Contracts\OrderRepositoryInterface;
use AgentWP\DTO\OrderDTO;
use AgentWP\DTO\OrderQuery;

/**
 * In-memory order repository for testing.
 */
final class FakeOrderRepository implements OrderRepositoryInterface {

	/**
	 * Stored orders.
	 *
	 * @var array<int, OrderDTO>
	 */
	private array $orders = array();

	/**
	 * Add an order to the repository.
	 *
	 * @param OrderDTO $order The order to add.
	 * @return self
	 */
	public function addOrder( OrderDTO $order ): self {
		$this->orders[ $order->id ] = $order;
		return $this;
	}

	/**
	 * Add multiple orders.
	 *
	 * @param OrderDTO[] $orders Orders to add.
	 * @return self
	 */
	public function addOrders( array $orders ): self {
		foreach ( $orders as $order ) {
			$this->addOrder( $order );
		}
		return $this;
	}

	/**
	 * {@inheritDoc}
	 */
	public function find( int $orderId ): ?OrderDTO {
		return $this->orders[ $orderId ] ?? null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function query( OrderQuery $query ): array {
		$results = $this->filterOrders( $query );

		// Apply sorting.
		usort( $results, function ( OrderDTO $a, OrderDTO $b ) use ( $query ) {
			$field = $query->orderBy;
			$aVal  = $this->getOrderField( $a, $field );
			$bVal  = $this->getOrderField( $b, $field );

			$cmp = $aVal <=> $bVal;
			return 'DESC' === strtoupper( $query->order ) ? -$cmp : $cmp;
		});

		// Apply limit (treat negative as 0).
		$limit = max( 0, $query->limit );
		return array_slice( $results, 0, $limit );
	}

	/**
	 * {@inheritDoc}
	 */
	public function queryIds( OrderQuery $query ): array {
		$orders = $this->query( $query );
		return array_map( fn( OrderDTO $order ) => $order->id, $orders );
	}

	/**
	 * {@inheritDoc}
	 */
	public function count( OrderQuery $query ): int {
		$results = $this->filterOrders( $query );
		return count( $results );
	}

	/**
	 * {@inheritDoc}
	 */
	public function getRecentForCustomer( int $customerId, int $limit = 5 ): array {
		$query = new OrderQuery(
			customerId: $customerId,
			limit: $limit,
			orderBy: 'date',
			order: 'DESC'
		);

		return $this->query( $query );
	}

	/**
	 * Filter orders based on query criteria.
	 *
	 * @param OrderQuery $query Query parameters.
	 * @return OrderDTO[]
	 */
	private function filterOrders( OrderQuery $query ): array {
		$results = array_values( $this->orders );

		// Filter by order ID.
		if ( null !== $query->orderId ) {
			$results = array_filter(
				$results,
				fn( OrderDTO $o ) => $o->id === $query->orderId
			);
		}

		// Filter by email.
		if ( null !== $query->email ) {
			$results = array_filter(
				$results,
				fn( OrderDTO $o ) => strtolower( $o->customerEmail ) === strtolower( $query->email )
			);
		}

		// Filter by status.
		if ( null !== $query->status ) {
			$results = array_filter(
				$results,
				fn( OrderDTO $o ) => $o->status === $query->status
			);
		}

		// Filter by customer ID.
		if ( null !== $query->customerId ) {
			$results = array_filter(
				$results,
				fn( OrderDTO $o ) => $o->customerId === $query->customerId
			);
		}

		// Filter by date range.
		if ( null !== $query->dateRange ) {
			$results = array_filter(
				$results,
				fn( OrderDTO $o ) => null !== $o->dateCreated && $query->dateRange->contains( $o->dateCreated )
			);
		}

		// Filter by search.
		if ( null !== $query->search && '' !== $query->search ) {
			$search  = strtolower( $query->search );
			$results = array_filter(
				$results,
				fn( OrderDTO $o ) => str_contains( strtolower( $o->customerName ), $search )
					|| str_contains( strtolower( $o->customerEmail ), $search )
					|| str_contains( (string) $o->id, $search )
			);
		}

		return array_values( $results );
	}

	/**
	 * Get a field value for sorting.
	 *
	 * @param OrderDTO $order The order.
	 * @param string   $field The field name.
	 * @return mixed
	 */
	private function getOrderField( OrderDTO $order, string $field ): mixed {
		return match ( $field ) {
			'date'   => $order->dateCreated?->getTimestamp() ?? 0,
			'total'  => $order->total,
			'status' => $order->status,
			'id'     => $order->id,
			default  => $order->id,
		};
	}

	// Test helpers.

	/**
	 * Get all orders.
	 *
	 * @return OrderDTO[]
	 */
	public function getAll(): array {
		return array_values( $this->orders );
	}

	/**
	 * Get order count.
	 *
	 * @return int
	 */
	public function getOrderCount(): int {
		return count( $this->orders );
	}

	/**
	 * Clear all orders.
	 *
	 * @return void
	 */
	public function clear(): void {
		$this->orders = array();
	}

	/**
	 * Remove an order by ID.
	 *
	 * @param int $orderId The order ID.
	 * @return void
	 */
	public function remove( int $orderId ): void {
		unset( $this->orders[ $orderId ] );
	}
}
