<?php
namespace AgentWP\Tests\Unit;

use AgentWP\Intent\Handlers\OrderRefundHandler;
use AgentWP\Tests\TestCase;
use AgentWP\AI\OpenAIClient;
use AgentWP\AI\Response;
use AgentWP\Services\OrderRefundService;
use AgentWP\Plugin\SettingsManager;
use Mockery;

class OrderRefundHandlerTest extends TestCase {

	public function test_handle_executes_refund_flow() {
		// Mock SettingsManager
		$settings = Mockery::mock( SettingsManager::class );
		$settings->shouldReceive( 'getApiKey' )->andReturn( 'sk-test' );

		// Mock Service
		$service = Mockery::mock( OrderRefundService::class );
		$service->shouldReceive( 'prepare_refund' )
			->with( 123, null, '', true )
			->andReturn( array( 'success' => true, 'message' => 'Refund Prepared' ) );

		// Partial Mock Handler
		$handler = Mockery::mock( OrderRefundHandler::class )->makePartial();
		$handler->shouldAllowMockingProtectedMethods();
		$handler->shouldReceive( 'get_settings' )->andReturn( $settings );
		$handler->shouldReceive( 'get_service' )->andReturn( $service );

		// Mock OpenAI Client
		$client = Mockery::mock( OpenAIClient::class );
		$handler->shouldReceive( 'create_client' )->with( 'sk-test' )->andReturn( $client );

		// 1. Tool Call Response
		$toolCallResponse = Response::success( array(
			'content' => '',
			'tool_calls' => array(
				array(
					'id' => 'call_1',
					'function' => array(
						'name' => 'prepare_refund',
						'arguments' => json_encode( array( 'order_id' => 123 ) )
					)
				)
			)
		) );

		// 2. Final Response
		$finalResponse = Response::success( array(
			'content' => 'Refund Prepared',
			'tool_calls' => array()
		) );

		// Expect chat calls
		// First call gets user input
		// Second call gets tool result
		$client->shouldReceive( 'chat' )
			->times( 2 )
			->andReturn( $toolCallResponse, $finalResponse );

		$response = $handler->handle( array( 'input' => 'Refund order 123' ) );

		$this->assertEquals( 'Refund Prepared', $response->get_data()['message'] );
	}
}
