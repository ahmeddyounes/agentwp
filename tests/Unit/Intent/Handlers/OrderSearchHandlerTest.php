<?php
/**
 * Order search intent handler tests.
 */

namespace AgentWP\Tests\Unit\Intent\Handlers;

use AgentWP\AI\Response;
use AgentWP\Contracts\OrderSearchServiceInterface;
use AgentWP\DTO\ServiceResult;
use AgentWP\Intent\Handlers\OrderSearchHandler;
use AgentWP\Intent\Intent;
use AgentWP\Intent\Tools\SearchOrdersTool;
use AgentWP\Tests\Fakes\FakeAIClientFactory;
use AgentWP\Tests\Fakes\FakeOpenAIClient;
use AgentWP\Tests\Fakes\FakeToolDispatcher;
use AgentWP\Tests\Fakes\FakeToolRegistry;
use AgentWP\Tests\TestCase;
use Mockery;

class OrderSearchHandlerTest extends TestCase {
	public function test_returns_error_when_api_key_missing(): void {
		$factory        = new FakeAIClientFactory( new FakeOpenAIClient(), false );
		$toolRegistry   = new FakeToolRegistry();
		$toolDispatcher = new FakeToolDispatcher();
		$handler        = new OrderSearchHandler( $factory, $toolRegistry, $toolDispatcher );

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
				ServiceResult::success(
					'1 order(s) found.',
					array(
						'orders' => array(),
						'count'  => 1,
						'cached' => false,
						'query'  => array(),
					)
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

		$factory        = new FakeAIClientFactory( $client, true );
		$toolRegistry   = new FakeToolRegistry();
		$toolDispatcher = new FakeToolDispatcher();
		// Register the SearchOrdersTool with the mock service.
		$toolDispatcher->registerTool( new SearchOrdersTool( $service ) );

		$handler = new OrderSearchHandler( $factory, $toolRegistry, $toolDispatcher );

		$response = $handler->handle( array( 'input' => 'Find refunded orders', 'store' => array( 'id' => 1 ) ) );

		$this->assertTrue( $response->is_success() );
		$data = $response->get_data();
		$this->assertSame( Intent::ORDER_SEARCH, $data['intent'] );
		$this->assertSame( 'Found 1 order matching your request.', $data['message'] );
		$this->assertSame( array( 'id' => 1 ), $data['store'] );
	}
}
