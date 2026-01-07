<?php
/**
 * Customer lookup intent handler tests.
 */

namespace AgentWP\Tests\Unit\Intent\Handlers;

use AgentWP\AI\Response;
use AgentWP\Intent\Handlers\CustomerLookupHandler;
use AgentWP\Intent\Intent;
use AgentWP\Tests\TestCase;
use Mockery;

class CustomerLookupHandlerTest extends TestCase {
	public function test_returns_prompt_when_input_missing(): void {
		$handler  = new CustomerLookupHandler();
		$response = $handler->handle( array() );

		$this->assertTrue( $response->is_success() );
		$this->assertSame( Intent::CUSTOMER_LOOKUP, $response->get_data()['intent'] );
		$this->assertSame(
			'I can look up customer profiles. Share an email or customer ID.',
			$response->get_data()['message']
		);
	}

	public function test_returns_prompt_for_invalid_input(): void {
		$handler  = new CustomerLookupHandler();
		$response = $handler->handle( array( 'input' => 'just words' ) );

		$this->assertTrue( $response->is_success() );
		$this->assertSame(
			'Share an email address or customer ID to look up their profile.',
			$response->get_data()['message']
		);
	}

	public function test_successful_customer_lookup_message(): void {
		$mock = Mockery::mock( 'overload:AgentWP\\Handlers\\CustomerHandler' );
		$mock->shouldReceive( 'handle' )
			->once()
			->with(
				array(
					'email'       => 'customer@example.com',
					'customer_id' => 0,
				)
			)
			->andReturn(
				Response::success(
					array(
						'total_orders' => 2,
					)
				)
			);

		$handler  = new CustomerLookupHandler();
		$response = $handler->handle( array( 'input' => 'customer@example.com' ) );

		$this->assertTrue( $response->is_success() );
		$this->assertSame( 'Found 2 orders for that customer.', $response->get_data()['message'] );
	}

	public function test_customer_lookup_message_when_no_orders(): void {
		$mock = Mockery::mock( 'overload:AgentWP\\Handlers\\CustomerHandler' );
		$mock->shouldReceive( 'handle' )
			->once()
			->with(
				array(
					'email'       => '',
					'customer_id' => 42,
				)
			)
			->andReturn(
				Response::success(
					array(
						'total_orders' => 0,
					)
				)
			);

		$handler  = new CustomerLookupHandler();
		$response = $handler->handle( array( 'input' => '42' ) );

		$this->assertTrue( $response->is_success() );
		$this->assertSame( 'I could not find any orders for that customer.', $response->get_data()['message'] );
	}

	public function test_customer_lookup_errors_passthrough(): void {
		$mock = Mockery::mock( 'overload:AgentWP\\Handlers\\CustomerHandler' );
		$mock->shouldReceive( 'handle' )
			->once()
			->andReturn( Response::error( 'WooCommerce missing', 400 ) );

		$handler  = new CustomerLookupHandler();
		$response = $handler->handle( array( 'input' => '123' ) );

		$this->assertFalse( $response->is_success() );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'WooCommerce missing', $response->get_message() );
	}
}
