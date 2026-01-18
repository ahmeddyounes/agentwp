<?php
/**
 * Tests for PrepareStockUpdateTool.
 *
 * @package AgentWP\Tests\Unit\Intent\Tools
 */

namespace AgentWP\Tests\Unit\Intent\Tools;

use AgentWP\Contracts\ProductStockServiceInterface;
use AgentWP\DTO\ServiceResult;
use AgentWP\Intent\Tools\PrepareStockUpdateTool;
use AgentWP\Tests\TestCase;

class PrepareStockUpdateToolTest extends TestCase {

	public function test_get_name_returns_prepare_stock_update(): void {
		$service = $this->createMock( ProductStockServiceInterface::class );
		$tool    = new PrepareStockUpdateTool( $service );

		$this->assertSame( 'prepare_stock_update', $tool->getName() );
	}

	public function test_execute_calls_service_with_correct_arguments(): void {
		$service = $this->createMock( ProductStockServiceInterface::class );
		$service->expects( $this->once() )
			->method( 'prepare_update' )
			->with( 123, 50, 'set' )
			->willReturn(
				ServiceResult::success(
					'Prepared',
					array(
						'draft_id' => 'draft_abc',
						'type'     => 'stock',
						'preview'  => array(
							'product_id'     => 123,
							'original_stock' => 10,
							'new_stock'      => 50,
						),
					)
				)
			);

		$tool   = new PrepareStockUpdateTool( $service );
		$result = $tool->execute(
			array(
				'product_id' => 123,
				'quantity'   => 50,
				'operation'  => 'set',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'draft_abc', $result['draft_id'] );
	}

	public function test_execute_passes_increase_operation(): void {
		$service = $this->createMock( ProductStockServiceInterface::class );
		$service->expects( $this->once() )
			->method( 'prepare_update' )
			->with( 1, 10, 'increase' )
			->willReturn( ServiceResult::success( 'Prepared', array( 'draft_id' => 'draft_1' ) ) );

		$tool = new PrepareStockUpdateTool( $service );
		$tool->execute(
			array(
				'product_id' => 1,
				'quantity'   => 10,
				'operation'  => 'increase',
			)
		);
	}

	public function test_execute_passes_decrease_operation(): void {
		$service = $this->createMock( ProductStockServiceInterface::class );
		$service->expects( $this->once() )
			->method( 'prepare_update' )
			->with( 1, 5, 'decrease' )
			->willReturn( ServiceResult::success( 'Prepared', array( 'draft_id' => 'draft_1' ) ) );

		$tool = new PrepareStockUpdateTool( $service );
		$tool->execute(
			array(
				'product_id' => 1,
				'quantity'   => 5,
				'operation'  => 'decrease',
			)
		);
	}

	public function test_execute_defaults_to_set_operation(): void {
		$service = $this->createMock( ProductStockServiceInterface::class );
		$service->expects( $this->once() )
			->method( 'prepare_update' )
			->with( 1, 10, 'set' )
			->willReturn( ServiceResult::success( 'Prepared', array( 'draft_id' => 'draft_1' ) ) );

		$tool = new PrepareStockUpdateTool( $service );
		$tool->execute(
			array(
				'product_id' => 1,
				'quantity'   => 10,
			)
		);
	}

	public function test_execute_returns_failure_from_service(): void {
		$service = $this->createMock( ProductStockServiceInterface::class );
		$service->expects( $this->once() )
			->method( 'prepare_update' )
			->willReturn( ServiceResult::notFound( 'Product', 999 ) );

		$tool   = new PrepareStockUpdateTool( $service );
		$result = $tool->execute(
			array(
				'product_id' => 999,
				'quantity'   => 10,
				'operation'  => 'set',
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertSame( 404, $result['code'] );
	}
}
