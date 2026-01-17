<?php
/**
 * Customer lookup intent handler tests.
 */

namespace AgentWP\Tests\Unit\Intent\Handlers;

use AgentWP\AI\Response;
use AgentWP\Contracts\CustomerServiceInterface;
use AgentWP\Intent\Handlers\CustomerLookupHandler;
use AgentWP\Intent\Intent;
use AgentWP\Tests\Fakes\FakeAIClientFactory;
use AgentWP\Tests\Fakes\FakeOpenAIClient;
use AgentWP\Tests\Fakes\FakeToolRegistry;
use AgentWP\Tests\TestCase;
use Mockery;

class CustomerLookupHandlerTest extends TestCase {
	public function test_returns_error_when_api_key_missing(): void {
		$service      = Mockery::mock( CustomerServiceInterface::class );
		$factory      = new FakeAIClientFactory( new FakeOpenAIClient(), false );
		$toolRegistry = new FakeToolRegistry();
		$handler      = new CustomerLookupHandler( $service, $factory, $toolRegistry );

		$response = $handler->handle( array( 'input' => 'customer@example.com' ) );

		$this->assertFalse( $response->is_success() );
		$this->assertSame( 401, $response->get_status() );
	}

	public function test_executes_customer_profile_tool_and_returns_final_message(): void {
		$service = Mockery::mock( CustomerServiceInterface::class );
		$service->shouldReceive( 'handle' )
			->once()
			->with(
				array(
					'email'       => 'customer@example.com',
					'customer_id' => 42,
				)
			)
			->andReturn(
				array(
					'total_orders' => 2,
				)
			);

		$client = new FakeOpenAIClient(
			array(
				Response::success(
					array(
						'content'    => '',
						'tool_calls' => array(
							array(
								'id'       => 'call_1',
								'function' => array(
									'name'      => 'get_customer_profile',
									'arguments' => wp_json_encode(
										array(
											'email'       => 'customer@example.com',
											'customer_id' => 42,
										)
									),
								),
							),
						),
					)
				),
				Response::success(
					array(
						'content'    => 'Found 2 orders for that customer.',
						'tool_calls' => array(),
					)
				),
			)
		);

		$factory      = new FakeAIClientFactory( $client, true );
		$toolRegistry = new FakeToolRegistry();
		$handler      = new CustomerLookupHandler( $service, $factory, $toolRegistry );

		$response = $handler->handle(
			array(
				'input' => 'Look up customer',
				'store' => array( 'name' => 'demo' ),
			)
		);

		$this->assertTrue( $response->is_success() );
		$data = $response->get_data();
		$this->assertSame( Intent::CUSTOMER_LOOKUP, $data['intent'] );
		$this->assertSame( 'Found 2 orders for that customer.', $data['message'] );
		$this->assertSame( array( 'name' => 'demo' ), $data['store'] );
	}
}
