<?php
/**
 * Tests for ConfirmStockUpdateTool.
 *
 * @package AgentWP\Tests\Unit\Intent\Tools
 */

namespace AgentWP\Tests\Unit\Intent\Tools;

use AgentWP\Contracts\ProductStockServiceInterface;
use AgentWP\DTO\ServiceResult;
use AgentWP\Intent\Tools\ConfirmStockUpdateTool;
use AgentWP\Tests\TestCase;

class ConfirmStockUpdateToolTest extends TestCase {

	public function test_get_name_returns_confirm_stock_update(): void {
		$service = $this->createMock( ProductStockServiceInterface::class );
		$tool    = new ConfirmStockUpdateTool( $service );

		$this->assertSame( 'confirm_stock_update', $tool->getName() );
	}

	public function test_execute_calls_service_with_draft_id(): void {
		$service = $this->createMock( ProductStockServiceInterface::class );
		$service->expects( $this->once() )
			->method( 'confirm_update' )
			->with( 'draft_abc123' )
			->willReturn(
				ServiceResult::success(
					'Stock updated',
					array(
						'product_id' => 123,
						'new_stock'  => 50,
					)
				)
			);

		$tool   = new ConfirmStockUpdateTool( $service );
		$result = $tool->execute( array( 'draft_id' => 'draft_abc123' ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 123, $result['product_id'] );
		$this->assertSame( 50, $result['new_stock'] );
	}

	public function test_execute_returns_error_when_draft_id_missing(): void {
		$service = $this->createMock( ProductStockServiceInterface::class );
		$service->expects( $this->never() )->method( 'confirm_update' );

		$tool   = new ConfirmStockUpdateTool( $service );
		$result = $tool->execute( array() );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Draft ID is required', $result['error'] );
	}

	public function test_execute_returns_error_when_draft_id_empty(): void {
		$service = $this->createMock( ProductStockServiceInterface::class );
		$service->expects( $this->never() )->method( 'confirm_update' );

		$tool   = new ConfirmStockUpdateTool( $service );
		$result = $tool->execute( array( 'draft_id' => '' ) );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Draft ID is required', $result['error'] );
	}

	public function test_execute_returns_failure_from_service(): void {
		$service = $this->createMock( ProductStockServiceInterface::class );
		$service->expects( $this->once() )
			->method( 'confirm_update' )
			->with( 'expired_draft' )
			->willReturn( ServiceResult::draftExpired( 'Draft expired.' ) );

		$tool   = new ConfirmStockUpdateTool( $service );
		$result = $tool->execute( array( 'draft_id' => 'expired_draft' ) );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 410, $result['code'] ); // HTTP status for draft_expired.
	}
}
