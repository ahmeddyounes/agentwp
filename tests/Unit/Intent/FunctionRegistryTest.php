<?php
/**
 * Unit tests for the legacy FunctionRegistry suggestions.
 */

namespace AgentWP\Tests\Unit\Intent;

use AgentWP\AI\Functions\AbstractFunction;
use AgentWP\AI\Response;
use AgentWP\Intent\FunctionRegistry;
use AgentWP\Intent\Handler;
use AgentWP\Intent\HandlerRegistry;
use AgentWP\Intent\Intent;
use AgentWP\Intent\ToolSuggestionProvider;
use AgentWP\Tests\Fakes\FakeToolRegistry;
use AgentWP\Tests\TestCase;

class FunctionRegistryTest extends TestCase {
	public function test_derives_suggestions_from_handler_tools_when_no_mapping(): void {
		$registry = new FunctionRegistry();
		$handlers = new HandlerRegistry();

		$handler = new class() implements Handler, ToolSuggestionProvider {
			public function canHandle( string $intent ): bool {
				return Intent::ORDER_SEARCH === $intent;
			}

			public function handle( array $context ): Response {
				unset( $context );
				return Response::success( array() );
			}

			public function getSuggestedTools(): array {
				return array( 'search_orders', 'prepare_refund' );
			}
		};

		$handlers->register( Intent::ORDER_SEARCH, $handler );
		$registry->set_handler_registry( $handlers );

		$this->assertSame(
			array( 'prepare_refund', 'search_orders' ),
			$registry->get_functions_for_intent( Intent::ORDER_SEARCH )
		);
	}

	public function test_filters_suggestions_to_registered_tools_when_tool_registry_set(): void {
		$registry = new FunctionRegistry();
		$handlers = new HandlerRegistry();
		$tools = new FakeToolRegistry();

		$schema = new class() extends AbstractFunction {
			public function get_name() {
				return 'search_orders';
			}

			public function get_description() {
				return 'Test schema.';
			}

			public function get_parameters() {
				return array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
				);
			}
		};

		$tools->register( $schema );

		$handler = new class() implements Handler, ToolSuggestionProvider {
			public function canHandle( string $intent ): bool {
				return Intent::ORDER_SEARCH === $intent;
			}

			public function handle( array $context ): Response {
				unset( $context );
				return Response::success( array() );
			}

			public function getSuggestedTools(): array {
				return array( 'search_orders', 'unknown_tool' );
			}
		};

		$handlers->register( Intent::ORDER_SEARCH, $handler );
		$registry->set_handler_registry( $handlers );
		$registry->set_tool_registry( $tools );

		$this->assertSame(
			array( 'search_orders' ),
			$registry->get_functions_for_intent( Intent::ORDER_SEARCH )
		);
	}
}
