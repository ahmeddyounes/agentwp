<?php
/**
 * WooCommerce order repository adapter.
 *
 * @package AgentWP\Infrastructure
 */

namespace AgentWP\Infrastructure;

use AgentWP\Contracts\OrderRepositoryInterface;
use AgentWP\DTO\OrderDTO;
use AgentWP\DTO\OrderQuery;

/**
 * Wraps WooCommerce order query functions.
 */
final class WooCommerceOrderRepository implements OrderRepositoryInterface {

	/**
	 * {@inheritDoc}
	 */
	public function find( int $orderId ): ?OrderDTO {
		$order = wc_get_order( $orderId );

		if ( ! $order || ! ( $order instanceof \WC_Order ) ) {
			return null;
		}

		return OrderDTO::fromWcOrder( $order );
	}

	/**
	 * {@inheritDoc}
	 */
	public function query( OrderQuery $query ): array {
		$args = $this->buildQueryArgs( $query );

		$orders = wc_get_orders( $args );

		// wc_get_orders() can return non-array on error.
		if ( ! is_array( $orders ) ) {
			return array();
		}

		return array_map(
			fn( $order ) => OrderDTO::fromWcOrder( $order ),
			$orders
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function queryIds( OrderQuery $query ): array {
		$args           = $this->buildQueryArgs( $query );
		$args['return'] = 'ids';

		$ids = wc_get_orders( $args );

		// wc_get_orders() can return non-array on error.
		return is_array( $ids ) ? $ids : array();
	}

	/**
	 * {@inheritDoc}
	 */
	public function count( OrderQuery $query ): int {
		$args            = $this->buildQueryArgs( $query );
		$args['limit']   = -1;
		$args['return']  = 'ids';
		$args['paginate'] = false;

		$ids = wc_get_orders( $args );

		// wc_get_orders() can return non-array on error.
		return is_array( $ids ) ? count( $ids ) : 0;
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
	 * Build WooCommerce query arguments from OrderQuery.
	 *
	 * @param OrderQuery $query The query parameters.
	 * @return array
	 */
	private function buildQueryArgs( OrderQuery $query ): array {
		$args = array(
			'limit'   => $query->limit,
			'offset'  => $query->offset,
			'orderby' => $this->mapOrderBy( $query->orderBy ),
			'order'   => strtoupper( $query->order ),
		);

		if ( null !== $query->orderId ) {
			$args['post__in'] = array( $query->orderId );
		}

		if ( null !== $query->email ) {
			$args['billing_email'] = $query->email;
		}

		if ( null !== $query->status ) {
			$args['status'] = $this->normalizeStatus( $query->status );
		}

		if ( null !== $query->customerId ) {
			$args['customer_id'] = $query->customerId;
		}

		if ( null !== $query->dateRange ) {
			$args['date_created'] = $query->dateRange->start->format( 'Y-m-d' ) . '...'
				. $query->dateRange->end->format( 'Y-m-d' );
		}

		if ( null !== $query->search && '' !== $query->search ) {
			// WooCommerce doesn't have a built-in search, use meta query.
			// Escape LIKE wildcards (% and _) to prevent SQL injection.
			// Wrap with % for substring matching after escaping.
			global $wpdb;
			$escaped_search = '%' . $wpdb->esc_like( $query->search ) . '%';

			$args['meta_query'] = array(
				'relation' => 'OR',
				array(
					'key'     => '_billing_first_name',
					'value'   => $escaped_search,
					'compare' => 'LIKE',
				),
				array(
					'key'     => '_billing_last_name',
					'value'   => $escaped_search,
					'compare' => 'LIKE',
				),
				array(
					'key'     => '_billing_email',
					'value'   => $escaped_search,
					'compare' => 'LIKE',
				),
			);
		}

		return $args;
	}

	/**
	 * Map order by field to WooCommerce field.
	 *
	 * @param string $field The field name.
	 * @return string
	 */
	private function mapOrderBy( string $field ): string {
		return match ( $field ) {
			'date'   => 'date',
			'total'  => 'total',
			'status' => 'status',
			'id'     => 'ID',
			default  => 'date',
		};
	}

	/**
	 * Normalize order status.
	 *
	 * @param string $status The status.
	 * @return string
	 */
	private function normalizeStatus( string $status ): string {
		// Remove 'wc-' prefix if present.
		if ( str_starts_with( $status, 'wc-' ) ) {
			return $status;
		}

		return 'wc-' . $status;
	}
}
