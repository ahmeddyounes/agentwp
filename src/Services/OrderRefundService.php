<?php
/**
 * Service for handling order refunds.
 *
 * @package AgentWP\Services
 */

namespace AgentWP\Services;

use AgentWP\Contracts\DraftStorageInterface;
use AgentWP\Contracts\OrderRefundServiceInterface;
use AgentWP\Contracts\PolicyInterface;

class OrderRefundService implements OrderRefundServiceInterface {
	private const DRAFT_TYPE = 'refund';

	private DraftStorageInterface $draftStorage;
	private PolicyInterface $policy;

	/**
	 * @param DraftStorageInterface $draftStorage Draft storage implementation.
	 * @param PolicyInterface       $policy       Policy for capability checks.
	 */
	public function __construct( DraftStorageInterface $draftStorage, PolicyInterface $policy ) {
		$this->draftStorage = $draftStorage;
		$this->policy       = $policy;
	}

	/**
	 * Prepare a refund draft.
	 *
	 * @param int    $order_id      Order ID.
	 * @param float  $amount        Refund amount (optional).
	 * @param string $reason        Refund reason.
	 * @param bool   $restock_items Whether to restock items.
	 * @return array Result with draft_id or error.
	 */
	public function prepare_refund( int $order_id, ?float $amount = null, string $reason = '', bool $restock_items = true ): array {
		if ( ! $this->policy->canRefundOrders() ) {
			return array(
				'success' => false,
				'message' => 'Permission denied.',
			);
		}

		if ( $order_id <= 0 ) {
			return array(
				'success' => false,
				'message' => 'Invalid order ID.',
			);
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array(
				'success' => false,
				'message' => "Order #{$order_id} not found.",
			);
		}

		if ( $order->get_remaining_refund_amount() <= 0 ) {
			return array(
				'success' => false,
				'message' => "Order #{$order_id} is already fully refunded.",
			);
		}

		$max_refund = $order->get_remaining_refund_amount();
		$refund_amount = $amount !== null ? (float) $amount : $max_refund;

		if ( $refund_amount > $max_refund ) {
			return array(
				'success' => false,
				'message' => "Cannot refund \${$refund_amount}. Max refundable is \${$max_refund}.",
			);
		}

		$draft_id   = $this->draftStorage->generate_id( self::DRAFT_TYPE );
		$draft_data = array(
			'order_id'       => $order_id,
			'amount'         => $refund_amount,
			'reason'         => $reason,
			'restock_items'  => $restock_items,
			'created_at'     => time(),
			'currency'       => $order->get_currency(),
			'customer_name'  => $order->get_formatted_billing_full_name(),
		);

		$this->draftStorage->store( self::DRAFT_TYPE, $draft_id, $draft_data );

		return array(
			'success'  => true,
			'draft_id' => $draft_id,
			'preview'  => array(
				'order_id'      => $order_id,
				'amount'        => $refund_amount,
				'currency'      => $order->get_currency(),
				'reason'        => $reason,
				'restock_items' => $restock_items,
				'status'        => 'ready_to_confirm',
			),
			'message' => "Refund prepared for Order #{$order_id}. Amount: {$refund_amount} {$order->get_currency()}. Reply with confirmation to proceed.",
		);
	}

	/**
	 * Confirm and execute a refund.
	 *
	 * @param string $draft_id Draft identifier.
	 * @return array Result.
	 */
	public function confirm_refund( string $draft_id ): array {
		if ( ! $this->policy->canRefundOrders() ) {
			return array(
				'success' => false,
				'message' => 'Permission denied.',
			);
		}

		$data = $this->draftStorage->claim( self::DRAFT_TYPE, $draft_id );
		if ( ! $data ) {
			return array(
				'success' => false,
				'message' => 'Refund draft expired or invalid. Please request the refund again.',
			);
		}

		$order_id = $data['order_id'];
		$amount   = $data['amount'];
		$reason   = $data['reason'];
		$restock  = $data['restock_items'];

		// Create the refund.
		$result = wc_create_refund(
			array(
				'amount'         => $amount,
				'reason'         => $reason,
				'order_id'       => $order_id,
				'restock_items'  => $restock,
				'refund_payment' => true, // Attempt gateway refund
			)
		);

		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'message' => 'Refund failed: ' . $result->get_error_message(),
			);
		}

		$restocked_items = array();
		if ( $restock ) {
			$order = wc_get_order( $order_id );
			if ( $order && method_exists( $order, 'get_items' ) ) {
				foreach ( $order->get_items() as $item ) {
					if ( is_object( $item ) && method_exists( $item, 'get_product_id' ) ) {
						$product_id = (int) $item->get_product_id();
						if ( $product_id > 0 ) {
							$restocked_items[] = $product_id;
						}
					}
				}
			}
		}

		return array(
			'success'         => true,
			'confirmed'       => true,
			'order_id'        => $order_id,
			'refund_id'       => $result->get_id(),
			'restocked_items' => $restocked_items,
			'message'         => "Refund #{$result->get_id()} processed successfully for Order #{$order_id}.",
		);
	}
}
