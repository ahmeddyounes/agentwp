<?php
/**
 * Order search intent handler tests.
 */

namespace AgentWP\Tests\Unit\Intent\Handlers;

use AgentWP\AI\Response;
use AgentWP\Intent\Handlers\OrderSearchHandler;
use AgentWP\Tests\TestCase;
use Mockery;

class OrderSearchHandlerTest extends TestCase {
	public function test_order_search_message_for_no_results(): void {
		$mock = Mockery::mock( 'overload:AgentWP\\Handlers\\OrderSearchHandler' );
		$mock->shouldReceive( 'handle' )
			->once()
			->with( array( 'query' => '' ) )
			->andReturn( Response::success( array( 'count' => 0 ) ) );

		$handler  = new OrderSearchHandler();
		$response = $handler->handle( array( 'input' => '' ) );

		$this->assertTrue( $response->is_success() );
		$this->assertSame( 'I could not find any orders that match.', $response->get_data()['message'] );
	}

	public function test_order_search_message_for_results(): void {
		$mock = Mockery::mock( 'overload:AgentWP\\Handlers\\OrderSearchHandler' );
		$mock->shouldReceive( 'handle' )
			->once()
			->with( array( 'query' => 'refunded' ) )
			->andReturn( Response::success( array( 'count' => 1 ) ) );

		$handler  = new OrderSearchHandler();
		$response = $handler->handle( array( 'input' => 'refunded' ) );

		$this->assertTrue( $response->is_success() );
		$this->assertSame( 'Found 1 order matching your request.', $response->get_data()['message'] );
	}

	public function test_order_search_errors_passthrough(): void {
		$mock = Mockery::mock( 'overload:AgentWP\\Handlers\\OrderSearchHandler' );
		$mock->shouldReceive( 'handle' )
			->once()
			->andReturn( Response::error( 'Missing search', 422 ) );

		$handler  = new OrderSearchHandler();
		$response = $handler->handle( array( 'input' => 'order' ) );

		$this->assertFalse( $response->is_success() );
		$this->assertSame( 422, $response->get_status() );
	}
}
