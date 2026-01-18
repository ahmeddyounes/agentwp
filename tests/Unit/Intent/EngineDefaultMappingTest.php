<?php
/**
 * Unit tests for Engine default function mapping.
 */

namespace AgentWP\Tests\Unit\Intent;

use AgentWP\AI\Functions\AbstractFunction;
use AgentWP\AI\Response;
use AgentWP\Contracts\IntentClassifierInterface;
use AgentWP\Intent\ContextBuilder;
use AgentWP\Intent\Engine;
use AgentWP\Intent\FunctionRegistry;
use AgentWP\Intent\Handler;
use AgentWP\Intent\HandlerRegistry;
use AgentWP\Intent\Handlers\FallbackHandler;
use AgentWP\Intent\Intent;
use AgentWP\Intent\ToolSuggestionProvider;
use AgentWP\Tests\Fakes\FakeMemoryStore;
use AgentWP\Tests\Fakes\FakeToolDispatcher;
use AgentWP\Tests\Fakes\FakeToolRegistry;
use AgentWP\Tests\TestCase;

class EngineDefaultMappingTest extends TestCase {
	public function test_engine_filters_default_mapping_to_registered_tools(): void {
		$tool_registry   = new FakeToolRegistry();
		$tool_dispatcher = new FakeToolDispatcher();

		$schema = new class( 'search_orders' ) extends AbstractFunction {
			private string $name;

			public function __construct( string $name ) {
				$this->name = $name;
			}

			public function get_name() {
				return $this->name;
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

		$secondary_schema = new class( 'select_orders' ) extends AbstractFunction {
			private string $name;

			public function __construct( string $name ) {
				$this->name = $name;
			}

			public function get_name() {
				return $this->name;
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

		$tool_registry->register( $schema );
		$tool_registry->register( $secondary_schema );

		$tool_dispatcher->register( 'search_orders', fn() => array( 'ok' => true ) );
		$tool_dispatcher->register( 'bulk_update', fn() => array( 'ok' => true ) );

		$handler = new class() implements Handler, ToolSuggestionProvider {
			public function canHandle( string $intent ): bool {
				return Intent::ORDER_SEARCH === $intent;
			}

			public function handle( array $context ): Response {
				return Response::success(
					array(
						'intent'  => $context['intent'] ?? Intent::UNKNOWN,
						'message' => 'Handled',
					)
				);
			}

			public function getSuggestedTools(): array {
				return array( 'search_orders', 'select_orders', 'bulk_update' );
			}
		};

		$handler_registry = new HandlerRegistry();
		$handler_registry->register( Intent::ORDER_SEARCH, $handler );

		$classifier = new class() implements IntentClassifierInterface {
			public function classify( string $input, array $context = array() ): string {
				unset( $input, $context );
				return Intent::ORDER_SEARCH;
			}
		};

		$builder = new class() extends ContextBuilder {
			public function build( array $context = array(), array $metadata = array() ): array {
				unset( $metadata );
				return $context;
			}
		};

		$engine = new Engine(
			array(),
			new FunctionRegistry(),
			$builder,
			$classifier,
			new FakeMemoryStore(),
			$handler_registry,
			new FallbackHandler(),
			null,
			$tool_registry,
			$tool_dispatcher
		);

		$this->assertSame(
			array( 'search_orders' ),
			$engine->get_function_registry()->get_functions_for_intent( Intent::ORDER_SEARCH )
		);
	}
}
