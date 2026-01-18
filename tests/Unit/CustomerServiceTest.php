<?php
namespace AgentWP\Tests\Unit;

use AgentWP\Services\CustomerService;
use AgentWP\Contracts\WooCommerceConfigGatewayInterface;
use AgentWP\Contracts\WooCommerceUserGatewayInterface;
use AgentWP\Contracts\WooCommerceOrderGatewayInterface;
use AgentWP\Contracts\OrderRepositoryInterface;
use AgentWP\Contracts\WooCommerceProductCategoryGatewayInterface;
use AgentWP\Contracts\WooCommercePriceFormatterInterface;
use AgentWP\Tests\TestCase;
use Mockery;

class CustomerServiceTest extends TestCase {

	public function test_handle_returns_customer_profile() {
		$configGateway = Mockery::mock( WooCommerceConfigGatewayInterface::class );
		$configGateway->shouldReceive( 'is_woocommerce_available' )->andReturn( true );
		$configGateway->shouldReceive( 'get_paid_statuses' )->andReturn( array( 'completed' ) );
		$configGateway->shouldReceive( 'apply_filters' )->andReturnUsing( function( $hook, $value ) {
			return $value;
		} );

		$userGateway = Mockery::mock( WooCommerceUserGatewayInterface::class );
		$userGateway->shouldReceive( 'get_current_timestamp' )->andReturn( time() );

		$orderMock = Mockery::mock( 'WC_Order' );
		$orderMock->shouldReceive( 'get_total' )->andReturn( 50.00 );
		$orderMock->shouldReceive( 'get_date_created' )->andReturn( new \DateTime( '2023-01-01' ) );
		$orderMock->shouldReceive( 'get_items' )->andReturn( array() );
		$orderMock->shouldReceive( 'get_id' )->andReturn( 100 );
		$orderMock->shouldReceive( 'get_status' )->andReturn( 'completed' );
		$orderMock->shouldReceive( 'get_currency' )->andReturn( 'USD' );
		$orderMock->shouldReceive( 'get_billing_first_name' )->andReturn( 'John' );
		$orderMock->shouldReceive( 'get_billing_last_name' )->andReturn( 'Doe' );
		$orderMock->shouldReceive( 'get_billing_email' )->andReturn( 'john@example.com' );

		$orderGateway = Mockery::mock( WooCommerceOrderGatewayInterface::class );
		$orderGateway->shouldReceive( 'get_order' )->with( 100 )->andReturn( $orderMock );

		$orderRepository = Mockery::mock( OrderRepositoryInterface::class );
		$orderRepository->shouldReceive( 'queryIds' )->andReturn( array( 100 ) );

		$categoryGateway = Mockery::mock( WooCommerceProductCategoryGatewayInterface::class );

		$priceFormatter = Mockery::mock( WooCommercePriceFormatterInterface::class );
		$priceFormatter->shouldReceive( 'normalize_decimal' )->andReturnUsing( function( $value ) {
			return (float) $value;
		} );
		$priceFormatter->shouldReceive( 'format_price' )->andReturnUsing( function( $amount ) {
			return '$' . number_format( $amount, 2 );
		} );

		$service = new CustomerService(
			$configGateway,
			$userGateway,
			$orderGateway,
			$orderRepository,
			$categoryGateway,
			$priceFormatter
		);

		$result = $service->handle( array( 'email' => 'john@example.com' ) );

		$this->assertTrue( $result->isSuccess() );
		$this->assertSame( 200, $result->httpStatus );
		$this->assertEquals( 1, $result->get( 'total_orders' ) );
		$this->assertEquals( 50.00, $result->get( 'total_spent' ) );
		$customer = $result->get( 'customer' );
		$this->assertEquals( 'john@example.com', $customer['email'] );
	}

	public function test_handle_returns_error_when_woocommerce_unavailable() {
		$configGateway = Mockery::mock( WooCommerceConfigGatewayInterface::class );
		$configGateway->shouldReceive( 'is_woocommerce_available' )->andReturn( false );

		$userGateway     = Mockery::mock( WooCommerceUserGatewayInterface::class );
		$orderGateway    = Mockery::mock( WooCommerceOrderGatewayInterface::class );
		$orderRepository = Mockery::mock( OrderRepositoryInterface::class );
		$categoryGateway = Mockery::mock( WooCommerceProductCategoryGatewayInterface::class );
		$priceFormatter  = Mockery::mock( WooCommercePriceFormatterInterface::class );

		$service = new CustomerService(
			$configGateway,
			$userGateway,
			$orderGateway,
			$orderRepository,
			$categoryGateway,
			$priceFormatter
		);

		$result = $service->handle( array( 'email' => 'john@example.com' ) );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( 400, $result->httpStatus );
		$this->assertSame( 'invalid_input', $result->code );
		$this->assertSame( 'WooCommerce is required.', $result->message );
	}

	public function test_handle_returns_error_when_no_identifier_provided() {
		$configGateway = Mockery::mock( WooCommerceConfigGatewayInterface::class );
		$configGateway->shouldReceive( 'is_woocommerce_available' )->andReturn( true );

		$userGateway     = Mockery::mock( WooCommerceUserGatewayInterface::class );
		$orderGateway    = Mockery::mock( WooCommerceOrderGatewayInterface::class );
		$orderRepository = Mockery::mock( OrderRepositoryInterface::class );
		$categoryGateway = Mockery::mock( WooCommerceProductCategoryGatewayInterface::class );
		$priceFormatter  = Mockery::mock( WooCommercePriceFormatterInterface::class );

		$service = new CustomerService(
			$configGateway,
			$userGateway,
			$orderGateway,
			$orderRepository,
			$categoryGateway,
			$priceFormatter
		);

		$result = $service->handle( array() );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( 400, $result->httpStatus );
		$this->assertSame( 'invalid_input', $result->code );
		$this->assertSame( 'Provide a customer ID or email.', $result->message );
	}
}