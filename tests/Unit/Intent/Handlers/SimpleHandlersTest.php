<?php
/**
 * Basic intent handler coverage.
 */

namespace AgentWP\Tests\Unit\Intent\Handlers;

use AgentWP\AI\Response;
use AgentWP\Contracts\AnalyticsServiceInterface;
use AgentWP\Contracts\EmailDraftServiceInterface;
use AgentWP\Contracts\OrderRefundServiceInterface;
use AgentWP\Contracts\OrderStatusServiceInterface;
use AgentWP\Intent\Handlers\AnalyticsQueryHandler;
use AgentWP\Intent\Handlers\EmailDraftHandler;
use AgentWP\Intent\Handlers\FallbackHandler;
use AgentWP\Intent\Handlers\OrderRefundHandler;
use AgentWP\Intent\Handlers\OrderStatusHandler;
use AgentWP\Intent\Intent;
use AgentWP\Tests\Fakes\FakeAIClientFactory;
use AgentWP\Tests\Fakes\FakeOpenAIClient;
use AgentWP\Tests\Fakes\FakeToolRegistry;
use AgentWP\Tests\TestCase;
use Mockery;

class SimpleHandlersTest extends TestCase {
	public function test_handlers_return_expected_messages(): void {
		$toolRegistry = new FakeToolRegistry();
		$handlers     = array(
			array(
				new AnalyticsQueryHandler(
					Mockery::mock( AnalyticsServiceInterface::class ),
					new FakeAIClientFactory(
						new FakeOpenAIClient( array( Response::success( array( 'content' => 'ok', 'tool_calls' => array() ) ) ) ),
						true
					),
					$toolRegistry
				),
				Intent::ANALYTICS_QUERY,
			),
			array(
				new EmailDraftHandler(
					Mockery::mock( EmailDraftServiceInterface::class ),
					new FakeAIClientFactory(
						new FakeOpenAIClient( array( Response::success( array( 'content' => 'ok', 'tool_calls' => array() ) ) ) ),
						true
					),
					$toolRegistry
				),
				Intent::EMAIL_DRAFT,
			),
			array(
				new OrderRefundHandler(
					Mockery::mock( OrderRefundServiceInterface::class ),
					new FakeAIClientFactory(
						new FakeOpenAIClient( array( Response::success( array( 'content' => 'ok', 'tool_calls' => array() ) ) ) ),
						true
					),
					$toolRegistry
				),
				Intent::ORDER_REFUND,
			),
			array(
				new OrderStatusHandler(
					Mockery::mock( OrderStatusServiceInterface::class ),
					new FakeAIClientFactory(
						new FakeOpenAIClient( array( Response::success( array( 'content' => 'ok', 'tool_calls' => array() ) ) ) ),
						true
					),
					$toolRegistry
				),
				Intent::ORDER_STATUS,
			),
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
