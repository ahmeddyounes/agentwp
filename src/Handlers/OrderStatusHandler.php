<?php
/**
 * Handle order status draft preparation and confirmation.
 *
 * @package AgentWP
 */

namespace AgentWP\Handlers;

use AgentWP\AI\Response;
use AgentWP\Plugin;

class OrderStatusHandler {
	const DRAFT_TYPE = 'status_update';
	const MAX_BULK   = 50;

	/**
	 * Handle order status requests.
	 *
	 * @param array $args Request args.
	 * @return Response
	 */
	public function handle( array $args ): Response {
		if ( isset( $args['draft_id'] ) ) {
			return $this->confirm_status_update( $args['draft_id'] );
		}

		if ( isset( $args['order_ids'] ) ) {
			return $this->prepare_bulk_status_update( $args );
		}

		return $this->prepare_status_update( $args );
	}

	/**
	 * Prepare a draft order status update without applying it.
	 *
	 * @param array $args Request args.
	 * @return Response
	 */
	public function prepare_status_update( array $args ): Response {
		if ( ! function_exists( 'wc_get_order' ) || ! function_exists( 'wc_get_order_statuses' ) ) {
			return Response::error( 'WooCommerce is required to prepare status updates.', 400 );
		}

		$order_id = isset( $args['order_id'] ) ? absint( $args['order_id'] ) : 0;
		if ( 0 === $order_id ) {
			return Response::error( 'Missing order ID for status update.', 400 );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return Response::error( 'Order not found for status update.', 404 );
		}

		$new_status = isset( $args['new_status'] ) ? $this->normalize_status( $args['new_status'] ) : '';
		if ( '' === $new_status ) {
			return Response::error( 'Missing new status for update.', 400 );
		}

		$valid_statuses = $this->get_valid_statuses();
		if ( ! in_array( $new_status, $valid_statuses, true ) ) {
			return Response::error(
				sprintf(
					'Invalid status "%s". Valid statuses: %s.',
					$new_status,
					implode( ', ', $valid_statuses )
				),
				400
			);
		}

		$current_status = $this->normalize_status( $order->get_status() );
		if ( $new_status === $current_status ) {
			return Response::error( 'Order already has the requested status.', 400 );
		}

		$note            = isset( $args['note'] ) ? sanitize_text_field( wp_unslash( $args['note'] ) ) : '';
		$notify_customer = $this->normalize_bool( isset( $args['notify_customer'] ) ? $args['notify_customer'] : false );
		$warning         = $this->get_irreversible_warning( $new_status );

		$draft_payload = array(
			'order_id'       => $order_id,
			'current_status' => $current_status,
			'new_status'     => $new_status,
			'note'           => $note,
			'notify_customer' => $notify_customer,
			'warning'        => $warning,
			'preview'        => array(
				'transition' => $current_status . ' -> ' . $new_status,
				'warning'    => $warning,
			),
		);

		$draft_id   = $this->generate_draft_id();
		$ttl        = $this->get_draft_ttl_seconds();
		$expires_at = gmdate( 'c', time() + $ttl );
		$stored     = $this->store_draft(
			$draft_id,
			array(
				'id'         => $draft_id,
				'type'       => self::DRAFT_TYPE,
				'payload'    => $draft_payload,
				'expires_at' => $expires_at,
			),
			$ttl
		);

		if ( ! $stored ) {
			return Response::error( 'Unable to store status update draft.', 500 );
		}

		return Response::success(
			array(
				'draft_id'   => $draft_id,
				'draft'      => $draft_payload,
				'expires_at' => $expires_at,
			)
		);
	}

	/**
	 * Prepare a draft bulk status update without applying it.
	 *
	 * @param array $args Request args.
	 * @return Response
	 */
	public function prepare_bulk_status_update( array $args ): Response {
		if ( ! function_exists( 'wc_get_order' ) || ! function_exists( 'wc_get_order_statuses' ) ) {
			return Response::error( 'WooCommerce is required to prepare bulk status updates.', 400 );
		}

		$order_ids = $this->normalize_order_ids( isset( $args['order_ids'] ) ? $args['order_ids'] : array() );
		if ( empty( $order_ids ) ) {
			return Response::error( 'Missing order IDs for bulk status update.', 400 );
		}

		if ( count( $order_ids ) > self::MAX_BULK ) {
			return Response::error( 'Bulk status updates support up to 50 orders at a time.', 400 );
		}

		$new_status = isset( $args['new_status'] ) ? $this->normalize_status( $args['new_status'] ) : '';
		if ( '' === $new_status ) {
			return Response::error( 'Missing new status for bulk update.', 400 );
		}

		$valid_statuses = $this->get_valid_statuses();
		if ( ! in_array( $new_status, $valid_statuses, true ) ) {
			return Response::error(
				sprintf(
					'Invalid status "%s". Valid statuses: %s.',
					$new_status,
					implode( ', ', $valid_statuses )
				),
				400
			);
		}

		$missing_orders = array();
		$invalid_orders = array();
		$previews       = array();

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				$missing_orders[] = $order_id;
				continue;
			}

			$current_status = $this->normalize_status( $order->get_status() );
			if ( $current_status === $new_status ) {
				$invalid_orders[] = $order_id;
				continue;
			}

			$previews[] = array(
				'order_id'       => $order_id,
				'current_status' => $current_status,
				'new_status'     => $new_status,
				'transition'     => $current_status . ' -> ' . $new_status,
			);
		}

		if ( ! empty( $missing_orders ) ) {
			return Response::error(
				sprintf( 'Orders not found: %s.', implode( ', ', $missing_orders ) ),
				404,
				array( 'missing_orders' => $missing_orders )
			);
		}

		if ( ! empty( $invalid_orders ) ) {
			return Response::error(
				sprintf( 'Orders already have the requested status: %s.', implode( ', ', $invalid_orders ) ),
				400,
				array( 'invalid_orders' => $invalid_orders )
			);
		}

		$warning         = $this->get_irreversible_warning( $new_status );
		$notify_customer = $this->normalize_bool( isset( $args['notify_customer'] ) ? $args['notify_customer'] : false );

		$draft_payload = array(
			'order_ids'       => $order_ids,
			'new_status'      => $new_status,
			'notify_customer' => $notify_customer,
			'warning'         => $warning,
			'preview'         => array(
				'order_count' => count( $order_ids ),
				'orders'      => $previews,
				'warning'     => $warning,
			),
		);

		$draft_id   = $this->generate_draft_id();
		$ttl        = $this->get_draft_ttl_seconds();
		$expires_at = gmdate( 'c', time() + $ttl );
		$stored     = $this->store_draft(
			$draft_id,
			array(
				'id'         => $draft_id,
				'type'       => self::DRAFT_TYPE,
				'payload'    => $draft_payload,
				'expires_at' => $expires_at,
			),
			$ttl
		);

		if ( ! $stored ) {
			return Response::error( 'Unable to store bulk status update draft.', 500 );
		}

		return Response::success(
			array(
				'draft_id'   => $draft_id,
				'draft'      => $draft_payload,
				'expires_at' => $expires_at,
			)
		);
	}

	/**
	 * Confirm and apply a draft status update.
	 *
	 * @param string $draft_id Draft identifier.
	 * @return Response
	 */
	public function confirm_status_update( $draft_id ): Response {
		if ( ! function_exists( 'wc_get_order' ) || ! function_exists( 'wc_get_order_statuses' ) ) {
			return Response::error( 'WooCommerce is required to update order statuses.', 400 );
		}

		$draft_id = is_string( $draft_id ) ? trim( $draft_id ) : '';
		if ( '' === $draft_id ) {
			return Response::error( 'Missing status update draft ID.', 400 );
		}

		$draft = $this->load_draft( $draft_id );
		if ( null === $draft ) {
			return Response::error( 'Status update draft not found or expired.', 404 );
		}

		if ( isset( $draft['type'] ) && self::DRAFT_TYPE !== $draft['type'] ) {
			return Response::error( 'Draft type mismatch for status update confirmation.', 400 );
		}

		$payload = isset( $draft['payload'] ) && is_array( $draft['payload'] ) ? $draft['payload'] : $draft;
		if ( isset( $payload['order_ids'] ) ) {
			return $this->confirm_bulk_status_update( $draft_id, $payload );
		}

		return $this->confirm_single_status_update( $draft_id, $payload );
	}

	/**
	 * @param string $draft_id Draft identifier.
	 * @param array  $payload Draft payload.
	 * @return Response
	 */
	private function confirm_single_status_update( $draft_id, array $payload ): Response {
		$order_id = isset( $payload['order_id'] ) ? absint( $payload['order_id'] ) : 0;
		if ( 0 === $order_id ) {
			return Response::error( 'Status update draft is missing the order ID.', 400 );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return Response::error( 'Order not found for status update confirmation.', 404 );
		}

		$new_status = isset( $payload['new_status'] ) ? $this->normalize_status( $payload['new_status'] ) : '';
		$valid_statuses = $this->get_valid_statuses();
		if ( '' === $new_status || ! in_array( $new_status, $valid_statuses, true ) ) {
			return Response::error( 'Draft contains an invalid target status.', 400 );
		}

		$current_status = $this->normalize_status( $order->get_status() );
		if ( $new_status === $current_status ) {
			return Response::error( 'Order already has the requested status.', 400 );
		}

		$note            = isset( $payload['note'] ) ? (string) $payload['note'] : '';
		$notify_customer = $this->normalize_bool( isset( $payload['notify_customer'] ) ? $payload['notify_customer'] : false );
		$audit_note      = $this->build_audit_note( $draft_id, $order_id, $current_status, $new_status, $note, false );

		$updated = $this->apply_status_update( $order, $new_status, $audit_note, $notify_customer );
		if ( ! $updated ) {
			return Response::error( 'Unable to apply status update.', 500 );
		}

		$this->delete_draft( $draft_id );

		return Response::success(
			array(
				'draft_id'       => $draft_id,
				'order_id'       => $order_id,
				'previous_status' => $current_status,
				'new_status'     => $new_status,
				'notified'       => $notify_customer,
			)
		);
	}

	/**
	 * @param string $draft_id Draft identifier.
	 * @param array  $payload Draft payload.
	 * @return Response
	 */
	private function confirm_bulk_status_update( $draft_id, array $payload ): Response {
		$order_ids = $this->normalize_order_ids( isset( $payload['order_ids'] ) ? $payload['order_ids'] : array() );
		if ( empty( $order_ids ) ) {
			return Response::error( 'Bulk status update draft is missing order IDs.', 400 );
		}

		if ( count( $order_ids ) > self::MAX_BULK ) {
			return Response::error( 'Bulk status updates support up to 50 orders at a time.', 400 );
		}

		$new_status = isset( $payload['new_status'] ) ? $this->normalize_status( $payload['new_status'] ) : '';
		$valid_statuses = $this->get_valid_statuses();
		if ( '' === $new_status || ! in_array( $new_status, $valid_statuses, true ) ) {
			return Response::error( 'Draft contains an invalid target status.', 400 );
		}

		$missing_orders = array();
		$invalid_orders = array();
		$orders         = array();

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				$missing_orders[] = $order_id;
				continue;
			}

			$current_status = $this->normalize_status( $order->get_status() );
			if ( $current_status === $new_status ) {
				$invalid_orders[] = $order_id;
				continue;
			}

			$orders[ $order_id ] = $order;
		}

		if ( ! empty( $missing_orders ) ) {
			return Response::error(
				sprintf( 'Orders not found: %s.', implode( ', ', $missing_orders ) ),
				404,
				array( 'missing_orders' => $missing_orders )
			);
		}

		if ( ! empty( $invalid_orders ) ) {
			return Response::error(
				sprintf( 'Orders already have the requested status: %s.', implode( ', ', $invalid_orders ) ),
				400,
				array( 'invalid_orders' => $invalid_orders )
			);
		}

		$updated_orders = array();
		$notify_customer = $this->normalize_bool( isset( $payload['notify_customer'] ) ? $payload['notify_customer'] : false );

		foreach ( $orders as $order_id => $order ) {
			$current_status = $this->normalize_status( $order->get_status() );
			$audit_note     = $this->build_audit_note( $draft_id, $order_id, $current_status, $new_status, '', true );

			$updated = $this->apply_status_update( $order, $new_status, $audit_note, $notify_customer );
			if ( $updated ) {
				$updated_orders[] = $order_id;
			}
		}

		$this->delete_draft( $draft_id );

		return Response::success(
			array(
				'draft_id'   => $draft_id,
				'order_ids'  => $order_ids,
				'new_status' => $new_status,
				'updated'    => $updated_orders,
				'notified'   => $notify_customer,
			)
		);
	}

	/**
	 * @param mixed  $order Order instance.
	 * @param string $new_status Target status.
	 * @param string $note Order note.
	 * @param bool   $notify_customer Notify flag.
	 * @return bool
	 */
	private function apply_status_update( $order, $new_status, $note, $notify_customer ) {
		if ( ! $order || ! method_exists( $order, 'update_status' ) ) {
			return false;
		}

		$notify_customer = $this->normalize_bool( $notify_customer );
		$notify_customer = apply_filters( 'agentwp_status_notify_customer', $notify_customer, $order, $new_status );

		$filter = null;
		if ( ! $notify_customer ) {
			$filter = function ( $enabled, $email ) {
				return false;
			};
			add_filter( 'woocommerce_email_enabled', $filter, 10, 2 );
		}

		$order->update_status( $new_status, $note );

		if ( $filter ) {
			remove_filter( 'woocommerce_email_enabled', $filter, 10 );
		}

		return true;
	}

	/**
	 * @param string $draft_id Draft identifier.
	 * @param int    $order_id Order ID.
	 * @param string $current_status Current status.
	 * @param string $new_status Target status.
	 * @param string $note Optional note.
	 * @param bool   $is_bulk Bulk flag.
	 * @return string
	 */
	private function build_audit_note( $draft_id, $order_id, $current_status, $new_status, $note, $is_bulk ) {
		$summary = sprintf(
			'[AgentWP] %s status update confirmed (draft %s) for order #%d. %s -> %s.',
			$is_bulk ? 'Bulk' : 'Order',
			$draft_id,
			$order_id,
			$this->get_status_label( $current_status ),
			$this->get_status_label( $new_status )
		);

		$note = trim( (string) $note );
		if ( '' !== $note ) {
			$summary .= ' Note: ' . $note . '.';
		}

		return $summary;
	}

	/**
	 * @param string $status Status slug.
	 * @return string
	 */
	private function get_status_label( $status ) {
		if ( function_exists( 'wc_get_order_status_name' ) ) {
			return wc_get_order_status_name( $status );
		}

		return $status;
	}

	/**
	 * @param mixed $value Input value.
	 * @return bool
	 */
	private function normalize_bool( $value ) {
		if ( function_exists( 'rest_sanitize_boolean' ) ) {
			return rest_sanitize_boolean( $value );
		}

		return (bool) $value;
	}

	/**
	 * @param mixed $status Raw status input.
	 * @return string
	 */
	private function normalize_status( $status ) {
		$status = is_string( $status ) ? strtolower( trim( $status ) ) : '';

		if ( '' === $status ) {
			return '';
		}

		if ( 0 === strpos( $status, 'wc-' ) ) {
			$status = substr( $status, 3 );
		}

		return sanitize_key( $status );
	}

	/**
	 * @return array
	 */
	private function get_valid_statuses() {
		$allowed = array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' );
		$normalized = array();

		if ( function_exists( 'wc_get_order_statuses' ) ) {
			$statuses = wc_get_order_statuses();
			if ( is_array( $statuses ) ) {
				foreach ( array_keys( $statuses ) as $status ) {
					$normalized_status = $this->normalize_status( $status );
					if ( '' !== $normalized_status ) {
						$normalized[] = $normalized_status;
					}
				}
			}
		}

		if ( ! empty( $normalized ) ) {
			$allowed = array_values( array_intersect( $allowed, $normalized ) );
		}

		sort( $allowed );

		return $allowed;
	}

	/**
	 * @param string $status Target status.
	 * @return string
	 */
	private function get_irreversible_warning( $status ) {
		if ( in_array( $status, array( 'cancelled', 'refunded' ), true ) ) {
			return 'This change is irreversible.';
		}

		return '';
	}

	/**
	 * @param mixed $order_ids Order ID input.
	 * @return array
	 */
	private function normalize_order_ids( $order_ids ) {
		$ids = array();

		if ( is_string( $order_ids ) ) {
			$order_ids = preg_split( '/[\s,]+/', $order_ids );
		}

		if ( ! is_array( $order_ids ) ) {
			return $ids;
		}

		foreach ( $order_ids as $order_id ) {
			$normalized = absint( $order_id );
			if ( $normalized > 0 ) {
				$ids[] = $normalized;
			}
		}

		$ids = array_values( array_unique( $ids ) );

		return $ids;
	}

	/**
	 * @return string
	 */
	private function generate_draft_id() {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}

		return uniqid( 'draft_', true );
	}

	/**
	 * @param string $draft_id Draft identifier.
	 * @return string
	 */
	private function build_draft_key( $draft_id ) {
		return Plugin::TRANSIENT_PREFIX . 'draft_' . $draft_id;
	}

	/**
	 * @return int
	 */
	private function get_draft_ttl_seconds() {
		$ttl_minutes = null;

		if ( function_exists( 'get_option' ) ) {
			$option_ttl = get_option( Plugin::OPTION_DRAFT_TTL, null );
			if ( null !== $option_ttl && '' !== $option_ttl ) {
				$ttl_minutes = intval( $option_ttl );
			}
		}

		if ( null === $ttl_minutes && function_exists( 'get_option' ) ) {
			$settings = get_option( Plugin::OPTION_SETTINGS, array() );
			if ( is_array( $settings ) && isset( $settings['draft_ttl_minutes'] ) ) {
				$ttl_minutes = intval( $settings['draft_ttl_minutes'] );
			}
		}

		if ( ! is_int( $ttl_minutes ) || $ttl_minutes <= 0 ) {
			$ttl_minutes = 10;
		}

		$minute_seconds = defined( 'MINUTE_IN_SECONDS' ) ? MINUTE_IN_SECONDS : 60;

		return $ttl_minutes * $minute_seconds;
	}

	/**
	 * @param string $draft_id Draft identifier.
	 * @param array  $draft Draft payload.
	 * @param int    $ttl_seconds Expiration seconds.
	 * @return bool
	 */
	private function store_draft( $draft_id, array $draft, $ttl_seconds ) {
		if ( ! function_exists( 'set_transient' ) ) {
			return false;
		}

		return set_transient( $this->build_draft_key( $draft_id ), $draft, $ttl_seconds );
	}

	/**
	 * @param string $draft_id Draft identifier.
	 * @return array|null
	 */
	private function load_draft( $draft_id ) {
		if ( ! function_exists( 'get_transient' ) ) {
			return null;
		}

		$draft = get_transient( $this->build_draft_key( $draft_id ) );
		if ( false === $draft || ! is_array( $draft ) ) {
			return null;
		}

		return $draft;
	}

	/**
	 * @param string $draft_id Draft identifier.
	 * @return void
	 */
	private function delete_draft( $draft_id ) {
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( $this->build_draft_key( $draft_id ) );
		}
	}
}
