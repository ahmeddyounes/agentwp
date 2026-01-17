<?php
/**
 * Order status service.
 *
 * @package AgentWP\Services
 */

namespace AgentWP\Services;

use AgentWP\Contracts\DraftManagerInterface;
use AgentWP\Contracts\OrderStatusServiceInterface;
use AgentWP\Contracts\PolicyInterface;
use AgentWP\Contracts\WooCommerceOrderGatewayInterface;
use AgentWP\DTO\ServiceResult;

class OrderStatusService implements OrderStatusServiceInterface {
	const DRAFT_TYPE = 'status';
	const MAX_BULK   = 50;

	private DraftManagerInterface $draftManager;
	private PolicyInterface $policy;
	private WooCommerceOrderGatewayInterface $orderGateway;

	/**
	 * Constructor.
	 *
	 * @param DraftManagerInterface            $draftManager Unified draft manager.
	 * @param PolicyInterface                  $policy       Policy for capability checks.
	 * @param WooCommerceOrderGatewayInterface $orderGateway WooCommerce order gateway.
	 */
	public function __construct(
		DraftManagerInterface $draftManager,
		PolicyInterface $policy,
		WooCommerceOrderGatewayInterface $orderGateway
	) {
		$this->draftManager = $draftManager;
		$this->policy       = $policy;
		$this->orderGateway = $orderGateway;
	}

	/**
	 * Prepare a draft order status update.
	 *
	 * @param int    $order_id        Order ID.
	 * @param string $new_status      Target status.
	 * @param string $note            Optional note.
	 * @param bool   $notify_customer Whether to notify.
	 * @return ServiceResult Result.
	 */
	public function prepare_update( int $order_id, string $new_status, string $note = '', bool $notify_customer = false ): ServiceResult {
		if ( ! $this->policy->canUpdateOrderStatus() ) {
			return ServiceResult::permissionDenied();
		}

		$order = $this->orderGateway->get_order( $order_id );
		if ( ! $order ) {
			return ServiceResult::notFound( 'Order', $order_id );
		}

		$new_status = $this->normalize_status( $new_status );
		if ( '' === $new_status ) {
			return ServiceResult::invalidInput( 'Invalid status.' );
		}

		$valid_statuses = $this->get_valid_statuses();
		if ( ! in_array( $new_status, $valid_statuses, true ) ) {
			return ServiceResult::invalidInput( 'Invalid status slug.' );
		}

		$current_status = $this->normalize_status( $order->get_status() );
		if ( $new_status === $current_status ) {
			return ServiceResult::invalidState( 'Order already has this status.' );
		}

		$warning = $this->get_irreversible_warning( $new_status );

		$payload = array(
			'order_id'        => $order_id,
			'current_status'  => $current_status,
			'new_status'      => $new_status,
			'note'            => $note,
			'notify_customer' => $notify_customer,
			'warning'         => $warning,
		);

		$preview = array(
			'transition' => $current_status . ' -> ' . $new_status,
			'warning'    => $warning,
		);

		$result = $this->draftManager->create( self::DRAFT_TYPE, $payload, $preview );

		if ( $result->isFailure() ) {
			return $result;
		}

		return ServiceResult::success(
			"Status update prepared for Order #{$order_id}: {$current_status} -> {$new_status}.",
			array(
				'draft_id' => $result->get( 'draft_id' ),
				'draft'    => array_merge( $payload, array( 'preview' => $preview ) ),
			)
		);
	}

	/**
	 * Prepare a bulk status update.
	 *
	 * @param array  $order_ids       Order IDs.
	 * @param string $new_status      Target status.
	 * @param bool   $notify_customer Whether to notify.
	 * @return ServiceResult Result.
	 */
	public function prepare_bulk_update( array $order_ids, string $new_status, bool $notify_customer = false ): ServiceResult {
		if ( ! $this->policy->canUpdateOrderStatus() ) {
			return ServiceResult::permissionDenied();
		}

		if ( empty( $order_ids ) ) {
			return ServiceResult::invalidInput( 'No orders specified.' );
		}

		if ( count( $order_ids ) > self::MAX_BULK ) {
			return ServiceResult::failure(
				ServiceResult::CODE_LIMIT_EXCEEDED,
				'Too many orders. Max ' . self::MAX_BULK . '.',
				400
			);
		}

		$new_status = $this->normalize_status( $new_status );
		$valid_statuses = $this->get_valid_statuses();
		if ( ! in_array( $new_status, $valid_statuses, true ) ) {
			return ServiceResult::invalidInput( 'Invalid status.' );
		}

		$previews = array();
		$warning = $this->get_irreversible_warning( $new_status );

		foreach ( $order_ids as $id ) {
			$order = $this->orderGateway->get_order( $id );
			if ( ! $order ) continue;

			$previews[] = array(
				'id' => $id,
				'current' => $order->get_status(),
				'new' => $new_status,
			);
		}

		$payload = array(
			'order_ids'       => $order_ids,
			'new_status'      => $new_status,
			'notify_customer' => $notify_customer,
			'warning'         => $warning,
		);

		$preview = array(
			'count'   => count( $previews ),
			'details' => $previews,
		);

		$result = $this->draftManager->create( self::DRAFT_TYPE, $payload, $preview );

		if ( $result->isFailure() ) {
			return $result;
		}

		return ServiceResult::success(
			"Bulk status update prepared for " . count( $order_ids ) . " orders to {$new_status}.",
			array(
				'draft_id' => $result->get( 'draft_id' ),
				'draft'    => array_merge( $payload, array( 'preview' => $preview ) ),
			)
		);
	}

	/**
	 * Confirm and execute a status update draft.
	 *
	 * @param string $draft_id Draft ID.
	 * @return ServiceResult Result.
	 */
	public function confirm_update( string $draft_id ): ServiceResult {
		if ( ! $this->policy->canUpdateOrderStatus() ) {
			return ServiceResult::permissionDenied();
		}

		$claimResult = $this->draftManager->claim( self::DRAFT_TYPE, $draft_id );
		if ( $claimResult->isFailure() ) {
			return ServiceResult::draftExpired( 'Draft not found or expired.' );
		}

		$payload = $claimResult->get( 'payload' );

		// Handle Bulk
		if ( isset( $payload['order_ids'] ) ) {
			return $this->process_bulk_update( $payload );
		}

		// Handle Single
		$order_id = $payload['order_id'] ?? 0;
		$new_status = $payload['new_status'] ?? '';
		$note = $payload['note'] ?? '';
		$notify = $payload['notify_customer'] ?? false;

		$order = $this->orderGateway->get_order( $order_id );
		if ( ! $order ) {
			return ServiceResult::notFound( 'Order', $order_id );
		}

		$updated = $this->apply_status_update( $order, $new_status, $note, $notify );
		if ( ! $updated ) {
			return ServiceResult::operationFailed( 'Update failed.' );
		}

		return ServiceResult::success(
			"Order #{$order_id} updated to {$new_status}.",
			array(
				'order_id'   => $order_id,
				'new_status' => $new_status,
			)
		);
	}

	private function process_bulk_update( array $payload ): ServiceResult {
		$ids    = isset( $payload['order_ids'] ) && is_array( $payload['order_ids'] ) ? $payload['order_ids'] : array();
		$status = isset( $payload['new_status'] ) ? (string) $payload['new_status'] : '';
		$notify = isset( $payload['notify_customer'] ) ? (bool) $payload['notify_customer'] : false;

		$updated = array();

		if ( empty( $ids ) || '' === $status ) {
			return ServiceResult::invalidInput( 'Invalid bulk update payload.' );
		}

		// Suppress emails for bulk updates when not notifying.
		if ( ! $notify ) {
			$this->orderGateway->set_emails_enabled( false );
		}

		try {
			foreach ( $ids as $id ) {
				$id = (int) $id;
				if ( $id <= 0 ) {
					continue;
				}

				$order = $this->orderGateway->get_order( $id );
				if ( ! $order ) {
					continue;
				}

				if ( $this->orderGateway->update_order_status( $order, $status, '' ) ) {
					$updated[] = $id;
				}
			}
		} finally {
			if ( ! $notify ) {
				$this->orderGateway->set_emails_enabled( true );
			}
		}

		return ServiceResult::success(
			count( $updated ) . ' orders updated to ' . $status . '.',
			array(
				'updated_count' => count( $updated ),
				'updated_ids'   => $updated,
				'new_status'    => $status,
			)
		);
	}

	/**
	 * Apply the status update.
	 */
	private function apply_status_update( object $order, string $new_status, string $note, bool $notify ): bool {
		// Suppress emails if not notifying.
		if ( ! $notify ) {
			$this->orderGateway->set_emails_enabled( false );
		}

		try {
			return $this->orderGateway->update_order_status( $order, $new_status, $note );
		} finally {
			if ( ! $notify ) {
				$this->orderGateway->set_emails_enabled( true );
			}
		}
	}

	/**
	 * Normalize WooCommerce status slug.
	 *
	 * @param string $status Raw status input.
	 * @return string Normalized status without `wc-` prefix.
	 */
	private function normalize_status( string $status ): string {
		$status = strtolower( trim( $status ) );
		if ( 0 === strpos( $status, 'wc-' ) ) {
			$status = substr( $status, 3 );
		}

		return $status;
	}

	/**
	 * @return string[]
	 */
	private function get_valid_statuses(): array {
		$statuses = $this->orderGateway->get_order_statuses();
		if ( empty( $statuses ) ) {
			return array();
		}

		// Strip 'wc-' prefix to match normalize_status() output.
		return array_map(
			function ( $status ) {
				return 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
			},
			array_keys( $statuses )
		);
	}

	private function get_irreversible_warning( string $status ): string {
		return in_array( $status, array( 'cancelled', 'refunded' ), true ) ? 'Irreversible.' : '';
	}
}
