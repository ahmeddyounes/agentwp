<?php
/**
 * Service for handling order refunds.
 *
 * @package AgentWP\Services
 */

namespace AgentWP\Services;

use AgentWP\Infrastructure\WooCommerceOrderRepository;
use AgentWP\Plugin;

class OrderRefundService {
	/**
	 * @var WooCommerceOrderRepository
	 */
	private $repository;

	/**
	 * @param WooCommerceOrderRepository|null $repository Order repository.
	 */
	public function __construct( ?WooCommerceOrderRepository $repository = null ) {
		$this->repository = $repository ? $repository : new WooCommerceOrderRepository();
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
	public function prepare_refund( $order_id, $amount = null, $reason = '', $restock_items = true ) {
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

		$draft_id   = 'refund_' . wp_generate_password( 12, false );
		$draft_data = array(
			'order_id'       => $order_id,
			'amount'         => $refund_amount,
			'reason'         => $reason,
			'restock_items'  => $restock_items,
			'created_at'     => time(),
			'currency'       => $order->get_currency(),
			'customer_name'  => $order->get_formatted_billing_full_name(),
		);

		$this->store_draft( $draft_id, $draft_data );

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
	public function confirm_refund( $draft_id ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return array(
				'success' => false,
				'message' => 'Permission denied.',
			);
		}

		$data = $this->claim_draft( $draft_id );
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
		$result = wc_create_refund( array(
			'amount'         => $amount,
			'reason'         => $reason,
			'order_id'       => $order_id,
			'restock_items'  => $restock,
			'refund_payment' => true, // Attempt gateway refund
		));

		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'message' => 'Refund failed: ' . $result->get_error_message(),
			);
		}

		return array(
			'success'   => true,
			'refund_id' => $result->get_id(),
			'message'   => "Refund #{$result->get_id()} processed successfully for Order #{$order_id}.",
		);
	}

	/**
	 * Store a draft in a transient.
	 *
	 * @param string $id Draft ID.
	 * @param array  $data Draft data.
	 * @return void
	 */
	private function store_draft( $id, $data ) {
		$key = Plugin::TRANSIENT_PREFIX . 'refund_' . get_current_user_id() . '_' . $id;
		set_transient( $key, $data, 3600 );
	}

	/**
	 * Claim and delete a draft.
	 *
	 * @param string $id Draft ID.
	 * @return array|false
	 */
	private function claim_draft( $id ) {
		$key = Plugin::TRANSIENT_PREFIX . 'refund_' . get_current_user_id() . '_' . $id;
		$data = get_transient( $key );
		if ( $data ) {
			delete_transient( $key );
			return $data;
		}
		return false;
	}
}