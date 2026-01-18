<?php
/**
 * Customer Profile DTO.
 *
 * @package AgentWP\DTO
 */

namespace AgentWP\DTO;

/**
 * Immutable customer profile value object.
 *
 * Represents a complete customer profile with:
 * - Customer identification
 * - Order history metrics
 * - LTV projections
 * - Health status
 * - Favorite products and categories
 */
final class CustomerProfileDTO {

	/**
	 * Create a new CustomerProfileDTO.
	 *
	 * @param CustomerSummaryDTO           $customer             Customer identification.
	 * @param CustomerMetricsDTO           $metrics              Order history metrics.
	 * @param CustomerLtvProjectionDTO     $ltvProjection        LTV projection data.
	 * @param string                       $healthStatus         Customer health status.
	 * @param CustomerHealthThresholdsDTO  $healthThresholds     Health status thresholds.
	 * @param array<CustomerFavoriteDTO>   $favoriteProducts     Top purchased products.
	 * @param array<CustomerFavoriteDTO>   $favoriteCategories   Top purchased categories.
	 * @param array<string>                $includedStatuses     Order statuses included.
	 * @param array<OrderSummaryDTO>       $recentOrders         Recent order summaries.
	 * @param bool                         $ordersTruncated      Whether orders were truncated.
	 * @param int                          $ordersSampled        Number of orders sampled.
	 * @param int                          $ordersLimit          Maximum orders limit.
	 */
	public function __construct(
		public readonly CustomerSummaryDTO $customer,
		public readonly CustomerMetricsDTO $metrics,
		public readonly CustomerLtvProjectionDTO $ltvProjection,
		public readonly string $healthStatus,
		public readonly CustomerHealthThresholdsDTO $healthThresholds,
		public readonly array $favoriteProducts,
		public readonly array $favoriteCategories,
		public readonly array $includedStatuses,
		public readonly array $recentOrders,
		public readonly bool $ordersTruncated,
		public readonly int $ordersSampled,
		public readonly int $ordersLimit,
	) {
	}

	/**
	 * Create from raw customer profile data.
	 *
	 * @param array $data Raw customer profile data from CustomerService.
	 * @return self
	 */
	public static function fromArray( array $data ): self {
		$favoriteProducts = array();
		if ( isset( $data['favorite_products'] ) && is_array( $data['favorite_products'] ) ) {
			foreach ( $data['favorite_products'] as $product ) {
				if ( is_array( $product ) ) {
					$favoriteProducts[] = CustomerFavoriteDTO::fromArray( $product, 'product' );
				}
			}
		}

		$favoriteCategories = array();
		if ( isset( $data['favorite_categories'] ) && is_array( $data['favorite_categories'] ) ) {
			foreach ( $data['favorite_categories'] as $category ) {
				if ( is_array( $category ) ) {
					$favoriteCategories[] = CustomerFavoriteDTO::fromArray( $category, 'category' );
				}
			}
		}

		$recentOrders = array();
		if ( isset( $data['recent_orders'] ) && is_array( $data['recent_orders'] ) ) {
			foreach ( $data['recent_orders'] as $order ) {
				if ( is_array( $order ) ) {
					$recentOrders[] = OrderSummaryDTO::fromArray( $order );
				}
			}
		}

		return new self(
			customer: CustomerSummaryDTO::fromArray( isset( $data['customer'] ) && is_array( $data['customer'] ) ? $data['customer'] : array() ),
			metrics: CustomerMetricsDTO::fromArray( $data ),
			ltvProjection: CustomerLtvProjectionDTO::fromArray( $data ),
			healthStatus: isset( $data['health_status'] ) ? (string) $data['health_status'] : 'churned',
			healthThresholds: CustomerHealthThresholdsDTO::fromArray( isset( $data['health_thresholds'] ) && is_array( $data['health_thresholds'] ) ? $data['health_thresholds'] : array() ),
			favoriteProducts: $favoriteProducts,
			favoriteCategories: $favoriteCategories,
			includedStatuses: isset( $data['included_statuses'] ) && is_array( $data['included_statuses'] ) ? array_map( 'strval', $data['included_statuses'] ) : array(),
			recentOrders: $recentOrders,
			ordersTruncated: isset( $data['orders_truncated'] ) ? (bool) $data['orders_truncated'] : false,
			ordersSampled: isset( $data['orders_sampled'] ) ? (int) $data['orders_sampled'] : 0,
			ordersLimit: isset( $data['orders_limit'] ) ? (int) $data['orders_limit'] : 2000,
		);
	}

	/**
	 * Convert to array format suitable for API responses.
	 *
	 * @return array
	 */
	public function toArray(): array {
		return array_merge(
			$this->metrics->toArray(),
			array(
				'customer'            => $this->customer->toArray(),
				'health_status'       => $this->healthStatus,
				'health_thresholds'   => $this->healthThresholds->toArray(),
				'favorite_products'   => array_map( fn( CustomerFavoriteDTO $p ) => $p->toArray(), $this->favoriteProducts ),
				'favorite_categories' => array_map( fn( CustomerFavoriteDTO $c ) => $c->toArray(), $this->favoriteCategories ),
				'included_statuses'   => $this->includedStatuses,
				'recent_orders'       => array_map( fn( OrderSummaryDTO $o ) => $o->toArray(), $this->recentOrders ),
				'orders_truncated'    => $this->ordersTruncated,
				'orders_sampled'      => $this->ordersSampled,
				'orders_limit'        => $this->ordersLimit,
				'estimated_ltv'       => $this->ltvProjection->estimatedLtv,
				'estimated_ltv_formatted' => $this->ltvProjection->estimatedLtvFormatted,
				'ltv_projection'      => $this->ltvProjection->toArray()['projection'],
			)
		);
	}

	/**
	 * Check if customer is a guest.
	 *
	 * @return bool
	 */
	public function isGuest(): bool {
		return $this->customer->isGuest;
	}

	/**
	 * Check if customer is active.
	 *
	 * @return bool
	 */
	public function isActive(): bool {
		return 'active' === $this->healthStatus;
	}

	/**
	 * Check if customer is at risk of churning.
	 *
	 * @return bool
	 */
	public function isAtRisk(): bool {
		return 'at_risk' === $this->healthStatus;
	}

	/**
	 * Check if customer has churned.
	 *
	 * @return bool
	 */
	public function isChurned(): bool {
		return 'churned' === $this->healthStatus;
	}
}
