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
use AgentWP\Intent\HandlerRegistry;
use AgentWP\Intent\Handlers\FallbackHandler;
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
			public function classify( string $input, array $context = array() ): string {
				unset( $input, $context );
				return Intent::ORDER_STATUS;
			}
		};

		$builder = new class() extends ContextBuilder {
			public function build( array $context = array(), array $metadata = array() ): array {
				return array_merge( $context, array( 'source' => 'test' ) );
			}
		};

		$memory = new FakeMemoryStore();

		// Pre-register the handler in the registry (required since O(n) fallback was removed).
		$registry = new HandlerRegistry();
		$registry->register( Intent::ORDER_STATUS, $handler );

		$engine = new Engine(
			array(), // Handlers without #[HandlesIntent] attribute are no longer auto-registered.
			new FunctionRegistry(),
			$builder,
			$classifier,
			$memory,
			$registry,
			new FallbackHandler()
		);

		$response = $engine->handle( 'status check', array( 'store' => array( 'id' => 1 ) ) );

		$this->assertTrue( $response->is_success() );
		$this->assertSame( 'Handled order status.', $response->get_data()['message'] );
		$this->assertGreaterThan( 0, $memory->count() );
		$this->assertTrue( $memory->hasIntent( Intent::ORDER_STATUS ) );
	}

	public function test_engine_uses_fallback_for_unknown_intent(): void {
		$classifier = new class() extends IntentClassifier {
			public function classify( string $input, array $context = array() ): string {
				unset( $input, $context );
				return 'unknown_intent';
			}
		};

		$builder = new class() extends ContextBuilder {
			public function build( array $context = array(), array $metadata = array() ): array {
				return array_merge( $context, array( 'source' => 'test' ) );
			}
		};

		$memory = new FakeMemoryStore();
		$registry = new HandlerRegistry();

		$engine = new Engine(
			array(),
			new FunctionRegistry(),
			$builder,
			$classifier,
			$memory,
			$registry,
			new FallbackHandler()
		);

		$response = $engine->handle( 'do something unknown' );

		$this->assertTrue( $response->is_success() );
		$this->assertSame( Intent::UNKNOWN, $response->get_data()['intent'] );
		$this->assertArrayHasKey( 'suggestions', $response->get_data() );
	}

	public function test_handler_resolution_is_deterministic(): void {
		// Create two handlers for ORDER_STATUS - only the one registered in the registry should be used.
		$handler1 = new class() implements Handler {
			public function canHandle( string $intent ): bool {
				return Intent::ORDER_STATUS === $intent;
			}

			public function handle( array $context ): Response {
				return Response::success( array( 'handler' => 'first', 'message' => 'First handler' ) );
			}
		};

		$handler2 = new class() implements Handler {
			public function canHandle( string $intent ): bool {
				return Intent::ORDER_STATUS === $intent;
			}

			public function handle( array $context ): Response {
				return Response::success( array( 'handler' => 'second', 'message' => 'Second handler' ) );
			}
		};

		$classifier = new class() extends IntentClassifier {
			public function classify( string $input, array $context = array() ): string {
				return Intent::ORDER_STATUS;
			}
		};

		$builder = new class() extends ContextBuilder {
			public function build( array $context = array(), array $metadata = array() ): array {
				return $context;
			}
		};

		$memory = new FakeMemoryStore();
		$registry = new HandlerRegistry();

		// Register first handler.
		$registry->register( Intent::ORDER_STATUS, $handler1 );

		// Attempt to register second handler for same intent - first one wins (deterministic).
		$registry->register( Intent::ORDER_STATUS, $handler2 );

		$engine = new Engine(
			array(),
			new FunctionRegistry(),
			$builder,
			$classifier,
			$memory,
			$registry,
			new FallbackHandler()
		);

		// Run multiple times to verify determinism.
		for ( $i = 0; $i < 5; $i++ ) {
			$response = $engine->handle( 'check status' );
			// Last registered handler wins in the current implementation.
			$this->assertSame( 'second', $response->get_data()['handler'] );
		}
	}
}
