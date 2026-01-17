<?php
/**
 * AgentWP test helpers for wp-env integration tests.
 */

use AgentWP\AI\Response;
use AgentWP\Contracts\AIClientFactoryInterface;
use AgentWP\Contracts\OrderRefundServiceInterface;
use AgentWP\Contracts\OrderStatusServiceInterface;
use AgentWP\Contracts\ProductStockServiceInterface;
use AgentWP\Intent\Engine;
use AgentWP\Intent\Intent;
use AgentWP\Plugin;

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

function agentwp_test_success_response( array $data = array(), int $status = 200, array $meta = array() ) {
	$payload = array(
		'success' => true,
		'data'    => $data,
	);

	if ( ! empty( $meta ) ) {
		$payload['meta'] = $meta;
	}

	$response = rest_ensure_response( $payload );
	$response->set_status( $status );

	return $response;
}

function agentwp_test_error_response( string $message, int $status = 400, array $meta = array() ) {
	$payload = array(
		'success' => false,
		'data'    => array(),
		'error'   => array(
			'message' => $message,
		),
	);

	if ( ! empty( $meta ) ) {
		$payload['error']['meta'] = $meta;
	}

	$response = rest_ensure_response( $payload );
	$response->set_status( $status );

	return $response;
}

function agentwp_test_container() {
	if ( ! class_exists( Plugin::class ) || ! method_exists( Plugin::class, 'container' ) ) {
		return new WP_Error( 'agentwp_test_missing_plugin', 'AgentWP plugin not loaded.' );
	}

	$container = Plugin::container();
	if ( ! $container ) {
		return new WP_Error( 'agentwp_test_missing_container', 'AgentWP container not available.' );
	}

	return $container;
}

function agentwp_test_resolve( string $id ) {
	$container = agentwp_test_container();
	if ( is_wp_error( $container ) ) {
		return $container;
	}

	try {
		return $container->get( $id );
	} catch ( Throwable $e ) {
		return new WP_Error( 'agentwp_test_container_error', 'Failed to resolve AgentWP dependency.' );
	}
}

function agentwp_test_response_from_ai( $result ) {
	if ( ! $result instanceof Response ) {
		return agentwp_test_error_response( 'Invalid AgentWP test response.', 500 );
	}

	if ( $result->is_success() ) {
		return agentwp_test_success_response( $result->get_data(), $result->get_status(), $result->get_meta() );
	}

	return agentwp_test_error_response( $result->get_message(), $result->get_status(), $result->get_meta() );
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

function agentwp_test_reset( WP_REST_Request $request ) {
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

	return agentwp_test_success_response(
		array(
			'reset' => true,
		)
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

	$service = agentwp_test_resolve( OrderRefundServiceInterface::class );
	if ( is_wp_error( $service ) ) {
		return agentwp_test_error_response( $service->get_error_message(), 500 );
	}

	$draft_id = isset( $payload['draft_id'] ) ? sanitize_text_field( $payload['draft_id'] ) : '';
	if ( '' !== $draft_id ) {
		$result = $service->confirm_refund( $draft_id );
	} else {
		$order_id = isset( $payload['order_id'] ) ? absint( $payload['order_id'] ) : 0;
		$amount = isset( $payload['amount'] ) ? (float) $payload['amount'] : null;
		$reason = isset( $payload['reason'] ) ? sanitize_text_field( $payload['reason'] ) : '';
		$restock_items = isset( $payload['restock_items'] ) ? (bool) $payload['restock_items'] : true;

		$result = $service->prepare_refund( $order_id, $amount, $reason, $restock_items );
	}

	if ( isset( $result['success'] ) && true === $result['success'] ) {
		unset( $result['success'] );
		return agentwp_test_success_response( $result );
	}

	$message = isset( $result['message'] ) ? (string) $result['message'] : 'Refund failed.';
	return agentwp_test_error_response( $message, 400 );
}

function agentwp_test_status_update( WP_REST_Request $request ) {
	$payload = $request->get_json_params();
	$payload = is_array( $payload ) ? $payload : array();

	$service = agentwp_test_resolve( OrderStatusServiceInterface::class );
	if ( is_wp_error( $service ) ) {
		return agentwp_test_error_response( $service->get_error_message(), 500 );
	}

	$draft_id = isset( $payload['draft_id'] ) ? sanitize_text_field( $payload['draft_id'] ) : '';
	if ( '' !== $draft_id ) {
		$result = $service->confirm_update( $draft_id );
	} else {
		$order_id = isset( $payload['order_id'] ) ? absint( $payload['order_id'] ) : 0;
		$new_status = isset( $payload['new_status'] ) ? sanitize_text_field( $payload['new_status'] ) : '';
		$note = isset( $payload['note'] ) ? sanitize_text_field( $payload['note'] ) : '';
		$notify_customer = isset( $payload['notify_customer'] ) ? (bool) $payload['notify_customer'] : false;

		$result = $service->prepare_update( $order_id, $new_status, $note, $notify_customer );
	}

	if ( isset( $result['success'] ) && true === $result['success'] ) {
		unset( $result['success'] );
		return agentwp_test_success_response( $result );
	}

	$message = $result['error'] ?? $result['message'] ?? 'Status update failed.';
	$status  = isset( $result['code'] ) ? (int) $result['code'] : 400;

	return agentwp_test_error_response( (string) $message, $status, $result );
}

function agentwp_test_stock_update( WP_REST_Request $request ) {
	$payload = $request->get_json_params();
	$payload = is_array( $payload ) ? $payload : array();

	$service = agentwp_test_resolve( ProductStockServiceInterface::class );
	if ( is_wp_error( $service ) ) {
		return agentwp_test_error_response( $service->get_error_message(), 500 );
	}

	$draft_id = isset( $payload['draft_id'] ) ? sanitize_text_field( $payload['draft_id'] ) : '';
	if ( '' !== $draft_id ) {
		$result = $service->confirm_update( $draft_id );
	} else {
		$product_id = isset( $payload['product_id'] ) ? absint( $payload['product_id'] ) : 0;
		$quantity   = isset( $payload['quantity'] ) ? (int) $payload['quantity'] : 0;
		$operation  = isset( $payload['operation'] ) ? sanitize_text_field( $payload['operation'] ) : '';

		if ( '' === $operation ) {
			return agentwp_test_error_response( 'Missing operation.', 400 );
		}

		$result = $service->prepare_update( $product_id, $quantity, $operation );
	}

	if ( isset( $result['success'] ) && true === $result['success'] ) {
		unset( $result['success'] );
		return agentwp_test_success_response( $result );
	}

	$message = $result['error'] ?? $result['message'] ?? 'Stock update failed.';
	$status  = isset( $result['code'] ) ? (int) $result['code'] : 400;

	return agentwp_test_error_response( (string) $message, $status, $result );
}

function agentwp_test_email_draft( WP_REST_Request $request ) {
	$payload = $request->get_json_params();
	$payload = is_array( $payload ) ? $payload : array();

	$client_factory = agentwp_test_resolve( AIClientFactoryInterface::class );
	if ( is_wp_error( $client_factory ) ) {
		return agentwp_test_error_response( $client_factory->get_error_message(), 500 );
	}

	$order_id = isset( $payload['order_id'] ) ? absint( $payload['order_id'] ) : 0;
	$intent   = isset( $payload['intent'] ) ? sanitize_text_field( $payload['intent'] ) : '';
	$tone     = isset( $payload['tone'] ) ? sanitize_text_field( $payload['tone'] ) : '';

	$client = $client_factory->create( Intent::EMAIL_DRAFT );
	$result = $client->chat(
		array(
			array(
				'role'    => 'system',
				'content' => 'Return JSON with subject_line and email_body.',
			),
			array(
				'role'    => 'user',
				'content' => sprintf( 'Draft a %s email for order %d. Tone: %s.', $intent, $order_id, $tone ),
			),
		),
		array()
	);

	if ( ! $result->is_success() ) {
		return agentwp_test_response_from_ai( $result );
	}

	$data    = $result->get_data();
	$content = isset( $data['content'] ) ? (string) $data['content'] : '';
	$parsed  = json_decode( $content, true );

	if ( ! is_array( $parsed ) ) {
		return agentwp_test_error_response( 'Failed to parse email draft response.', 500, array( 'content' => $content ) );
	}

	return agentwp_test_success_response( $parsed );
}

function agentwp_test_intent_flow( WP_REST_Request $request ) {
	$payload = $request->get_json_params();
	$payload = is_array( $payload ) ? $payload : array();

	$prompt       = isset( $payload['prompt'] ) ? sanitize_text_field( $payload['prompt'] ) : '';
	$order_id     = isset( $payload['order_id'] ) ? absint( $payload['order_id'] ) : 0;
	$email_intent = isset( $payload['email_intent'] ) ? sanitize_text_field( $payload['email_intent'] ) : 'shipping_update';
	$tone         = isset( $payload['tone'] ) ? sanitize_text_field( $payload['tone'] ) : 'friendly';

	if ( '' === $prompt ) {
		return agentwp_test_error_response( 'Intent flow requires a prompt.', 400 );
	}

	if ( $order_id <= 0 ) {
		return agentwp_test_error_response( 'Intent flow requires an order ID.', 400 );
	}

	$engine = agentwp_test_resolve( Engine::class );
	if ( is_wp_error( $engine ) ) {
		return agentwp_test_error_response( $engine->get_error_message(), 500 );
	}

	$engine_result = $engine->handle(
		$prompt,
		array(
			'order_id'     => $order_id,
			'email_intent' => $email_intent,
			'tone'         => $tone,
		)
	);
	if ( ! $engine_result->is_success() ) {
		return agentwp_test_response_from_ai( $engine_result );
	}

	$engine_data = $engine_result->get_data();
	$resolved_intent = isset( $engine_data['intent'] ) ? (string) $engine_data['intent'] : '';

	if ( Intent::EMAIL_DRAFT !== $resolved_intent ) {
		return agentwp_test_error_response( 'Intent flow resolved to a non-email intent.', 400, array( 'intent' => $resolved_intent ) );
	}

	$message = isset( $engine_data['message'] ) ? (string) $engine_data['message'] : '';
	$draft   = json_decode( $message, true );

	if ( ! is_array( $draft ) ) {
		return agentwp_test_error_response( 'Failed to parse intent draft response.', 500, array( 'message' => $message ) );
	}

	return agentwp_test_success_response(
		array(
			'engine' => $engine_data,
			'draft'  => $draft,
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
