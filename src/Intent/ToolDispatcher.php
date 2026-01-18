<?php
/**
 * Tool dispatcher service implementation.
 *
 * Centralizes tool execution that was previously duplicated across
 * handler `execute_tool()` implementations.
 *
 * @package AgentWP\Intent
 */

namespace AgentWP\Intent;

use AgentWP\Contracts\AuditLoggerInterface;
use AgentWP\Contracts\ExecutableToolInterface;
use AgentWP\Contracts\LoggerInterface;
use AgentWP\Contracts\ToolDispatcherInterface;
use AgentWP\Contracts\ToolRegistryInterface;
use AgentWP\Infrastructure\NullLogger;
use AgentWP\Validation\ToolArgumentValidator;

/**
 * Dispatches tool execution calls to registered executors.
 *
 * Provides centralized tool execution with:
 * - Tool registration with callable executors or ExecutableTool instances
 * - Argument validation against JSON schemas from ToolRegistry
 * - Execution with JSON-safe result handling
 */
class ToolDispatcher implements ToolDispatcherInterface {

	/**
	 * Registered tool executors.
	 *
	 * @var array<string, callable>
	 */
	private array $executors = array();

	/**
	 * Tool registry for schema validation.
	 *
	 * @var ToolRegistryInterface
	 */
	private ToolRegistryInterface $toolRegistry;

	/**
	 * Argument validator.
	 *
	 * @var ToolArgumentValidator
	 */
	private ToolArgumentValidator $validator;

	/**
	 * Logger for tool dispatch failures.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * Audit logger for sensitive tool dispatch failures.
	 *
	 * @var AuditLoggerInterface|null
	 */
	private ?AuditLoggerInterface $auditLogger;

	/**
	 * Initialize the dispatcher.
	 *
	 * @param ToolRegistryInterface       $toolRegistry Tool registry for schema lookup.
	 * @param ToolArgumentValidator|null  $validator    Argument validator (optional).
	 * @param LoggerInterface|null        $logger       Logger for tool dispatch failures (optional).
	 * @param AuditLoggerInterface|null   $auditLogger  Audit logger for tool dispatch failures (optional).
	 */
	public function __construct(
		ToolRegistryInterface $toolRegistry,
		?ToolArgumentValidator $validator = null,
		?LoggerInterface $logger = null,
		?AuditLoggerInterface $auditLogger = null
	) {
		$this->toolRegistry = $toolRegistry;
		$this->validator    = $validator ?? new ToolArgumentValidator();
		$this->logger       = $logger ?? new NullLogger();
		$this->auditLogger  = $auditLogger;
	}

	/**
	 * Register a tool executor.
	 *
	 * @param string   $name     Tool name.
	 * @param callable $executor Callable that executes the tool: fn(array $args): mixed
	 * @return void
	 */
	public function register( string $name, callable $executor ): void {
		$this->executors[ $name ] = $executor;
	}

	/**
	 * Register multiple tool executors.
	 *
	 * @param array<string, callable> $executors Map of tool name to executor callable.
	 * @return void
	 */
	public function registerMany( array $executors ): void {
		foreach ( $executors as $name => $executor ) {
			$this->register( $name, $executor );
		}
	}

	/**
	 * Register an executable tool.
	 *
	 * @param ExecutableToolInterface $tool Executable tool instance.
	 * @return void
	 */
	public function registerTool( ExecutableToolInterface $tool ): void {
		$this->register(
			$tool->getName(),
			fn( array $args ): array => $tool->execute( $args )
		);
	}

	/**
	 * Register multiple executable tools.
	 *
	 * @param array<ExecutableToolInterface> $tools Array of executable tool instances.
	 * @return void
	 */
	public function registerTools( array $tools ): void {
		foreach ( $tools as $tool ) {
			$this->registerTool( $tool );
		}
	}

	/**
	 * Check if a tool executor is registered.
	 *
	 * @param string $name Tool name.
	 * @return bool
	 */
	public function has( string $name ): bool {
		return isset( $this->executors[ $name ] );
	}

	/**
	 * Dispatch a tool execution.
	 *
	 * Resolves the tool by name, validates arguments against the schema
	 * (if available in the ToolRegistry), executes the tool, and returns
	 * a JSON-safe result.
	 *
	 * @param string $name      Tool name.
	 * @param array  $arguments Tool arguments.
	 * @return array JSON-safe execution result.
	 */
	public function dispatch( string $name, array $arguments ): array {
		// Check if executor is registered.
		if ( ! $this->has( $name ) ) {
			$this->logDispatchFailure(
				'unknown_tool',
				$name,
				array(
					'argument_count' => count( $arguments ),
				)
			);

			return $this->unknownToolError( $name );
		}

		// Validate arguments against schema if available.
		$validation_error = $this->validateArguments( $name, $arguments );
		if ( null !== $validation_error ) {
			$this->logDispatchFailure(
				'invalid_tool_arguments',
				$name,
				$this->extractValidationContext( $validation_error )
			);

			return $validation_error;
		}

		// Execute the tool.
		$result = $this->executeAndSanitize( $name, $arguments );

		return $result;
	}

	/**
	 * Validate tool arguments against the schema.
	 *
	 * @param string $name      Tool name.
	 * @param array  $arguments Tool arguments.
	 * @return array|null Error array if validation fails, null if valid.
	 */
	private function validateArguments( string $name, array $arguments ): ?array {
		$schema = $this->toolRegistry->get( $name );

		// Skip validation if schema not found.
		if ( null === $schema ) {
			return null;
		}

		$result = $this->validator->validate( $schema, $arguments );

		if ( ! $result->isValid ) {
			return $result->toErrorArray();
		}

		return null;
	}

	/**
	 * Execute a tool and ensure JSON-safe result.
	 *
	 * @param string $name      Tool name.
	 * @param array  $arguments Tool arguments.
	 * @return array JSON-safe result.
	 */
	private function executeAndSanitize( string $name, array $arguments ): array {
		$executor = $this->executors[ $name ];
		$result   = $executor( $arguments );

		// Ensure result is an array.
		if ( ! is_array( $result ) ) {
			// Wrap scalar values.
			if ( is_scalar( $result ) || is_null( $result ) ) {
				$result = array( 'result' => $result );
			} else {
				// Try to convert objects to arrays.
				$result = $this->toArray( $result );
			}
		}

		// Verify JSON encoding succeeds.
		$encoded = wp_json_encode( $result );
		if ( false === $encoded ) {
			return array( 'error' => 'Failed to encode tool result as JSON' );
		}

		return $result;
	}

	/**
	 * Convert a value to an array.
	 *
	 * @param mixed $value Value to convert.
	 * @return array
	 */
	private function toArray( $value ): array {
		if ( is_array( $value ) ) {
			return $value;
		}

		if ( is_object( $value ) ) {
			// Check for common conversion methods.
			if ( method_exists( $value, 'toArray' ) ) {
				return $value->toArray();
			}
			if ( method_exists( $value, 'toLegacyArray' ) ) {
				return $value->toLegacyArray();
			}
			if ( method_exists( $value, 'jsonSerialize' ) ) {
				$serialized = $value->jsonSerialize();
				return is_array( $serialized ) ? $serialized : array( 'result' => $serialized );
			}
			// Fall back to casting.
			return (array) $value;
		}

		return array( 'result' => $value );
	}

	/**
	 * Build a consistent error response for unknown tools.
	 *
	 * @param string $name Tool name.
	 * @return array{success: false, error: string, code: string}
	 */
	private function unknownToolError( string $name ): array {
		return array(
			'success' => false,
			'error'   => sprintf( 'Unknown tool "%s".', $name ),
			'code'    => 'unknown_tool',
		);
	}

	/**
	 * Log tool dispatch failures without leaking sensitive data.
	 *
	 * @param string $reason  Failure reason.
	 * @param string $tool    Tool name.
	 * @param array  $context Additional safe context.
	 * @return void
	 */
	private function logDispatchFailure( string $reason, string $tool, array $context = array() ): void {
		$payload = array_merge(
			array(
				'tool'   => $tool,
				'reason' => $reason,
			),
			$context
		);

		$this->logger->warning( 'Tool dispatch failed.', $payload );

		if ( null !== $this->auditLogger ) {
			$this->auditLogger->logSensitiveAction(
				'tool_dispatch_failure',
				$this->getCurrentUserId(),
				$payload
			);
		}
	}

	/**
	 * Extract safe validation context for logging.
	 *
	 * @param array $validation_error Validation error array.
	 * @return array<string, mixed>
	 */
	private function extractValidationContext( array $validation_error ): array {
		$errors = isset( $validation_error['validation_errors'] ) && is_array( $validation_error['validation_errors'] )
			? $validation_error['validation_errors']
			: array();

		$fields = array();
		$codes  = array();

		foreach ( $errors as $error ) {
			if ( isset( $error['field'] ) && '' !== $error['field'] ) {
				$fields[] = $error['field'];
			}
			if ( isset( $error['code'] ) && '' !== $error['code'] ) {
				$codes[] = $error['code'];
			}
		}

		return array(
			'validation_count'  => count( $errors ),
			'validation_fields' => array_values( array_unique( $fields ) ),
			'validation_codes'  => array_values( array_unique( $codes ) ),
		);
	}

	/**
	 * Get the current user ID in a safe way.
	 *
	 * @return int
	 */
	private function getCurrentUserId(): int {
		if ( function_exists( 'get_current_user_id' ) ) {
			return (int) get_current_user_id();
		}

		return 0;
	}
}
