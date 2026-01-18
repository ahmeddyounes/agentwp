<?php
namespace AgentWP\Tests\Unit;

use AgentWP\Intent\Handlers\OrderRefundHandler;
use AgentWP\Tests\TestCase;
use AgentWP\AI\Response;
use AgentWP\Contracts\OrderRefundServiceInterface;
use AgentWP\DTO\ServiceResult;
use AgentWP\Intent\Tools\ConfirmRefundTool;
use AgentWP\Intent\Tools\PrepareRefundTool;
use AgentWP\Tests\Fakes\FakeAIClientFactory;
use AgentWP\Tests\Fakes\FakeOpenAIClient;
use AgentWP\Tests\Fakes\FakeToolDispatcher;
use AgentWP\Tests\Fakes\FakeToolRegistry;
use Mockery;

class OrderRefundHandlerTest extends TestCase {

	public function test_handle_executes_refund_flow() {
		$service = Mockery::mock( OrderRefundServiceInterface::class );
		$service->shouldReceive( 'prepare_refund' )
			->once()
			->with( 123, null, '', true )
			->andReturn( ServiceResult::success( 'Refund prepared.', array( 'draft_id' => 'draft_123' ) ) );

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

		// Create tool dispatcher with pre-registered refund tools.
		$toolDispatcher = new FakeToolDispatcher();
		$toolDispatcher->registerTools(
			array(
				new PrepareRefundTool( $service ),
				new ConfirmRefundTool( $service ),
			)
		);

		$handler = new OrderRefundHandler(
			new FakeAIClientFactory( $client, true ),
			new FakeToolRegistry(),
			$toolDispatcher
		);

		$response = $handler->handle( array( 'input' => 'Refund order 123' ) );

		$this->assertTrue( $response->is_success() );
		$this->assertSame( 'Refund Prepared', $response->get_data()['message'] );
	}
}
