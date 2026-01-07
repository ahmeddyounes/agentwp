<?php
/**
 * AgentWP test helpers for wp-env integration tests.
 */

use AgentWP\AI\Response;
use AgentWP\Handlers\EmailDraftHandler;
use AgentWP\Handlers\OrderStatusHandler;
use AgentWP\Handlers\RefundHandler;
use AgentWP\Handlers\StockHandler;
use AgentWP\Intent\Engine;
use AgentWP\Intent\Intent;

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

if ( ! defined( 'AGENTWP_E2E' ) || ! AGENTWP_E2E ) {
	return;
}

const AGENTWP_TEST_META_KEY = '_agentwp_test_seed';

function agentwp_test_permissions() {
	if ( current_user_can( 'manage_woocommerce' ) ) {
		return true;
	}

	return new WP_Error( 'agentwp_test_forbidden', 'Insufficient permissions for AgentWP test route.' );
}

function agentwp_test_response_from_ai( $result ) {
	if ( ! $result instanceof Response ) {
		$response = rest_ensure_response(
			array(
				'success' => false,
				'data'    => array(),
				'error'   => array(
					'message' => 'Invalid AgentWP test response.',
				),
			)
		);
		$response->set_status( 500 );
		return $response;
	}

	if ( $result->is_success() ) {
		$response = rest_ensure_response(
			array(
				'success' => true,
				'data'    => $result->get_data(),
				'meta'    => $result->get_meta(),
			)
		);
		$response->set_status( $result->get_status() );
		return $response;
	}

	$response = rest_ensure_response(
		array(
			'success' => false,
			'data'    => array(),
			'error'   => array(
				'message' => $result->get_message(),
				'meta'    => $result->get_meta(),
			),
		)
	);
	$response->set_status( $result->get_status() );

	return $response;
}

function agentwp_test_seed_product( $name, $price, $stock, $sku ) {
	if ( ! class_exists( 'WC_Product_Simple' ) ) {
		return 0;
	}

	$product = new WC_Product_Simple();
	$product->set_name( $name );
	$product->set_regular_price( (string) $price );
	$product->set_status( 'publish' );
	$product->set_catalog_visibility( 'visible' );
	$product->set_manage_stock( true );
	$product->set_stock_quantity( $stock );
	$product->set_sku( $sku );
	$product->save();

	$product_id = $product->get_id();
	if ( $product_id ) {
		update_post_meta( $product_id, AGENTWP_TEST_META_KEY, 1 );
	}

	return $product_id;
}

function agentwp_test_seed_order( $product_id, $quantity, array $address, $status = 'processing' ) {
	if ( ! function_exists( 'wc_create_order' ) || ! function_exists( 'wc_get_product' ) ) {
		return 0;
	}

	$product = wc_get_product( $product_id );
	if ( ! $product ) {
		return 0;
	}

	$order = wc_create_order();
	$order->add_product( $product, $quantity );
	$order->set_address( $address, 'billing' );
	$order->set_address( $address, 'shipping' );
	$order->set_status( $status );
	$order->calculate_totals();
	$order->save();

	$order_id = $order->get_id();
	if ( $order_id ) {
		update_post_meta( $order_id, AGENTWP_TEST_META_KEY, 1 );
	}

	return $order_id;
}

function agentwp_test_seed_default() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return new WP_Error( 'agentwp_test_no_wc', 'WooCommerce is required to seed data.' );
	}

	$product_id = agentwp_test_seed_product( 'AgentWP Test Product', 25, 10, 'agentwp-test-001' );

	$address = array(
		'first_name' => 'Test',
		'last_name'  => 'Customer',
		'email'      => 'test.customer@example.com',
		'phone'      => '555-0100',
		'address_1'  => '100 Market St',
		'city'       => 'San Francisco',
		'state'      => 'CA',
		'postcode'   => '94105',
		'country'    => 'US',
	);

	$order_id = agentwp_test_seed_order( $product_id, 2, $address );

	return array(
		'product_id'    => $product_id,
		'order_id'      => $order_id,
		'stock_quantity' => 10,
	);
}

function agentwp_test_seed_huge_order() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return new WP_Error( 'agentwp_test_no_wc', 'WooCommerce is required to seed data.' );
	}

	$product_id = agentwp_test_seed_product( 'AgentWP Bulk Product', 8, 500, 'agentwp-test-bulk' );

	$address = array(
		'first_name' => 'Big',
		'last_name'  => 'Buyer',
		'email'      => 'big.buyer@example.com',
		'phone'      => '555-0199',
		'address_1'  => '500 Commerce Way',
		'city'       => 'Austin',
		'state'      => 'TX',
		'postcode'   => '73301',
		'country'    => 'US',
	);

	$order_id = agentwp_test_seed_order( $product_id, 200, $address );

	return array(
		'product_id'    => $product_id,
		'order_id'      => $order_id,
		'stock_quantity' => 500,
	);
}

function agentwp_test_reset() {
	$post_types = array( 'product', 'shop_order', 'shop_order_refund' );
	$posts      = get_posts(
		array(
			'post_type'      => $post_types,
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => AGENTWP_TEST_META_KEY,
		)
	);

	foreach ( $posts as $post_id ) {
		wp_delete_post( $post_id, true );
	}

	$refunds = get_posts(
		array(
			'post_type'      => 'shop_order_refund',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		)
	);

	foreach ( $refunds as $refund_id ) {
		wp_delete_post( $refund_id, true );
	}

	$options = array(
		'agentwp_settings',
		'agentwp_api_key',
		'agentwp_api_key_last4',
		'agentwp_budget_limit',
		'agentwp_draft_ttl_minutes',
		'agentwp_usage_stats',
	);

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	global $wpdb;
	if ( $wpdb ) {
		$like      = $wpdb->esc_like( '_transient_agentwp_' ) . '%';
		$like_time = $wpdb->esc_like( '_transient_timeout_agentwp_' ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like_time ) );

		$usage_table  = $wpdb->prefix . 'agentwp_usage';
		$search_table = $wpdb->prefix . 'agentwp_search_index';

		$tables = $wpdb->get_col( 'SHOW TABLES' );
		if ( is_array( $tables ) && in_array( $usage_table, $tables, true ) ) {
			$wpdb->query( "TRUNCATE TABLE {$usage_table}" );
		}
		if ( is_array( $tables ) && in_array( $search_table, $tables, true ) ) {
			$wpdb->query( "TRUNCATE TABLE {$search_table}" );
		}
	}

	return array(
		'reset' => true,
	);
}

function agentwp_test_seed( WP_REST_Request $request ) {
	$payload  = $request->get_json_params();
	$scenario = isset( $payload['scenario'] ) ? sanitize_text_field( $payload['scenario'] ) : 'default';

	if ( 'huge' === $scenario ) {
		$result = agentwp_test_seed_huge_order();
	} else {
		$result = agentwp_test_seed_default();
	}

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response(
		array(
			'success' => true,
			'data'    => array_merge(
				$result,
				array(
					'scenario' => $scenario,
				)
			),
		)
	);
}

function agentwp_test_refund( WP_REST_Request $request ) {
	$payload = $request->get_json_params();
	$payload = is_array( $payload ) ? $payload : array();

	if ( ! class_exists( RefundHandler::class ) ) {
		return new WP_Error( 'agentwp_test_missing_handler', 'Refund handler not available.' );
	}

	$handler = new RefundHandler();
	$result  = $handler->handle( $payload );

	return agentwp_test_response_from_ai( $result );
}

function agentwp_test_status_update( WP_REST_Request $request ) {
	$payload = $request->get_json_params();
	$payload = is_array( $payload ) ? $payload : array();

	if ( ! class_exists( OrderStatusHandler::class ) ) {
		return new WP_Error( 'agentwp_test_missing_handler', 'Order status handler not available.' );
	}

	$handler = new OrderStatusHandler();
	$result  = $handler->handle( $payload );

	return agentwp_test_response_from_ai( $result );
}

function agentwp_test_stock_update( WP_REST_Request $request ) {
	$payload = $request->get_json_params();
	$payload = is_array( $payload ) ? $payload : array();

	if ( ! class_exists( StockHandler::class ) ) {
		return new WP_Error( 'agentwp_test_missing_handler', 'Stock handler not available.' );
	}

	$handler = new StockHandler();
	$result  = $handler->handle( $payload );

	return agentwp_test_response_from_ai( $result );
}

function agentwp_test_email_draft( WP_REST_Request $request ) {
	$payload = $request->get_json_params();
	$payload = is_array( $payload ) ? $payload : array();

	if ( ! class_exists( EmailDraftHandler::class ) ) {
		return new WP_Error( 'agentwp_test_missing_handler', 'Email draft handler not available.' );
	}

	$handler = new EmailDraftHandler();
	$result  = $handler->handle( $payload );

	return agentwp_test_response_from_ai( $result );
}

function agentwp_test_intent_flow( WP_REST_Request $request ) {
	$payload = $request->get_json_params();
	$payload = is_array( $payload ) ? $payload : array();

	$prompt       = isset( $payload['prompt'] ) ? sanitize_text_field( $payload['prompt'] ) : '';
	$order_id     = isset( $payload['order_id'] ) ? absint( $payload['order_id'] ) : 0;
	$email_intent = isset( $payload['email_intent'] ) ? sanitize_text_field( $payload['email_intent'] ) : 'shipping_update';
	$tone         = isset( $payload['tone'] ) ? sanitize_text_field( $payload['tone'] ) : 'friendly';

	if ( '' === $prompt ) {
		return new WP_Error( 'agentwp_test_missing_prompt', 'Intent flow requires a prompt.' );
	}

	if ( $order_id <= 0 ) {
		return new WP_Error( 'agentwp_test_missing_order', 'Intent flow requires an order ID.' );
	}

	$engine = new Engine();
	$engine_result = $engine->handle( $prompt, array() );
	if ( ! $engine_result->is_success() ) {
		return agentwp_test_response_from_ai( $engine_result );
	}

	$engine_data   = $engine_result->get_data();
	$resolved_intent = isset( $engine_data['intent'] ) ? $engine_data['intent'] : '';

	if ( Intent::EMAIL_DRAFT !== $resolved_intent ) {
		return new WP_Error( 'agentwp_test_intent_mismatch', 'Intent flow resolved to a non-email intent.' );
	}

	if ( ! class_exists( EmailDraftHandler::class ) ) {
		return new WP_Error( 'agentwp_test_missing_handler', 'Email draft handler not available.' );
	}

	$handler = new EmailDraftHandler();
	$draft_result = $handler->draft_email(
		array(
			'order_id' => $order_id,
			'intent'   => $email_intent,
			'tone'     => $tone,
		)
	);

	if ( ! $draft_result->is_success() ) {
		return agentwp_test_response_from_ai( $draft_result );
	}

	return rest_ensure_response(
		array(
			'success' => true,
			'data'    => array(
				'engine' => $engine_data,
				'draft'  => $draft_result->get_data(),
			),
		)
	);
}

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'agentwp-test/v1',
			'/reset',
			array(
				'methods'             => 'POST',
				'callback'            => 'agentwp_test_reset',
				'permission_callback' => 'agentwp_test_permissions',
			)
		);

		register_rest_route(
			'agentwp-test/v1',
			'/seed',
			array(
				'methods'             => 'POST',
				'callback'            => 'agentwp_test_seed',
				'permission_callback' => 'agentwp_test_permissions',
			)
		);

		register_rest_route(
			'agentwp-test/v1',
			'/refund',
			array(
				'methods'             => 'POST',
				'callback'            => 'agentwp_test_refund',
				'permission_callback' => 'agentwp_test_permissions',
			)
		);

		register_rest_route(
			'agentwp-test/v1',
			'/status',
			array(
				'methods'             => 'POST',
				'callback'            => 'agentwp_test_status_update',
				'permission_callback' => 'agentwp_test_permissions',
			)
		);

		register_rest_route(
			'agentwp-test/v1',
			'/stock',
			array(
				'methods'             => 'POST',
				'callback'            => 'agentwp_test_stock_update',
				'permission_callback' => 'agentwp_test_permissions',
			)
		);

		register_rest_route(
			'agentwp-test/v1',
			'/email-draft',
			array(
				'methods'             => 'POST',
				'callback'            => 'agentwp_test_email_draft',
				'permission_callback' => 'agentwp_test_permissions',
			)
		);

		register_rest_route(
			'agentwp-test/v1',
			'/intent-flow',
			array(
				'methods'             => 'POST',
				'callback'            => 'agentwp_test_intent_flow',
				'permission_callback' => 'agentwp_test_permissions',
			)
		);
	}
);
