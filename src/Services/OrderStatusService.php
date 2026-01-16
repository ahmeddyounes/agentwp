<?php
/**
 * Order status service.
 *
 * @package AgentWP\Services
 */

namespace AgentWP\Services;

use AgentWP\Contracts\DraftStorageInterface;
use AgentWP\Contracts\OrderStatusServiceInterface;
use AgentWP\Infrastructure\TransientDraftStorage;
use Exception;

class OrderStatusService implements OrderStatusServiceInterface {
	const DRAFT_TYPE = 'status';
	const MAX_BULK   = 50;

	private DraftStorageInterface $draftStorage;

	/**
	 * Constructor.
	 *
	 * @param DraftStorageInterface|null $draftStorage Draft storage implementation.
	 */
	public function __construct( ?DraftStorageInterface $draftStorage = null ) {
		$this->draftStorage = $draftStorage ?? new TransientDraftStorage();
	}

	/**
	 * Prepare a draft order status update.
	 *
	 * @param int    $order_id        Order ID.
	 * @param string $new_status      Target status.
	 * @param string $note            Optional note.
	 * @param bool   $notify_customer Whether to notify.
	 * @return array Result.
	 */
	public function prepare_update( int $order_id, string $new_status, string $note = '', bool $notify_customer = false ): array {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return array( 'error' => 'Permission denied.', 'code' => 403 );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array( 'error' => 'Order not found.', 'code' => 404 );
		}

		$new_status = $this->normalize_status( $new_status );
		if ( '' === $new_status ) {
			return array( 'error' => 'Invalid status.', 'code' => 400 );
		}

		$valid_statuses = $this->get_valid_statuses();
		if ( ! in_array( $new_status, $valid_statuses, true ) ) {
			return array( 'error' => 'Invalid status slug.', 'code' => 400 );
		}

		$current_status = $this->normalize_status( $order->get_status() );
		if ( $new_status === $current_status ) {
			return array( 'error' => 'Order already has this status.', 'code' => 400 );
		}

		$warning = $this->get_irreversible_warning( $new_status );

		$draft_payload = array(
			'order_id'        => $order_id,
			'current_status'  => $current_status,
			'new_status'      => $new_status,
			'note'            => $note,
			'notify_customer' => $notify_customer,
			'warning'         => $warning,
			'preview'         => array(
				'transition' => $current_status . ' -> ' . $new_status,
				'warning'    => $warning,
			),
		);

		$draft_id = $this->draftStorage->generate_id( self::DRAFT_TYPE );
		$stored   = $this->draftStorage->store( self::DRAFT_TYPE, $draft_id, $draft_payload );

		if ( ! $stored ) {
			return array( 'error' => 'Failed to save draft.', 'code' => 500 );
		}

		return array(
			'success'  => true,
			'draft_id' => $draft_id,
			'draft'    => $draft_payload,
		);
	}

	/**
	 * Prepare a bulk status update.
	 *
	 * @param array  $order_ids       Order IDs.
	 * @param string $new_status      Target status.
	 * @param bool   $notify_customer Whether to notify.
	 * @return array Result.
	 */
	public function prepare_bulk_update( array $order_ids, string $new_status, bool $notify_customer = false ): array {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return array( 'error' => 'Permission denied.', 'code' => 403 );
		}

		if ( empty( $order_ids ) ) {
			return array( 'error' => 'No orders specified.', 'code' => 400 );
		}

		if ( count( $order_ids ) > self::MAX_BULK ) {
			return array( 'error' => 'Too many orders. Max ' . self::MAX_BULK . '.', 'code' => 400 );
		}

		$new_status = $this->normalize_status( $new_status );
		$valid_statuses = $this->get_valid_statuses();
		if ( ! in_array( $new_status, $valid_statuses, true ) ) {
			return array( 'error' => 'Invalid status.', 'code' => 400 );
		}

		$previews = array();
		$warning = $this->get_irreversible_warning( $new_status );

		foreach ( $order_ids as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order ) continue;
			
			$previews[] = array(
				'id' => $id,
				'current' => $order->get_status(),
				'new' => $new_status,
			);
		}

		$draft_payload = array(
			'order_ids'       => $order_ids,
			'new_status'      => $new_status,
			'notify_customer' => $notify_customer,
			'warning'         => $warning,
			'preview'         => array(
				'count'   => count( $previews ),
				'details' => $previews,
			),
		);

		$draft_id = $this->draftStorage->generate_id( self::DRAFT_TYPE );
		$this->draftStorage->store( self::DRAFT_TYPE, $draft_id, $draft_payload );

		return array(
			'success'  => true,
			'draft_id' => $draft_id,
			'draft'    => $draft_payload,
		);
	}

	/**
	 * Confirm and execute a status update draft.
	 *
	 * @param string $draft_id Draft ID.
	 * @return array Result.
	 */
	public function confirm_update( string $draft_id ): array {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return array( 'error' => 'Permission denied.', 'code' => 403 );
		}

		$draft = $this->draftStorage->claim( self::DRAFT_TYPE, $draft_id );
		if ( ! $draft ) {
			return array( 'error' => 'Draft not found or expired.', 'code' => 404 );
		}

		$payload = $draft['payload'] ?? $draft;

		// Handle Bulk
		if ( isset( $payload['order_ids'] ) ) {
			return $this->process_bulk_update( $payload );
		}

		// Handle Single
		$order_id = $payload['order_id'] ?? 0;
		$new_status = $payload['new_status'] ?? '';
		$note = $payload['note'] ?? '';
		$notify = $payload['notify_customer'] ?? false;

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array( 'error' => 'Order not found.', 'code' => 404 );
		}

		$updated = $this->apply_status_update( $order, $new_status, $note, $notify );
		if ( ! $updated ) {
			return array( 'error' => 'Update failed.', 'code' => 500 );
		}

		return array(
			'success'    => true,
			'order_id'   => $order_id,
			'new_status' => $new_status,
			'message'    => "Order #{$order_id} updated to {$new_status}.",
		);
	}

	private function process_bulk_update( $payload ) {
		$ids = $payload['order_ids'];
		$status = $payload['new_status'];
		$notify = $payload['notify_customer'];
		$updated = array();

		// Batch filter handling
		if ( ! $notify ) {
			add_filter( 'woocommerce_email_enabled', '__return_false' );
		}

		try {
			foreach ( $ids as $id ) {
				$order = wc_get_order( $id );
				if ( ! $order ) continue;

				if ( method_exists( $order, 'update_status' ) ) {
					try {
						if ( $order->update_status( $status, '' ) ) {
							$updated[] = $id;
						}
					} catch ( Exception $e ) {
						continue;
					}
				}
			}
		} finally {
			if ( ! $notify ) {
				remove_filter( 'woocommerce_email_enabled', '__return_false' );
			}
		}

		return array(
			'success' => true,
			'updated_count' => count( $updated ),
			'updated_ids' => $updated,
			'new_status' => $status,
		);
	}

	/**
	 * Apply the status update.
	 */
	private function apply_status_update( $order, $new_status, $note, $notify ) {
		if ( method_exists( $order, 'update_status' ) ) {
			// Bypass emails if not notifying
			if ( ! $notify ) {
				add_filter( 'woocommerce_email_enabled', '__return_false' );
			}
			
			try {
				$result = $order->update_status( $new_status, $note );
			} catch ( Exception $e ) {
				$result = false;
			} finally {
				if ( ! $notify ) {
					remove_filter( 'woocommerce_email_enabled', '__return_false' );
				}
			}
			
			return $result;
		}
		return false;
	}

	// ... Helpers ...
	private function normalize_status( $status ) {
		$status = is_string( $status ) ? strtolower( trim( $status ) ) : '';
		if ( 0 === strpos( $status, 'wc-' ) ) $status = substr( $status, 3 );
		return $status;
	}

	private function get_valid_statuses() {
		if ( ! function_exists( 'wc_get_order_statuses' ) ) {
			return array();
		}
		// Strip 'wc-' prefix to match normalize_status() output.
		$statuses = array_keys( wc_get_order_statuses() );
		return array_map(
			function ( $status ) {
				return 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
			},
			$statuses
		);
	}

	private function get_irreversible_warning( $status ) {
		return in_array( $status, array( 'cancelled', 'refunded' ), true ) ? 'Irreversible.' : '';
	}
}