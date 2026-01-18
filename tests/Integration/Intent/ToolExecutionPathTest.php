<?php
/**
 * Integration tests covering the full Engine → handler → tool dispatcher execution path.
 */

namespace AgentWP\Tests\Integration\Intent;

use AgentWP\AI\Functions\AbstractFunction;
use AgentWP\AI\Response;
use AgentWP\Contracts\OpenAIClientInterface;
use AgentWP\Intent\ContextBuilder;
use AgentWP\Intent\Engine;
use AgentWP\Intent\FunctionRegistry;
use AgentWP\Intent\HandlerRegistry;
use AgentWP\Intent\Handlers\AbstractAgenticHandler;
use AgentWP\Intent\Handlers\FallbackHandler;
use AgentWP\Intent\Intent;
use AgentWP\Contracts\IntentClassifierInterface;
use AgentWP\Tests\Fakes\FakeAIClientFactory;
use AgentWP\Tests\Fakes\FakeMemoryStore;
use AgentWP\Tests\Fakes\FakeToolRegistry;
use AgentWP\Tests\TestCase;

class ToolExecutionPathTest extends TestCase {
	public function test_engine_agentic_handler_dispatches_tool_and_serializes_result(): void {
		$tool_name = 'test_tool';

		$schema = new class( $tool_name ) extends AbstractFunction {
			private string $name;

			public function __construct( string $name ) {
				$this->name = $name;
			}

			public function get_name() {
				return $this->name;
			}

			public function get_description() {
				return 'Test tool for integration test.';
			}

			public function get_parameters() {
				return array(
					'type'                 => 'object',
					'properties'           => array(
						'order_id' => array(
							'type' => 'integer',
						),
					),
					'required'             => array( 'order_id' ),
					'additionalProperties' => false,
				);
			}
		};

		$registry = new FakeToolRegistry();
		$registry->register( $schema );

		$client = new class() implements OpenAIClientInterface {
			/** @var Response[] */
			public array $responses;
			public array $calls = array();

			public function __construct() {
				$this->responses = array(
					Response::success(
						array(
							'content'    => '',
							'tool_calls' => array(
								array(
									'id'       => 'call_1',
									'type'     => 'function',
									'function' => array(
										'name'      => 'test_tool',
										'arguments' => '{"order_id":123}',
									),
								),
							),
						)
					),
					Response::success(
						array(
							'content'    => 'Done',
							'tool_calls' => array(),
						)
					),
				);
			}

			public function chat( array $messages, array $functions ): Response {
				$this->calls[] = array(
					'messages'  => $messages,
					'functions' => $functions,
				);

				if ( empty( $this->responses ) ) {
					return Response::error( 'No fake responses queued.', 500 );
				}

				return array_shift( $this->responses );
			}

			public function validateKey( string $key ): bool {
				unset( $key );
				return true;
			}
		};

		$handler = new class( new FakeAIClientFactory( $client, true ), $registry ) extends AbstractAgenticHandler {
			private string $toolName;

			public function __construct( FakeAIClientFactory $clientFactory, FakeToolRegistry $toolRegistry ) {
				$this->toolName = 'test_tool';
				parent::__construct( Intent::ORDER_STATUS, $clientFactory, $toolRegistry );
			}

			protected function registerToolExecutors( \AgentWP\Contracts\ToolDispatcherInterface $dispatcher ): void {
				$dispatcher->register(
					$this->toolName,
					fn( array $args ) => 'OK:' . (string) ( $args['order_id'] ?? '' )
				);
			}

			protected function getSystemPrompt(): string {
				return 'Test.';
			}

			protected function getToolNames(): array {
				return array( $this->toolName );
			}
		};

		$classifier = new class() implements IntentClassifierInterface {
			public function classify( string $input, array $context = array() ): string {
				unset( $input, $context );
				return Intent::ORDER_STATUS;
			}
		};

		$builder = new class() extends ContextBuilder {
			public function build( array $context = array(), array $metadata = array() ): array {
				return $context;
			}
		};

		$memory = new FakeMemoryStore();
		$handler_registry = new HandlerRegistry();
		$handler_registry->register( Intent::ORDER_STATUS, $handler );

		$engine = new Engine(
			array(),
			new FunctionRegistry(),
			$builder,
			$classifier,
			$memory,
			$handler_registry,
			new FallbackHandler()
		);

		$response = $engine->handle( 'status check' );
		$this->assertTrue( $response->is_success() );
		$this->assertSame( 'Done', $response->get_data()['message'] );

		$this->assertCount( 2, $client->calls );
		$second_call_messages = $client->calls[1]['messages'];

		// Messages should include: system, user, assistant(tool_calls), tool(result).
		$this->assertGreaterThanOrEqual( 4, count( $second_call_messages ) );
		$this->assertSame( 'tool', $second_call_messages[ count( $second_call_messages ) - 1 ]['role'] );
		$this->assertSame( 'call_1', $second_call_messages[ count( $second_call_messages ) - 1 ]['tool_call_id'] );

		$decoded = json_decode( $second_call_messages[ count( $second_call_messages ) - 1 ]['content'], true );
		$this->assertSame( array( 'result' => 'OK:123' ), $decoded );
	}

	public function test_engine_agentic_handler_returns_validation_error_for_invalid_tool_args(): void {
		$tool_name = 'test_tool';

		$schema = new class( $tool_name ) extends AbstractFunction {
			private string $name;

			public function __construct( string $name ) {
				$this->name = $name;
			}

			public function get_name() {
				return $this->name;
			}

			public function get_description() {
				return 'Test tool for validation.';
			}

			public function get_parameters() {
				return array(
					'type'                 => 'object',
					'properties'           => array(
						'order_id' => array(
							'type' => 'integer',
						),
					),
					'required'             => array( 'order_id' ),
					'additionalProperties' => false,
				);
			}
		};

		$registry = new FakeToolRegistry();
		$registry->register( $schema );

		$client = new class() implements OpenAIClientInterface {
			/** @var Response[] */
			public array $responses;
			public array $calls = array();

			public function __construct() {
				$this->responses = array(
					Response::success(
						array(
							'content'    => '',
							'tool_calls' => array(
								array(
									'id'       => 'call_1',
									'type'     => 'function',
									'function' => array(
										'name'      => 'test_tool',
										'arguments' => '{}',
									),
								),
							),
						)
					),
					Response::success(
						array(
							'content'    => 'Done',
							'tool_calls' => array(),
						)
					),
				);
			}

			public function chat( array $messages, array $functions ): Response {
				$this->calls[] = array(
					'messages'  => $messages,
					'functions' => $functions,
				);

				if ( empty( $this->responses ) ) {
					return Response::error( 'No fake responses queued.', 500 );
				}

				return array_shift( $this->responses );
			}

			public function validateKey( string $key ): bool {
				unset( $key );
				return true;
			}
		};

		$handler = new class( new FakeAIClientFactory( $client, true ), $registry ) extends AbstractAgenticHandler {
			public function __construct( FakeAIClientFactory $clientFactory, FakeToolRegistry $toolRegistry ) {
				parent::__construct( Intent::ORDER_STATUS, $clientFactory, $toolRegistry );
			}

			protected function registerToolExecutors( \AgentWP\Contracts\ToolDispatcherInterface $dispatcher ): void {
				$dispatcher->register( 'test_tool', fn( array $args ) => array( 'ok' => true ) );
			}

			protected function getSystemPrompt(): string {
				return 'Test.';
			}

			protected function getToolNames(): array {
				return array( 'test_tool' );
			}
		};

		$classifier = new class() implements IntentClassifierInterface {
			public function classify( string $input, array $context = array() ): string {
				unset( $input, $context );
				return Intent::ORDER_STATUS;
			}
		};

		$builder = new class() extends ContextBuilder {
			public function build( array $context = array(), array $metadata = array() ): array {
				return $context;
			}
		};

		$memory = new FakeMemoryStore();
		$handler_registry = new HandlerRegistry();
		$handler_registry->register( Intent::ORDER_STATUS, $handler );

		$engine = new Engine(
			array(),
			new FunctionRegistry(),
			$builder,
			$classifier,
			$memory,
			$handler_registry,
			new FallbackHandler()
		);

		$response = $engine->handle( 'status check' );
		$this->assertTrue( $response->is_success() );
		$this->assertSame( 'Done', $response->get_data()['message'] );

		$this->assertCount( 2, $client->calls );
		$second_call_messages = $client->calls[1]['messages'];
		$tool_message         = $second_call_messages[ count( $second_call_messages ) - 1 ];

		$this->assertSame( 'tool', $tool_message['role'] );
		$decoded = json_decode( $tool_message['content'], true );

		$this->assertIsArray( $decoded );
		$this->assertFalse( $decoded['success'] );
		$this->assertSame( 'invalid_tool_arguments', $decoded['code'] );
		$this->assertNotEmpty( $decoded['validation_errors'] );
	}
}
