<?php
/**
 * Tests for GetCustomerProfileTool.
 *
 * @package AgentWP\Tests\Unit\Intent\Tools
 */

namespace AgentWP\Tests\Unit\Intent\Tools;

use AgentWP\Contracts\CustomerServiceInterface;
use AgentWP\DTO\ServiceResult;
use AgentWP\Intent\Tools\GetCustomerProfileTool;
use AgentWP\Tests\TestCase;

class GetCustomerProfileToolTest extends TestCase {

	public function test_get_name_returns_get_customer_profile(): void {
		$service = $this->createMock( CustomerServiceInterface::class );
		$tool    = new GetCustomerProfileTool( $service );

		$this->assertSame( 'get_customer_profile', $tool->getName() );
	}

	public function test_execute_calls_service_with_email(): void {
		$service = $this->createMock( CustomerServiceInterface::class );
		$service->expects( $this->once() )
			->method( 'handle' )
			->with( array( 'email' => 'john@example.com' ) )
			->willReturn(
				ServiceResult::success(
					'Customer profile retrieved',
					array(
						'customer' => array(
							'email' => 'john@example.com',
							'name'  => 'John Doe',
						),
						'total_orders' => 15,
						'total_spent'  => 1500.00,
					)
				)
			);

		$tool   = new GetCustomerProfileTool( $service );
		$result = $tool->execute( array( 'email' => 'john@example.com' ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 15, $result['total_orders'] );
		$this->assertSame( 1500.00, $result['total_spent'] );
	}

	public function test_execute_calls_service_with_customer_id(): void {
		$service = $this->createMock( CustomerServiceInterface::class );
		$service->expects( $this->once() )
			->method( 'handle' )
			->with( array( 'customer_id' => 42 ) )
			->willReturn(
				ServiceResult::success(
					'Profile retrieved',
					array(
						'customer' => array(
							'customer_id' => 42,
							'name'        => 'Jane Smith',
						),
					)
				)
			);

		$tool   = new GetCustomerProfileTool( $service );
		$result = $tool->execute( array( 'customer_id' => 42 ) );

		$this->assertTrue( $result['success'] );
	}

	public function test_execute_calls_service_with_both_email_and_customer_id(): void {
		$service = $this->createMock( CustomerServiceInterface::class );
		$service->expects( $this->once() )
			->method( 'handle' )
			->with(
				array(
					'email'       => 'jane@example.com',
					'customer_id' => 42,
				)
			)
			->willReturn( ServiceResult::success( 'Profile', array() ) );

		$tool = new GetCustomerProfileTool( $service );
		$tool->execute(
			array(
				'email'       => 'jane@example.com',
				'customer_id' => 42,
			)
		);
	}

	public function test_execute_returns_error_when_no_identifier_provided(): void {
		$service = $this->createMock( CustomerServiceInterface::class );
		$service->expects( $this->never() )->method( 'handle' );

		$tool   = new GetCustomerProfileTool( $service );
		$result = $tool->execute( array() );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'email or customer_id is required', $result['error'] );
	}

	public function test_execute_returns_error_when_customer_id_is_zero(): void {
		$service = $this->createMock( CustomerServiceInterface::class );
		$service->expects( $this->never() )->method( 'handle' );

		$tool   = new GetCustomerProfileTool( $service );
		$result = $tool->execute( array( 'customer_id' => 0 ) );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'email or customer_id is required', $result['error'] );
	}

	public function test_execute_returns_error_when_email_is_empty(): void {
		$service = $this->createMock( CustomerServiceInterface::class );
		$service->expects( $this->never() )->method( 'handle' );

		$tool   = new GetCustomerProfileTool( $service );
		$result = $tool->execute( array( 'email' => '' ) );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'email or customer_id is required', $result['error'] );
	}

	public function test_execute_returns_failure_from_service(): void {
		$service = $this->createMock( CustomerServiceInterface::class );
		$service->expects( $this->once() )
			->method( 'handle' )
			->willReturn( ServiceResult::invalidInput( 'WooCommerce is required.' ) );

		$tool   = new GetCustomerProfileTool( $service );
		$result = $tool->execute( array( 'email' => 'test@example.com' ) );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 400, $result['code'] ); // HTTP status for invalid_input.
	}
}
