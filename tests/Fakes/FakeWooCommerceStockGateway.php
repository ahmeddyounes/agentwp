<?php
/**
 * Fake WooCommerce stock gateway for testing.
 *
 * @package AgentWP\Tests\Fakes
 */

namespace AgentWP\Tests\Fakes;

use AgentWP\Contracts\WooCommerceStockGatewayInterface;

/**
 * In-memory stock gateway for testing.
 */
final class FakeWooCommerceStockGateway implements WooCommerceStockGatewayInterface {

	/**
	 * Stored products.
	 *
	 * @var array<int, object>
	 */
	private array $products = array();

	/**
	 * Stock update history.
	 *
	 * @var array
	 */
	private array $stockUpdateHistory = array();

	/**
	 * Whether stock updates should fail.
	 *
	 * @var bool
	 */
	private bool $failStockUpdate = false;

	/**
	 * Add a product to the gateway.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $name       Product name.
	 * @param string $sku        Product SKU.
	 * @param int    $stock      Current stock quantity.
	 * @return self
	 */
	public function addProduct( int $product_id, string $name, string $sku = '', int $stock = 0 ): self {
		$gateway = $this;

		$this->products[ $product_id ] = new class( $product_id, $name, $sku, $stock, $gateway ) extends \stdClass {
			private int $id;
			private string $name;
			private string $sku;
			private ?int $stock;
			private FakeWooCommerceStockGateway $gateway;

			public function __construct( int $id, string $name, string $sku, int $stock, FakeWooCommerceStockGateway $gateway ) {
				$this->id      = $id;
				$this->name    = $name;
				$this->sku     = $sku;
				$this->stock   = $stock;
				$this->gateway = $gateway;
			}

			public function get_id(): int {
				return $this->id;
			}

			public function get_name(): string {
				return $this->name;
			}

			public function get_sku(): string {
				return $this->sku;
			}

			public function get_stock_quantity(): ?int {
				return $this->stock;
			}

			public function set_stock_quantity( int $quantity ): void {
				$this->stock = $quantity;
			}
		};

		return $this;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_product( int $product_id ): ?object {
		return $this->products[ $product_id ] ?? null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_products( array $args ): array {
		$results = array();

		foreach ( $this->products as $product ) {
			// Apply search filter if provided.
			if ( ! empty( $args['s'] ) ) {
				$search = strtolower( $args['s'] );
				$name   = strtolower( $product->get_name() );
				$sku    = strtolower( $product->get_sku() );

				if ( ! str_contains( $name, $search ) && ! str_contains( $sku, $search ) ) {
					continue;
				}
			}

			$results[] = $product;
		}

		// Apply limit if provided.
		if ( ! empty( $args['limit'] ) && $args['limit'] > 0 ) {
			$results = array_slice( $results, 0, $args['limit'] );
		}

		return $results;
	}

	/**
	 * {@inheritDoc}
	 */
	public function update_product_stock( object|int $product, int $quantity ): int|bool {
		if ( $this->failStockUpdate ) {
			return false;
		}

		$product_id = is_int( $product ) ? $product : $product->get_id();

		if ( ! isset( $this->products[ $product_id ] ) ) {
			return false;
		}

		$productObj = $this->products[ $product_id ];
		$old_stock  = $productObj->get_stock_quantity();

		$productObj->set_stock_quantity( $quantity );

		$this->stockUpdateHistory[] = array(
			'product_id' => $product_id,
			'old_stock'  => $old_stock,
			'new_stock'  => $quantity,
		);

		return $quantity;
	}

	// Test helpers.

	/**
	 * Set stock updates to fail.
	 *
	 * @param bool $fail Whether to fail.
	 * @return self
	 */
	public function setFailStockUpdate( bool $fail ): self {
		$this->failStockUpdate = $fail;
		return $this;
	}

	/**
	 * Get stock update history.
	 *
	 * @return array
	 */
	public function getStockUpdateHistory(): array {
		return $this->stockUpdateHistory;
	}

	/**
	 * Get the current stock of a product.
	 *
	 * @param int $product_id Product ID.
	 * @return int|null
	 */
	public function getProductStock( int $product_id ): ?int {
		$product = $this->products[ $product_id ] ?? null;
		return $product ? $product->get_stock_quantity() : null;
	}

	/**
	 * Clear all data.
	 *
	 * @return void
	 */
	public function clear(): void {
		$this->products           = array();
		$this->stockUpdateHistory = array();
		$this->failStockUpdate    = false;
	}
}
