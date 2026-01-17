<?php
namespace AgentWP\Tests\Unit;

use AgentWP\Intent\Handlers\OrderRefundHandler;
use AgentWP\Tests\TestCase;
use AgentWP\AI\Response;
use AgentWP\Contracts\OrderRefundServiceInterface;
use AgentWP\Tests\Fakes\FakeAIClientFactory;
use AgentWP\Tests\Fakes\FakeOpenAIClient;
use Mockery;

class OrderRefundHandlerTest extends TestCase {

	public function test_handle_executes_refund_flow() {
		$service = Mockery::mock( OrderRefundServiceInterface::class );
		$service->shouldReceive( 'prepare_refund' )
			->once()
			->with( 123, null, '', true )
			->andReturn( array( 'draft_id' => 'draft_123' ) );

		$client = new FakeOpenAIClient(
			array(
				Response::success(
					array(
						'content'    => '',
						'tool_calls' => array(
							array(
								'id'       => 'call_1',
								'function' => array(
									'name'      => 'prepare_refund',
									'arguments' => wp_json_encode( array( 'order_id' => 123 ) ),
								),
							),
						),
					)
				),
				Response::success(
					array(
						'content'    => 'Refund Prepared',
						'tool_calls' => array(),
					)
				),
			)
		);

		$handler = new OrderRefundHandler(
			$service,
			new FakeAIClientFactory( $client, true )
		);

		$response = $handler->handle( array( 'input' => 'Refund order 123' ) );

		$this->assertTrue( $response->is_success() );
		$this->assertSame( 'Refund Prepared', $response->get_data()['message'] );
	}
}
