<?php
/**
 * Tests for SearchProductTool.
 *
 * @package AgentWP\Tests\Unit\Intent\Tools
 */

namespace AgentWP\Tests\Unit\Intent\Tools;

use AgentWP\Contracts\ProductStockServiceInterface;
use AgentWP\Intent\Tools\SearchProductTool;
use AgentWP\Tests\TestCase;

class SearchProductToolTest extends TestCase {

	public function test_get_name_returns_search_product(): void {
		$service = $this->createMock( ProductStockServiceInterface::class );
		$tool    = new SearchProductTool( $service );

		$this->assertSame( 'search_product', $tool->getName() );
	}

	public function test_execute_returns_products_from_service(): void {
		$products = array(
			array(
				'id'    => 1,
				'name'  => 'Widget',
				'sku'   => 'WDG-001',
				'stock' => 10,
			),
			array(
				'id'    => 2,
				'name'  => 'Blue Widget',
				'sku'   => 'WDG-002',
				'stock' => 5,
			),
		);

		$service = $this->createMock( ProductStockServiceInterface::class );
		$service->expects( $this->once() )
			->method( 'search_products' )
			->with( 'widget' )
			->willReturn( $products );

		$tool   = new SearchProductTool( $service );
		$result = $tool->execute( array( 'query' => 'widget' ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( $products, $result['products'] );
		$this->assertSame( 2, $result['count'] );
		$this->assertSame( 'widget', $result['query'] );
	}

	public function test_execute_falls_back_to_sku_when_query_empty(): void {
		$service = $this->createMock( ProductStockServiceInterface::class );
		$service->expects( $this->once() )
			->method( 'search_products' )
			->with( 'SKU-123' )
			->willReturn( array() );

		$tool   = new SearchProductTool( $service );
		$result = $tool->execute( array( 'sku' => 'SKU-123' ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'SKU-123', $result['query'] );
	}

	public function test_execute_returns_error_when_no_query_or_sku(): void {
		$service = $this->createMock( ProductStockServiceInterface::class );
		$service->expects( $this->never() )->method( 'search_products' );

		$tool   = new SearchProductTool( $service );
		$result = $tool->execute( array() );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'query or SKU is required', $result['error'] );
		$this->assertEmpty( $result['products'] );
	}

	public function test_execute_returns_empty_array_when_no_results(): void {
		$service = $this->createMock( ProductStockServiceInterface::class );
		$service->expects( $this->once() )
			->method( 'search_products' )
			->with( 'nonexistent' )
			->willReturn( array() );

		$tool   = new SearchProductTool( $service );
		$result = $tool->execute( array( 'query' => 'nonexistent' ) );

		$this->assertTrue( $result['success'] );
		$this->assertEmpty( $result['products'] );
		$this->assertSame( 0, $result['count'] );
	}
}
