<?php
/**
 * Integration tests for intent engine.
 */

namespace AgentWP\Tests\Integration\Intent;

use AgentWP\AI\Response;
use AgentWP\Intent\ContextBuilder;
use AgentWP\Intent\Engine;
use AgentWP\Intent\FunctionRegistry;
use AgentWP\Intent\Handler;
use AgentWP\Intent\Intent;
use AgentWP\Intent\IntentClassifier;
use AgentWP\Tests\Fakes\FakeMemoryStore;
use AgentWP\Tests\TestCase;

class EngineTest extends TestCase {
	public function test_engine_routes_to_matching_handler_and_records_memory(): void {
		$handler = new class() implements Handler {
			public function canHandle( string $intent ): bool {
				return Intent::ORDER_STATUS === $intent;
			}

			public function handle( array $context ): Response {
				return Response::success(
					array(
						'intent'  => $context['intent'],
						'message' => 'Handled order status.',
					)
				);
			}
		};

		$classifier = new class() extends IntentClassifier {
			public function classify( $input, array $context = array() ) {
				return Intent::ORDER_STATUS;
			}
		};

		$builder = new class() extends ContextBuilder {
			public function build( array $context = array(), array $metadata = array() ) {
				return array_merge( $context, array( 'source' => 'test' ) );
			}
		};

		$memory = new FakeMemoryStore();

		$engine = new Engine(
			array( $handler ),
			new FunctionRegistry(),
			$builder,
			$classifier,
			$memory
		);

		$response = $engine->handle( 'status check', array( 'store' => array( 'id' => 1 ) ) );

		$this->assertTrue( $response->is_success() );
		$this->assertSame( 'Handled order status.', $response->get_data()['message'] );
		$this->assertGreaterThan( 0, $memory->count() );
		$this->assertTrue( $memory->hasIntent( Intent::ORDER_STATUS ) );
	}
}
