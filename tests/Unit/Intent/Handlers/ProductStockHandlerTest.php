<?php
/**
 * Product stock intent handler tests.
 */

namespace AgentWP\Tests\Unit\Intent\Handlers;

use AgentWP\AI\Response;
use AgentWP\Contracts\ProductStockServiceInterface;
use AgentWP\Intent\Handlers\ProductStockHandler;
use AgentWP\Intent\Intent;
use AgentWP\Tests\Fakes\FakeAIClientFactory;
use AgentWP\Tests\Fakes\FakeOpenAIClient;
use AgentWP\Tests\Fakes\FakeToolDispatcher;
use AgentWP\Tests\Fakes\FakeToolRegistry;
use AgentWP\Tests\TestCase;
use Mockery;

class ProductStockHandlerTest extends TestCase {
	public function test_returns_error_when_api_key_missing(): void {
		$factory        = new FakeAIClientFactory( new FakeOpenAIClient(), false );
		$toolRegistry   = new FakeToolRegistry();
		$toolDispatcher = new FakeToolDispatcher();
		$handler        = new ProductStockHandler( $factory, $toolRegistry, $toolDispatcher );

		$response = $handler->handle( array( 'input' => 'Check stock for hoodie' ) );

		$this->assertFalse( $response->is_success() );
		$this->assertSame( 401, $response->get_status() );
	}

	public function test_executes_search_product_tool(): void {
		$service = Mockery::mock( ProductStockServiceInterface::class );
		$service->shouldReceive( 'search_products' )
			->once()
			->with( 'Hoodie' )
			->andReturn(
				array(
					array(
						'id'    => 1,
						'name'  => 'Hoodie',
						'sku'   => 'HOODIE-1',
						'stock' => 3,
					),
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
									'name'      => 'search_product',
									'arguments' => wp_json_encode( array( 'query' => 'Hoodie' ) ),
								),
							),
						),
					)
				),
				Response::success(
					array(
						'content'    => 'Found 1 product matching your request.',
						'tool_calls' => array(),
					)
				),
			)
		);

		$factory        = new FakeAIClientFactory( $client, true );
		$toolRegistry   = new FakeToolRegistry();
		$toolDispatcher = new FakeToolDispatcher();
		$toolDispatcher->registerTool( new \AgentWP\Intent\Tools\SearchProductTool( $service ) );
		$handler        = new ProductStockHandler( $factory, $toolRegistry, $toolDispatcher );

		$response = $handler->handle( array( 'input' => 'Search Hoodie' ) );

		$this->assertTrue( $response->is_success() );
		$this->assertSame( Intent::PRODUCT_STOCK, $response->get_data()['intent'] );
		$this->assertSame( 'Found 1 product matching your request.', $response->get_data()['message'] );
	}
}
