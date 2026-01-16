<?php
namespace AgentWP\Tests\Unit;

use AgentWP\Services\CustomerService;
use AgentWP\Tests\TestCase;
use WP_Mock;
use Mockery;

class WC_Order {
	public function get_total() {}
	public function get_date_created() {}
	public function get_items( $type = '' ) {}
	public function get_id() {}
	public function get_status() {}
	public function get_currency() {}
	public function get_billing_first_name() {}
	public function get_billing_last_name() {}
	public function get_billing_email() {}
}

class CustomerServiceTest extends TestCase {

	public function test_handle_returns_customer_profile() {
		$service = new CustomerService();

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

		WP_Mock::userFunction( 'wc_get_orders' )
			->andReturnUsing( function( $args ) use ( $orderMock ) {
				if ( isset( $args['return'] ) && 'ids' === $args['return'] ) {
					return array( 100 );
				}
				return array( $orderMock );
			} );

		WP_Mock::userFunction( 'wc_get_order' )
			->andReturn( $orderMock );

		WP_Mock::userFunction( 'wc_get_is_paid_statuses' )->andReturn( array( 'completed' ) );
		WP_Mock::userFunction( 'wc_format_decimal' )->andReturnUsing( function( $v ) { return (float) $v; } );
		WP_Mock::userFunction( 'wc_price' )->andReturn( '$50.00' );
		WP_Mock::userFunction( 'wc_get_price_decimals' )->andReturn( 2 );
		WP_Mock::userFunction( 'current_time' )->andReturn( time() );

		$result = $service->handle( array( 'email' => 'john@example.com' ) );

		$this->assertArrayHasKey( 'total_orders', $result );
		$this->assertEquals( 1, $result['total_orders'] );
		$this->assertEquals( 50.00, $result['total_spent'] );
		$this->assertEquals( 'john@example.com', $result['customer']['email'] );
	}
}