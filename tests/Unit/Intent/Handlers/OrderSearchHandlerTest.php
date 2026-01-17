<?php
/**
 * Order search intent handler tests.
 */

namespace AgentWP\Tests\Unit\Intent\Handlers;

use AgentWP\AI\Response;
use AgentWP\Contracts\OrderSearchServiceInterface;
use AgentWP\Intent\Handlers\OrderSearchHandler;
use AgentWP\Intent\Intent;
use AgentWP\Tests\Fakes\FakeAIClientFactory;
use AgentWP\Tests\Fakes\FakeOpenAIClient;
use AgentWP\Tests\TestCase;
use Mockery;

class OrderSearchHandlerTest extends TestCase {
	public function test_returns_error_when_api_key_missing(): void {
		$service = Mockery::mock( OrderSearchServiceInterface::class );
		$factory = new FakeAIClientFactory( new FakeOpenAIClient(), false );
		$handler = new OrderSearchHandler( $service, $factory );

		$response = $handler->handle( array( 'input' => 'find refunded orders' ) );

		$this->assertFalse( $response->is_success() );
		$this->assertSame( 401, $response->get_status() );
	}

	public function test_executes_search_orders_tool_with_mapped_arguments(): void {
		$service = Mockery::mock( OrderSearchServiceInterface::class );
		$service->shouldReceive( 'handle' )
			->once()
			->with(
				array(
					'query'    => 'refunded',
					'status'   => '',
					'limit'    => 10,
					'email'    => '',
					'order_id' => 0,
				)
			)
			->andReturn(
				array(
					'count'  => 1,
					'orders' => array(),
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
									'name'      => 'search_orders',
									'arguments' => wp_json_encode( array( 'query' => 'refunded' ) ),
								),
							),
						),
					)
				),
				Response::success(
					array(
						'content'    => 'Found 1 order matching your request.',
						'tool_calls' => array(),
					)
				),
			)
		);

		$factory = new FakeAIClientFactory( $client, true );
		$handler = new OrderSearchHandler( $service, $factory );

		$response = $handler->handle( array( 'input' => 'Find refunded orders', 'store' => array( 'id' => 1 ) ) );

		$this->assertTrue( $response->is_success() );
		$data = $response->get_data();
		$this->assertSame( Intent::ORDER_SEARCH, $data['intent'] );
		$this->assertSame( 'Found 1 order matching your request.', $data['message'] );
		$this->assertSame( array( 'id' => 1 ), $data['store'] );
	}
}
