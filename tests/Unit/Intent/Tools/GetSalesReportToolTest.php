<?php
/**
 * Tests for GetSalesReportTool.
 *
 * @package AgentWP\Tests\Unit\Intent\Tools
 */

namespace AgentWP\Tests\Unit\Intent\Tools;

use AgentWP\Contracts\AnalyticsServiceInterface;
use AgentWP\DTO\ServiceResult;
use AgentWP\Intent\Tools\GetSalesReportTool;
use AgentWP\Tests\TestCase;

class GetSalesReportToolTest extends TestCase {

	public function test_get_name_returns_get_sales_report(): void {
		$service = $this->createMock( AnalyticsServiceInterface::class );
		$tool    = new GetSalesReportTool( $service );

		$this->assertSame( 'get_sales_report', $tool->getName() );
	}

	public function test_execute_calls_service_with_period(): void {
		$service = $this->createMock( AnalyticsServiceInterface::class );
		$service->expects( $this->once() )
			->method( 'get_report_by_period' )
			->with( 'this_week', null, null )
			->willReturn(
				ServiceResult::success(
					'Report retrieved',
					array(
						'period'      => 'this_week',
						'start'       => '2024-01-15',
						'end'         => '2024-01-21',
						'total_sales' => 1500.00,
						'orders'      => 25,
						'refunds'     => 50.00,
					)
				)
			);

		$tool   = new GetSalesReportTool( $service );
		$result = $tool->execute( array( 'period' => 'this_week' ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'this_week', $result['period'] );
		$this->assertSame( 1500.00, $result['total_sales'] );
		$this->assertSame( 25, $result['orders'] );
	}

	public function test_execute_defaults_period_to_today(): void {
		$service = $this->createMock( AnalyticsServiceInterface::class );
		$service->expects( $this->once() )
			->method( 'get_report_by_period' )
			->with( 'today', null, null )
			->willReturn( ServiceResult::success( 'Report retrieved', array( 'period' => 'today' ) ) );

		$tool = new GetSalesReportTool( $service );
		$tool->execute( array() );
	}

	public function test_execute_passes_custom_date_range(): void {
		$service = $this->createMock( AnalyticsServiceInterface::class );
		$service->expects( $this->once() )
			->method( 'get_report_by_period' )
			->with( 'custom', '2024-01-01', '2024-01-31' )
			->willReturn(
				ServiceResult::success(
					'Custom report',
					array(
						'period' => 'custom',
						'start'  => '2024-01-01',
						'end'    => '2024-01-31',
					)
				)
			);

		$tool   = new GetSalesReportTool( $service );
		$result = $tool->execute(
			array(
				'period'     => 'custom',
				'start_date' => '2024-01-01',
				'end_date'   => '2024-01-31',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'custom', $result['period'] );
	}

	public function test_execute_includes_compare_previous_flag(): void {
		$service = $this->createMock( AnalyticsServiceInterface::class );
		$service->expects( $this->once() )
			->method( 'get_report_by_period' )
			->willReturn(
				ServiceResult::success(
					'Report',
					array( 'period' => 'this_month' )
				)
			);

		$tool   = new GetSalesReportTool( $service );
		$result = $tool->execute(
			array(
				'period'           => 'this_month',
				'compare_previous' => true,
			)
		);

		$this->assertTrue( $result['compare_previous'] );
	}

	public function test_execute_defaults_compare_previous_to_false(): void {
		$service = $this->createMock( AnalyticsServiceInterface::class );
		$service->expects( $this->once() )
			->method( 'get_report_by_period' )
			->willReturn( ServiceResult::success( 'Report', array() ) );

		$tool   = new GetSalesReportTool( $service );
		$result = $tool->execute( array( 'period' => 'yesterday' ) );

		$this->assertFalse( $result['compare_previous'] );
	}

	public function test_execute_returns_failure_from_service(): void {
		$service = $this->createMock( AnalyticsServiceInterface::class );
		$service->expects( $this->once() )
			->method( 'get_report_by_period' )
			->willReturn( ServiceResult::operationFailed( 'WooCommerce unavailable' ) );

		$tool   = new GetSalesReportTool( $service );
		$result = $tool->execute( array( 'period' => 'today' ) );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 500, $result['code'] ); // HTTP status for operation_failed.
	}
}
