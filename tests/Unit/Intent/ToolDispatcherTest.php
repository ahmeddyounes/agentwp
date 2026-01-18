<?php
/**
 * ToolDispatcher unit tests.
 */

namespace AgentWP\Tests\Unit\Intent;

use AgentWP\AI\Functions\FunctionSchema;
use AgentWP\Intent\ToolDispatcher;
use AgentWP\Tests\Fakes\FakeToolRegistry;
use AgentWP\Tests\TestCase;
use AgentWP\Validation\ToolArgumentValidator;

class ToolDispatcherTest extends TestCase {

	/**
	 * @var FakeToolRegistry
	 */
	private FakeToolRegistry $toolRegistry;

	/**
	 * @var ToolDispatcher
	 */
	private ToolDispatcher $dispatcher;

	public function setUp(): void {
		parent::setUp();
		$this->toolRegistry = new FakeToolRegistry();
		$this->dispatcher   = new ToolDispatcher( $this->toolRegistry );
	}

	public function test_register_and_has_tool(): void {
		$this->assertFalse( $this->dispatcher->has( 'my_tool' ) );

		$this->dispatcher->register( 'my_tool', fn() => array( 'success' => true ) );

		$this->assertTrue( $this->dispatcher->has( 'my_tool' ) );
	}

	public function test_register_many_tools(): void {
		$this->dispatcher->registerMany(
			array(
				'tool_a' => fn() => array( 'a' => true ),
				'tool_b' => fn() => array( 'b' => true ),
			)
		);

		$this->assertTrue( $this->dispatcher->has( 'tool_a' ) );
		$this->assertTrue( $this->dispatcher->has( 'tool_b' ) );
	}

	public function test_dispatch_executes_registered_tool(): void {
		$this->dispatcher->register(
			'greet',
			fn( array $args ) => array( 'message' => 'Hello, ' . ( $args['name'] ?? 'World' ) )
		);

		$result = $this->dispatcher->dispatch( 'greet', array( 'name' => 'Alice' ) );

		$this->assertSame( array( 'message' => 'Hello, Alice' ), $result );
	}

	public function test_dispatch_returns_error_for_unknown_tool(): void {
		$result = $this->dispatcher->dispatch( 'unknown_tool', array() );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'Unknown tool', $result['error'] );
	}

	public function test_dispatch_validates_arguments_against_schema(): void {
		// Create a schema that requires 'name' parameter.
		$schema = new class() implements FunctionSchema {
			public function get_name(): string {
				return 'strict_tool';
			}

			public function get_description(): string {
				return 'A tool with strict validation';
			}

			public function get_parameters(): array {
				return array(
					'type'       => 'object',
					'properties' => array(
						'name' => array(
							'type'        => 'string',
							'description' => 'The name',
						),
					),
					'required'   => array( 'name' ),
				);
			}

			public function to_tool_definition(): array {
				return array(
					'type'     => 'function',
					'function' => array(
						'name'        => $this->get_name(),
						'description' => $this->get_description(),
						'parameters'  => $this->get_parameters(),
					),
				);
			}
		};

		$this->toolRegistry->register( $schema );

		$this->dispatcher->register(
			'strict_tool',
			fn( array $args ) => array( 'success' => true, 'name' => $args['name'] )
		);

		// Test with valid arguments.
		$result = $this->dispatcher->dispatch( 'strict_tool', array( 'name' => 'Test' ) );
		$this->assertSame( array( 'success' => true, 'name' => 'Test' ), $result );

		// Test with missing required argument - should return validation error.
		$result = $this->dispatcher->dispatch( 'strict_tool', array() );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertArrayHasKey( 'code', $result );
		$this->assertSame( 'invalid_tool_arguments', $result['code'] );
	}

	public function test_dispatch_skips_validation_when_no_schema(): void {
		// Register tool without schema in registry.
		$this->dispatcher->register(
			'no_schema_tool',
			fn( array $args ) => array( 'result' => $args['data'] ?? 'default' )
		);

		// Should work without validation.
		$result = $this->dispatcher->dispatch( 'no_schema_tool', array( 'data' => 'test' ) );
		$this->assertSame( array( 'result' => 'test' ), $result );
	}

	public function test_dispatch_wraps_scalar_result_in_array(): void {
		$this->dispatcher->register(
			'string_result',
			fn() => 'just a string'
		);

		$result = $this->dispatcher->dispatch( 'string_result', array() );
		$this->assertSame( array( 'result' => 'just a string' ), $result );
	}

	public function test_dispatch_wraps_null_result_in_array(): void {
		$this->dispatcher->register(
			'null_result',
			fn() => null
		);

		$result = $this->dispatcher->dispatch( 'null_result', array() );
		$this->assertSame( array( 'result' => null ), $result );
	}

	public function test_dispatch_handles_non_json_encodable_result(): void {
		$this->dispatcher->register(
			'bad_result',
			function () {
				// Create a resource which cannot be JSON encoded.
				$resource = fopen( 'php://memory', 'r' );
				// Return the resource directly - this will fail JSON encoding.
				return array( 'resource' => $resource );
			}
		);

		$result = $this->dispatcher->dispatch( 'bad_result', array() );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'Failed to encode', $result['error'] );
	}
}
