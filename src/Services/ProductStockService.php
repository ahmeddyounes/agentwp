<?php
/**
 * Product stock service.
 *
 * @package AgentWP\Services
 */

namespace AgentWP\Services;

use AgentWP\Contracts\AuditLoggerInterface;
use AgentWP\Contracts\DraftManagerInterface;
use AgentWP\Contracts\PolicyInterface;
use AgentWP\Contracts\ProductStockServiceInterface;
use AgentWP\Contracts\WooCommerceStockGatewayInterface;
use AgentWP\DTO\ServiceResult;

class ProductStockService implements ProductStockServiceInterface {
	private const DRAFT_TYPE = 'stock';

	private DraftManagerInterface $draftManager;
	private PolicyInterface $policy;
	private WooCommerceStockGatewayInterface $stockGateway;
	private ?AuditLoggerInterface $auditLogger;

	/**
	 * Constructor.
	 *
	 * @param DraftManagerInterface            $draftManager Unified draft manager.
	 * @param PolicyInterface                  $policy       Policy for capability checks.
	 * @param WooCommerceStockGatewayInterface $stockGateway WooCommerce stock gateway.
	 * @param AuditLoggerInterface|null        $auditLogger  Audit logger (optional).
	 */
	public function __construct(
		DraftManagerInterface $draftManager,
		PolicyInterface $policy,
		WooCommerceStockGatewayInterface $stockGateway,
		?AuditLoggerInterface $auditLogger = null
	) {
		$this->draftManager = $draftManager;
		$this->policy       = $policy;
		$this->stockGateway = $stockGateway;
		$this->auditLogger  = $auditLogger;
	}

	/**
	 * Search products.
	 *
	 * @param string $query Query string.
	 * @return array
	 */
	public function search_products( string $query ): array {
		$args = array(
			'limit' => 5,
			's'     => $query,
		);
		$products = $this->stockGateway->get_products( $args );
		$results  = array();

		foreach ( $products as $product ) {
			if ( is_int( $product ) ) {
				$product = $this->stockGateway->get_product( $product );
			}

			// Gateway returns null for invalid products.
			if ( null === $product ) {
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
	public function prepare_update( int $product_id, int $quantity, string $operation = 'set' ): ServiceResult {
		if ( ! $this->policy->canManageStock() ) {
			return ServiceResult::permissionDenied();
		}

		if ( $product_id <= 0 ) {
			return ServiceResult::invalidInput( 'Invalid product ID.' );
		}

		if ( $quantity < 0 ) {
			return ServiceResult::invalidInput( 'Quantity cannot be negative.' );
		}

		$product = $this->stockGateway->get_product( $product_id );
		if ( ! $product ) {
			return ServiceResult::notFound( 'Product', $product_id );
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

		$payload = array(
			'product_id' => $product_id,
			'quantity'   => $new, // Target quantity
			'original'   => $current,
		);

		$summary = sprintf(
			'%s: stock %d → %d',
			$product->get_name(),
			$current,
			$new
		);

		$preview = array(
			'summary'        => $summary,
			'product_id'     => $product_id,
			'product_name'   => $product->get_name(),
			'product_sku'    => $product->get_sku(),
			'original_stock' => $current,
			'new_stock'      => $new,
		);

		$result = $this->draftManager->create( self::DRAFT_TYPE, $payload, $preview );

		if ( $result->isFailure() ) {
			return $result;
		}

		return ServiceResult::success(
			"Stock update prepared for {$product->get_name()}: {$current} -> {$new}.",
			array(
				'draft_id'   => $result->get( 'draft_id' ),
				'type'       => $result->get( 'type' ),
				'preview'    => $result->get( 'preview' ),
				'expires_at' => $result->get( 'expires_at' ),
				'ttl'        => $result->get( 'ttl' ),
			)
		);
	}

	/**
	 * Confirm update.
	 */
	public function confirm_update( string $draft_id ): ServiceResult {
		if ( ! $this->policy->canManageStock() ) {
			return ServiceResult::permissionDenied();
		}

		$claimResult = $this->draftManager->claim( self::DRAFT_TYPE, $draft_id );
		if ( $claimResult->isFailure() ) {
			return ServiceResult::draftExpired( 'Draft expired.' );
		}

		$payload    = $claimResult->get( 'payload' );
		$product_id = $payload['product_id'];
		$quantity   = $payload['quantity'];
		$original   = $payload['original'] ?? null;

		$product = $this->stockGateway->get_product( $product_id );
		if ( ! $product ) {
			return ServiceResult::notFound( 'Product', $product_id );
		}

		$result = $this->stockGateway->update_product_stock( $product, $quantity );
		if ( false === $result ) {
			return ServiceResult::operationFailed( 'Stock update unavailable.' );
		}

		$this->logConfirmation( $draft_id, $product_id, $product->get_name(), $original, $quantity );

		return ServiceResult::success(
			"Stock updated for {$product->get_name()}.",
			array(
				'product_id' => (int) $product_id,
				'new_stock'  => (int) $quantity,
			)
		);
	}

	/**
	 * Log a stock update confirmation.
	 *
	 * @param string   $draft_id     Draft ID.
	 * @param int      $product_id   Product ID.
	 * @param string   $product_name Product name.
	 * @param int|null $old_stock    Original stock quantity.
	 * @param int      $new_stock    New stock quantity.
	 * @return void
	 */
	private function logConfirmation( string $draft_id, int $product_id, string $product_name, ?int $old_stock, int $new_stock ): void {
		if ( ! $this->auditLogger ) {
			return;
		}

		$this->auditLogger->logDraftConfirmation(
			self::DRAFT_TYPE,
			$draft_id,
			get_current_user_id(),
			array(
				'product_id'   => $product_id,
				'product_name' => $product_name,
				'old_stock'    => $old_stock,
				'new_stock'    => $new_stock,
			)
		);
	}
}
