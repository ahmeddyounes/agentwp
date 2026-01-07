<?php
/**
 * Basic intent handler coverage.
 */

namespace AgentWP\Tests\Unit\Intent\Handlers;

use AgentWP\Intent\Handlers\AnalyticsQueryHandler;
use AgentWP\Intent\Handlers\EmailDraftHandler;
use AgentWP\Intent\Handlers\FallbackHandler;
use AgentWP\Intent\Handlers\OrderRefundHandler;
use AgentWP\Intent\Handlers\OrderStatusHandler;
use AgentWP\Intent\Intent;
use AgentWP\Tests\TestCase;

class SimpleHandlersTest extends TestCase {
	public function test_handlers_return_expected_messages(): void {
		$handlers = array(
			array( new AnalyticsQueryHandler(), Intent::ANALYTICS_QUERY ),
			array( new EmailDraftHandler(), Intent::EMAIL_DRAFT ),
			array( new OrderRefundHandler(), Intent::ORDER_REFUND ),
			array( new OrderStatusHandler(), Intent::ORDER_STATUS ),
		);

		foreach ( $handlers as $config ) {
			list( $handler, $intent ) = $config;
			$this->assertTrue( $handler->canHandle( $intent ) );
			$response = $handler->handle(
				array(
					'store'                => array( 'name' => 'demo' ),
					'user'                 => array( 'id' => 5 ),
					'recent_orders'        => array( array( 'id' => 1 ) ),
					'function_suggestions' => array( 'search_orders' ),
				)
			);

			$this->assertTrue( $response->is_success() );
			$data = $response->get_data();
			$this->assertSame( $intent, $data['intent'] );
			$this->assertArrayHasKey( 'message', $data );
			$this->assertArrayHasKey( 'store', $data );
			$this->assertArrayHasKey( 'user', $data );
			$this->assertArrayHasKey( 'recent_orders', $data );
			$this->assertArrayHasKey( 'function_suggestions', $data );
		}
	}

	public function test_fallback_handler_includes_suggestions(): void {
		$handler  = new FallbackHandler();
		$response = $handler->handle( array() );

		$this->assertTrue( $response->is_success() );
		$data = $response->get_data();
		$this->assertSame( Intent::UNKNOWN, $data['intent'] );
		$this->assertSame( Intent::suggestions(), $data['suggestions'] );
	}
}
