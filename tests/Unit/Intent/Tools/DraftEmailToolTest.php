<?php
/**
 * Tests for DraftEmailTool.
 *
 * @package AgentWP\Tests\Unit\Intent\Tools
 */

namespace AgentWP\Tests\Unit\Intent\Tools;

use AgentWP\Contracts\EmailDraftServiceInterface;
use AgentWP\DTO\ServiceResult;
use AgentWP\Intent\Tools\DraftEmailTool;
use AgentWP\Tests\TestCase;

class DraftEmailToolTest extends TestCase {

	public function test_get_name_returns_draft_email(): void {
		$service = $this->createMock( EmailDraftServiceInterface::class );
		$tool    = new DraftEmailTool( $service );

		$this->assertSame( 'draft_email', $tool->getName() );
	}

	public function test_execute_returns_order_context_with_email_params(): void {
		$service = $this->createMock( EmailDraftServiceInterface::class );
		$service->expects( $this->once() )
			->method( 'get_order_context' )
			->with( 123 )
			->willReturn(
				ServiceResult::success(
					'Order context loaded',
					array(
						'type'    => 'email',
						'context' => array(
							'summary'  => 'Email draft for Order #123',
							'order_id' => 123,
							'customer' => 'John Doe',
							'total'    => 99.99,
							'status'   => 'completed',
						),
					)
				)
			);

		$tool   = new DraftEmailTool( $service );
		$result = $tool->execute(
			array(
				'order_id'            => 123,
				'intent'              => 'shipping_update',
				'tone'                => 'friendly',
				'custom_instructions' => 'Be extra helpful',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'email', $result['type'] );
		$this->assertSame( 'shipping_update', $result['email_intent'] );
		$this->assertSame( 'friendly', $result['email_tone'] );
		$this->assertSame( 'Be extra helpful', $result['custom_instructions'] );
		$this->assertArrayHasKey( 'context', $result );
	}

	public function test_execute_defaults_tone_to_professional(): void {
		$service = $this->createMock( EmailDraftServiceInterface::class );
		$service->expects( $this->once() )
			->method( 'get_order_context' )
			->willReturn(
				ServiceResult::success(
					'Context loaded',
					array(
						'type'    => 'email',
						'context' => array(),
					)
				)
			);

		$tool   = new DraftEmailTool( $service );
		$result = $tool->execute(
			array(
				'order_id' => 1,
				'intent'   => 'general_inquiry',
			)
		);

		$this->assertSame( 'professional', $result['email_tone'] );
	}

	public function test_execute_returns_failure_when_order_not_found(): void {
		$service = $this->createMock( EmailDraftServiceInterface::class );
		$service->expects( $this->once() )
			->method( 'get_order_context' )
			->with( 999 )
			->willReturn( ServiceResult::notFound( 'Order', 999 ) );

		$tool   = new DraftEmailTool( $service );
		$result = $tool->execute(
			array(
				'order_id' => 999,
				'intent'   => 'shipping_update',
				'tone'     => 'professional',
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertSame( 404, $result['code'] ); // HTTP status for not_found.
	}

	public function test_execute_handles_missing_order_id(): void {
		$service = $this->createMock( EmailDraftServiceInterface::class );
		$service->expects( $this->once() )
			->method( 'get_order_context' )
			->with( 0 )
			->willReturn( ServiceResult::invalidInput( 'Invalid order ID.' ) );

		$tool   = new DraftEmailTool( $service );
		$result = $tool->execute( array( 'intent' => 'general_inquiry' ) );

		$this->assertFalse( $result['success'] );
	}
}
