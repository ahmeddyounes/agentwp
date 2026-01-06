<?php
/**
 * Handle refund draft preparation and confirmation.
 *
 * @package AgentWP
 */

namespace AgentWP\Handlers;

use AgentWP\AI\Response;
use AgentWP\Plugin;

class RefundHandler {
	const DRAFT_TYPE = 'refund';

	/**
	 * Handle refund-related requests.
	 *
	 * @param array $args Request args.
	 * @return Response
	 */
	public function handle( array $args ): Response {
		if ( isset( $args['draft_id'] ) ) {
			return $this->confirm_refund( $args['draft_id'] );
		}

		return $this->prepare_refund( $args );
	}

	/**
	 * Prepare a refund draft without executing it.
	 *
	 * @param array $args Request args.
	 * @return Response
	 */
	public function prepare_refund( array $args ): Response {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return Response::error( 'WooCommerce is required to prepare refunds.', 400 );
		}

		$order_id = isset( $args['order_id'] ) ? absint( $args['order_id'] ) : 0;
		if ( 0 === $order_id ) {
			return Response::error( 'Missing order ID for refund.', 400 );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return Response::error( 'Order not found for refund.', 404 );
		}

		$amount_provided = array_key_exists( 'amount', $args ) && '' !== $args['amount'] && null !== $args['amount'];
		$amount_input    = $amount_provided ? $this->normalize_amount( $args['amount'] ) : null;
		$reason          = isset( $args['reason'] ) ? sanitize_text_field( wp_unslash( $args['reason'] ) ) : '';
		$restock_items   = $this->normalize_bool( isset( $args['restock_items'] ) ? $args['restock_items'] : false );

		$order_total    = $this->normalize_amount( $order->get_total() );
		$total_refunded = $this->normalize_amount( $order->get_total_refunded() );
		$remaining      = max( 0, $this->normalize_amount( $order_total - $total_refunded ) );

		if ( $remaining <= 0 ) {
			return Response::error( 'Order has no refundable balance.', 400 );
		}

		if ( $amount_provided ) {
			if ( null === $amount_input || $amount_input <= 0 ) {
				return Response::error( 'Refund amount must be greater than zero.', 400 );
			}

			if ( $amount_input > $remaining + $this->amount_epsilon() ) {
				return Response::error( 'Refund amount exceeds remaining order total.', 400 );
			}

			$refund_amount = $this->is_full_refund( $amount_input, $remaining ) ? $remaining : $amount_input;
		} else {
			$refund_amount = $remaining;
		}

		$is_full_refund   = $this->is_full_refund( $refund_amount, $remaining );
		$items_to_restock = ( $restock_items && $is_full_refund ) ? $this->build_items_to_restock( $order ) : array();

		$payment_method = $this->get_payment_method_label( $order );
		$gateway        = $this->get_payment_gateway( $order );
		$requires_manual_refund = $this->requires_manual_refund( $gateway );

		$draft_payload = array(
			'order_id'               => $order_id,
			'order_total'            => $order_total,
			'refund_amount'          => $refund_amount,
			'reason'                 => $reason,
			'items_to_restock'       => $items_to_restock,
			'customer_email'         => $this->get_customer_email( $order ),
			'payment_method'         => $payment_method,
			'requires_manual_refund' => $requires_manual_refund,
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
			return Response::error( 'Unable to store refund draft.', 500 );
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
	 * Confirm and execute a refund from a draft.
	 *
	 * @param string $draft_id Draft identifier.
	 * @return Response
	 */
	public function confirm_refund( $draft_id ): Response {
		if ( ! function_exists( 'wc_get_order' ) || ! function_exists( 'wc_create_refund' ) ) {
			return Response::error( 'WooCommerce is required to process refunds.', 400 );
		}

		$draft_id = is_string( $draft_id ) ? trim( $draft_id ) : '';
		if ( '' === $draft_id ) {
			return Response::error( 'Missing refund draft ID.', 400 );
		}

		$draft = $this->load_draft( $draft_id );
		if ( null === $draft ) {
			return Response::error( 'Refund draft not found or expired.', 404 );
		}

		if ( isset( $draft['type'] ) && self::DRAFT_TYPE !== $draft['type'] ) {
			return Response::error( 'Draft type mismatch for refund confirmation.', 400 );
		}

		$payload = isset( $draft['payload'] ) && is_array( $draft['payload'] ) ? $draft['payload'] : $draft;
		$order_id = isset( $payload['order_id'] ) ? absint( $payload['order_id'] ) : 0;
		if ( 0 === $order_id ) {
			return Response::error( 'Refund draft is missing the order ID.', 400 );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return Response::error( 'Order not found for refund confirmation.', 404 );
		}

		$refund_amount = isset( $payload['refund_amount'] ) ? $this->normalize_amount( $payload['refund_amount'] ) : 0;
		if ( $refund_amount <= 0 ) {
			return Response::error( 'Refund amount is invalid for confirmation.', 400 );
		}

		$order_total    = $this->normalize_amount( $order->get_total() );
		$total_refunded = $this->normalize_amount( $order->get_total_refunded() );
		$remaining      = max( 0, $this->normalize_amount( $order_total - $total_refunded ) );

		if ( $remaining <= 0 ) {
			return Response::error( 'Order has no refundable balance.', 400 );
		}

		if ( $refund_amount > $remaining + $this->amount_epsilon() ) {
			return Response::error( 'Refund amount exceeds remaining order total.', 400 );
		}

		$refund_amount = $this->is_full_refund( $refund_amount, $remaining ) ? $remaining : $refund_amount;
		$reason        = isset( $payload['reason'] ) ? (string) $payload['reason'] : '';
		$requires_manual_refund = ! empty( $payload['requires_manual_refund'] );
		$items_to_restock = isset( $payload['items_to_restock'] ) && is_array( $payload['items_to_restock'] )
			? $payload['items_to_restock']
			: array();
		$should_restock = $this->is_full_refund( $refund_amount, $remaining ) && ! empty( $items_to_restock );

		$refund = wc_create_refund(
			array(
				'amount'         => $refund_amount,
				'reason'         => $reason,
				'order_id'       => $order_id,
				'refund_payment' => ! $requires_manual_refund,
				'restock_items'  => false,
			)
		);

		if ( is_wp_error( $refund ) ) {
			return Response::error( $refund->get_error_message(), 400 );
		}

		$refund_id = is_object( $refund ) && method_exists( $refund, 'get_id' ) ? $refund->get_id() : 0;
		$restocked = $should_restock ? $this->restock_items( $order, $items_to_restock ) : array();

		$this->delete_draft( $draft_id );
		$this->add_audit_note( $order, $draft_id, $refund_amount, $reason, ! empty( $restocked ) );
		$this->maybe_notify_customer( $order, $refund, $payload );

		return Response::success(
			array(
				'confirmed'              => true,
				'draft_id'               => $draft_id,
				'order_id'               => $order_id,
				'refund_id'              => $refund_id,
				'refund_amount'          => $refund_amount,
				'restocked_items'        => $restocked,
				'requires_manual_refund' => $requires_manual_refund,
			)
		);
	}

	/**
	 * @param mixed $value Input.
	 * @return bool
	 */
	private function normalize_bool( $value ) {
		if ( function_exists( 'rest_sanitize_boolean' ) ) {
			return rest_sanitize_boolean( $value );
		}

		return (bool) $value;
	}

	/**
	 * @param mixed $amount Amount input.
	 * @return float
	 */
	private function normalize_amount( $amount ) {
		$amount   = is_numeric( $amount ) ? (float) $amount : 0.0;
		$decimals = $this->get_price_decimals();

		if ( function_exists( 'wc_format_decimal' ) ) {
			return (float) wc_format_decimal( $amount, $decimals );
		}

		return round( $amount, $decimals );
	}

	/**
	 * @return int
	 */
	private function get_price_decimals() {
		if ( function_exists( 'wc_get_price_decimals' ) ) {
			return wc_get_price_decimals();
		}

		return 2;
	}

	/**
	 * @return float
	 */
	private function amount_epsilon() {
		$decimals = $this->get_price_decimals();
		return 1 / pow( 10, $decimals );
	}

	/**
	 * @param float $refund_amount Refund amount.
	 * @param float $remaining Remaining total.
	 * @return bool
	 */
	private function is_full_refund( $refund_amount, $remaining ) {
		return abs( $remaining - $refund_amount ) <= $this->amount_epsilon();
	}

	/**
	 * @param mixed $order Order instance.
	 * @return array
	 */
	private function build_items_to_restock( $order ) {
		$items = array();

		if ( ! $order || ! method_exists( $order, 'get_items' ) ) {
			return $items;
		}

		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item || ! method_exists( $item, 'get_quantity' ) ) {
				continue;
			}

			$quantity = (int) $item->get_quantity();
			if ( $quantity <= 0 ) {
				continue;
			}

			$items[] = array(
				'item_id'     => (int) $item_id,
				'product_id'  => (int) $item->get_product_id(),
				'variation_id' => (int) $item->get_variation_id(),
				'name'        => sanitize_text_field( $item->get_name() ),
				'quantity'    => $quantity,
			);
		}

		return $items;
	}

	/**
	 * @param mixed $order Order instance.
	 * @return string
	 */
	private function get_customer_email( $order ) {
		if ( ! $order || ! method_exists( $order, 'get_billing_email' ) ) {
			return '';
		}

		return sanitize_email( $order->get_billing_email() );
	}

	/**
	 * @param mixed $order Order instance.
	 * @return string
	 */
	private function get_payment_method_label( $order ) {
		if ( ! $order ) {
			return '';
		}

		if ( method_exists( $order, 'get_payment_method_title' ) ) {
			$title = (string) $order->get_payment_method_title();
			if ( '' !== $title ) {
				return $title;
			}
		}

		return method_exists( $order, 'get_payment_method' ) ? (string) $order->get_payment_method() : '';
	}

	/**
	 * @param mixed $order Order instance.
	 * @return mixed|null
	 */
	private function get_payment_gateway( $order ) {
		if ( function_exists( 'wc_get_payment_gateway_by_order' ) ) {
			return wc_get_payment_gateway_by_order( $order );
		}

		if ( ! function_exists( 'WC' ) || ! $order || ! method_exists( $order, 'get_payment_method' ) ) {
			return null;
		}

		$gateways = WC()->payment_gateways();
		if ( ! $gateways || ! method_exists( $gateways, 'payment_gateways' ) ) {
			return null;
		}

		$method_id = $order->get_payment_method();
		$all       = $gateways->payment_gateways();

		return ( $method_id && isset( $all[ $method_id ] ) ) ? $all[ $method_id ] : null;
	}

	/**
	 * @param mixed $gateway Payment gateway.
	 * @return bool
	 */
	private function requires_manual_refund( $gateway ) {
		if ( ! $gateway || ! method_exists( $gateway, 'supports' ) ) {
			return true;
		}

		return ! $gateway->supports( 'refunds' );
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

	/**
	 * @param mixed $order Order instance.
	 * @param array $items_to_restock Items to restock.
	 * @return array
	 */
	private function restock_items( $order, array $items_to_restock ) {
		$restocked = array();

		if ( ! $order || ! function_exists( 'wc_update_product_stock' ) ) {
			return $restocked;
		}

		foreach ( $items_to_restock as $item_data ) {
			$item_id  = isset( $item_data['item_id'] ) ? absint( $item_data['item_id'] ) : 0;
			$quantity = isset( $item_data['quantity'] ) ? absint( $item_data['quantity'] ) : 0;
			if ( 0 === $item_id || 0 === $quantity ) {
				continue;
			}

			$item = $order->get_item( $item_id );
			if ( ! $item || ! method_exists( $item, 'get_product' ) ) {
				continue;
			}

			$product = $item->get_product();
			if ( ! $product || ! $product->managing_stock() ) {
				continue;
			}

			wc_update_product_stock( $product, $quantity, 'increase' );
			$restocked[] = array(
				'product_id' => $product->get_id(),
				'quantity'   => $quantity,
			);
		}

		return $restocked;
	}

	/**
	 * @param mixed  $order Order instance.
	 * @param string $draft_id Draft ID.
	 * @param float  $amount Refund amount.
	 * @param string $reason Refund reason.
	 * @param bool   $restocked Restock indicator.
	 * @return void
	 */
	private function add_audit_note( $order, $draft_id, $amount, $reason, $restocked ) {
		if ( ! $order || ! method_exists( $order, 'add_order_note' ) ) {
			return;
		}

		$reason_text = '' !== $reason ? $reason : 'no reason provided';
		$amount      = $this->normalize_amount( $amount );
		$note        = sprintf(
			'[AgentWP] Refund confirmed (draft %s). Amount: %s. Reason: %s. Restocked: %s.',
			$draft_id,
			$amount,
			$reason_text,
			$restocked ? 'yes' : 'no'
		);

		$order->add_order_note( $note );
	}

	/**
	 * @param mixed $order Order instance.
	 * @param mixed $refund Refund instance.
	 * @param array $payload Draft payload.
	 * @return void
	 */
	private function maybe_notify_customer( $order, $refund, array $payload ) {
		if ( ! $order || ! $refund ) {
			return;
		}

		$notify = apply_filters( 'agentwp_refund_notify_customer', false, $refund, $order, $payload );
		if ( ! $notify ) {
			return;
		}

		if ( function_exists( 'wc_send_order_refund_notification' ) ) {
			wc_send_order_refund_notification( $order->get_id(), $refund->get_id() );
			return;
		}

		if ( method_exists( $order, 'send_customer_refund_notification' ) ) {
			$order->send_customer_refund_notification( $refund->get_id() );
			return;
		}

		if ( function_exists( 'WC' ) ) {
			$mailer = WC()->mailer();
			if ( $mailer && isset( $mailer->emails['WC_Email_Customer_Refunded_Order'] ) ) {
				$mailer->emails['WC_Email_Customer_Refunded_Order']->trigger( $order->get_id(), $refund->get_id() );
			}
		}
	}
}
