<?php
/**
 * Product stock intent handler tests.
 */

namespace AgentWP\Tests\Unit\Intent\Handlers;

use AgentWP\AI\Response;
use AgentWP\Intent\Handlers\ProductStockHandler;
use AgentWP\Tests\TestCase;
use Mockery;

class ProductStockHandlerTest extends TestCase {
	public function test_returns_prompt_when_query_missing(): void {
		$handler  = new ProductStockHandler();
		$response = $handler->handle( array( 'input' => '' ) );

		$this->assertTrue( $response->is_success() );
		$this->assertSame( 'I can check product stock. Share a product name or SKU.', $response->get_data()['message'] );
	}

	public function test_product_stock_message_for_no_results(): void {
		$mock = Mockery::mock( 'overload:AgentWP\\Handlers\\StockHandler' );
		$mock->shouldReceive( 'handle' )
			->once()
			->with( array( 'query' => 'SKU-1' ) )
			->andReturn( Response::success( array( 'count' => 0 ) ) );

		$handler  = new ProductStockHandler();
		$response = $handler->handle( array( 'input' => 'SKU-1' ) );

		$this->assertTrue( $response->is_success() );
		$this->assertSame( 'I could not find any products that match.', $response->get_data()['message'] );
	}

	public function test_product_stock_message_for_results(): void {
		$mock = Mockery::mock( 'overload:AgentWP\\Handlers\\StockHandler' );
		$mock->shouldReceive( 'handle' )
			->once()
			->with( array( 'query' => 'Hoodie' ) )
			->andReturn( Response::success( array( 'count' => 3 ) ) );

		$handler  = new ProductStockHandler();
		$response = $handler->handle( array( 'input' => 'Hoodie' ) );

		$this->assertTrue( $response->is_success() );
		$this->assertSame( 'Found 3 products matching your request.', $response->get_data()['message'] );
	}

	public function test_product_stock_errors_passthrough(): void {
		$mock = Mockery::mock( 'overload:AgentWP\\Handlers\\StockHandler' );
		$mock->shouldReceive( 'handle' )
			->once()
			->andReturn( Response::error( 'WooCommerce missing', 400 ) );

		$handler  = new ProductStockHandler();
		$response = $handler->handle( array( 'input' => 'Hoodie' ) );

		$this->assertFalse( $response->is_success() );
		$this->assertSame( 400, $response->get_status() );
	}
}
