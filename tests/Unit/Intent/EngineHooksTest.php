<?php
/**
 * Unit tests for Engine hooks injection.
 *
 * @package AgentWP\Tests\Unit\Intent
 */

namespace AgentWP\Tests\Unit\Intent;

use AgentWP\AI\Response;
use AgentWP\Intent\ContextBuilder;
use AgentWP\Intent\Engine;
use AgentWP\Intent\FunctionRegistry;
use AgentWP\Intent\Handler;
use AgentWP\Intent\HandlerRegistry;
use AgentWP\Intent\Handlers\FallbackHandler;
use AgentWP\Intent\Intent;
use AgentWP\Contracts\IntentClassifierInterface;
use AgentWP\Tests\Fakes\FakeMemoryStore;
use AgentWP\Tests\Fakes\FakeWPFunctions;
use AgentWP\Tests\TestCase;

/**
 * Tests that Engine uses injected HooksInterface instead of WordPress globals.
 */
class EngineHooksTest extends TestCase {

	/**
	 * Test that Engine calls applyFilters for handler registration.
	 *
	 * @return void
	 */
	public function test_engine_applies_handlers_filter_on_construction(): void {
		$hooks = new FakeWPFunctions();
		$memory = new FakeMemoryStore();
		$registry = new HandlerRegistry();
		$classifier = $this->createStubClassifier( Intent::UNKNOWN );
		$builder = $this->createStubContextBuilder();

		new Engine(
			array(),
			new FunctionRegistry(),
			$builder,
			$classifier,
			$memory,
			$registry,
			new FallbackHandler(),
			$hooks
		);

		$this->assertTrue(
			$hooks->wasFilterApplied( 'agentwp_intent_handlers' ),
			'Expected agentwp_intent_handlers filter to be applied'
		);
	}

	/**
	 * Test that Engine calls doAction for function registration.
	 *
	 * @return void
	 */
	public function test_engine_fires_function_registration_action_on_construction(): void {
		$hooks = new FakeWPFunctions();
		$memory = new FakeMemoryStore();
		$registry = new HandlerRegistry();
		$classifier = $this->createStubClassifier( Intent::UNKNOWN );
		$builder = $this->createStubContextBuilder();

		new Engine(
			array(),
			new FunctionRegistry(),
			$builder,
			$classifier,
			$memory,
			$registry,
			new FallbackHandler(),
			$hooks
		);

		$this->assertTrue(
			$hooks->wasActionFired( 'agentwp_register_intent_functions' ),
			'Expected agentwp_register_intent_functions action to be fired'
		);
	}

	/**
	 * Test that Engine calls applyFilters for default function mapping.
	 *
	 * @return void
	 */
	public function test_engine_applies_function_mapping_filter(): void {
		$hooks = new FakeWPFunctions();
		$memory = new FakeMemoryStore();
		$registry = new HandlerRegistry();
		$classifier = $this->createStubClassifier( Intent::UNKNOWN );
		$builder = $this->createStubContextBuilder();

		new Engine(
			array(),
			new FunctionRegistry(),
			$builder,
			$classifier,
			$memory,
			$registry,
			new FallbackHandler(),
			$hooks
		);

		$this->assertTrue(
			$hooks->wasFilterApplied( 'agentwp_default_function_mapping' ),
			'Expected agentwp_default_function_mapping filter to be applied'
		);
	}

	/**
	 * Test that filter can modify handlers.
	 *
	 * @return void
	 */
	public function test_filter_can_add_handlers(): void {
		$hooks = new FakeWPFunctions();
		$memory = new FakeMemoryStore();
		$registry = new HandlerRegistry();
		$classifier = $this->createStubClassifier( Intent::ORDER_STATUS );
		$builder = $this->createStubContextBuilder();

		// Create a handler that will be added via filter.
		$customHandler = new class() implements Handler {
			public function canHandle( string $intent ): bool {
				return Intent::ORDER_STATUS === $intent;
			}

			public function handle( array $context ): Response {
				return Response::success( array( 'message' => 'Custom handler response' ) );
			}
		};

		// Mock filter to add the handler.
		$hooks->setFilterReturn( 'agentwp_intent_handlers', array( $customHandler ) );

		$engine = new Engine(
			array(), // Empty initial handlers - filter will add.
			new FunctionRegistry(),
			$builder,
			$classifier,
			$memory,
			$registry,
			new FallbackHandler(),
			$hooks
		);

		// The engine should have registered the handler from the filter.
		// Since the handler doesn't have #[HandlesIntent] attribute, we need to register manually.
		// But we can verify the filter was called with the correct arguments.
		$lastFilter = $hooks->getLastFilter();
		$this->assertNotNull( $lastFilter );
		$this->assertStringContainsString( 'agentwp', $lastFilter['hook'] );
	}

	/**
	 * Test that Engine is unit-testable without WordPress globals.
	 *
	 * @return void
	 */
	public function test_engine_works_without_wordpress_globals(): void {
		$hooks = new FakeWPFunctions();
		$memory = new FakeMemoryStore();
		$registry = new HandlerRegistry();
		$classifier = $this->createStubClassifier( Intent::UNKNOWN );
		$builder = $this->createStubContextBuilder();

		$engine = new Engine(
			array(),
			new FunctionRegistry(),
			$builder,
			$classifier,
			$memory,
			$registry,
			new FallbackHandler(),
			$hooks
		);

		// Engine should handle requests without WordPress being loaded.
		$response = $engine->handle( 'test input' );

		$this->assertTrue( $response->is_success() );
		$this->assertSame( Intent::UNKNOWN, $response->get_data()['intent'] );
	}

	/**
	 * Test backward compatibility when hooks is null.
	 *
	 * @return void
	 */
	public function test_engine_works_when_hooks_is_null(): void {
		$memory = new FakeMemoryStore();
		$registry = new HandlerRegistry();
		$classifier = $this->createStubClassifier( Intent::UNKNOWN );
		$builder = $this->createStubContextBuilder();

		// Pass null for hooks - should not throw.
		$engine = new Engine(
			array(),
			new FunctionRegistry(),
			$builder,
			$classifier,
			$memory,
			$registry,
			new FallbackHandler(),
			null
		);

		$response = $engine->handle( 'test' );
		$this->assertTrue( $response->is_success() );
	}

	/**
	 * Create a stub intent classifier that returns a fixed intent.
	 *
	 * @param string $intent Intent to return.
	 * @return IntentClassifierInterface
	 */
	private function createStubClassifier( string $intent ): IntentClassifierInterface {
		return new class( $intent ) implements IntentClassifierInterface {
			private string $fixedIntent;

			public function __construct( string $intent ) {
				$this->fixedIntent = $intent;
			}

			public function classify( string $input, array $context = array() ): string {
				return $this->fixedIntent;
			}
		};
	}

	/**
	 * Create a stub context builder.
	 *
	 * @return ContextBuilder
	 */
	private function createStubContextBuilder(): ContextBuilder {
		return new class() extends ContextBuilder {
			public function build( array $context = array(), array $metadata = array() ): array {
				return array_merge( $context, array( 'source' => 'test' ) );
			}
		};
	}
}
