<?php
/**
 * Abstract base class for agentic intent handlers.
 *
 * Encapsulates the common agentic loop pattern used by all AI-powered handlers.
 *
 * @package AgentWP\Intent\Handlers
 */

namespace AgentWP\Intent\Handlers;

use AgentWP\AI\Response;
use AgentWP\Config\AgentWPConfig;
use AgentWP\Contracts\AIClientFactoryInterface;
use AgentWP\Contracts\OpenAIClientInterface;
use AgentWP\Contracts\ToolDispatcherInterface;
use AgentWP\Contracts\ToolRegistryInterface;
use AgentWP\Intent\ToolDispatcher;
use AgentWP\Intent\ToolSuggestionProvider;

/**
 * Abstract handler that provides the agentic interaction loop.
 *
 * Subclasses only need to implement:
 * - getSystemPrompt(): string
 * - getToolNames(): array
 * - registerToolExecutors(ToolDispatcherInterface $dispatcher): void
 */
abstract class AbstractAgenticHandler extends BaseHandler implements ToolSuggestionProvider {

	/**
	 * Get maximum number of interaction turns before giving up.
	 *
	 * Configurable via 'agentwp_config_agentic_max_turns' filter.
	 *
	 * @return int
	 */
	protected static function getMaxTurns(): int {
		return (int) AgentWPConfig::get( 'agentic.max_turns', AgentWPConfig::AGENTIC_MAX_TURNS );
	}

	/**
	 * @var AIClientFactoryInterface
	 */
	protected AIClientFactoryInterface $clientFactory;

	/**
	 * @var ToolRegistryInterface
	 */
	protected ToolRegistryInterface $toolRegistry;

	/**
	 * @var ToolDispatcherInterface
	 */
	protected ToolDispatcherInterface $toolDispatcher;

	/**
	 * Initialize the handler.
	 *
	 * @param string                       $intent         Intent identifier.
	 * @param AIClientFactoryInterface     $clientFactory  AI client factory.
	 * @param ToolRegistryInterface        $toolRegistry   Tool registry.
	 * @param ToolDispatcherInterface|null $toolDispatcher Tool dispatcher (optional, created if not provided).
	 */
	public function __construct(
		string $intent,
		AIClientFactoryInterface $clientFactory,
		ToolRegistryInterface $toolRegistry,
		?ToolDispatcherInterface $toolDispatcher = null
	) {
		parent::__construct( $intent );
		$this->clientFactory  = $clientFactory;
		$this->toolRegistry   = $toolRegistry;
		$this->toolDispatcher = $toolDispatcher ?? new ToolDispatcher( $toolRegistry );

		// Let subclass register its tool executors.
		$this->registerToolExecutors( $this->toolDispatcher );
	}

	/**
	 * Register tool executors with the dispatcher.
	 *
	 * Subclasses must implement this to register their tool executors.
	 *
	 * @param ToolDispatcherInterface $dispatcher The tool dispatcher.
	 * @return void
	 */
	abstract protected function registerToolExecutors( ToolDispatcherInterface $dispatcher ): void;

	/**
	 * Get the system prompt for this handler.
	 *
	 * @return string
	 */
	abstract protected function getSystemPrompt(): string;

	/**
	 * Get the tool names this handler requires.
	 *
	 * @return array<string> Array of tool names.
	 */
	abstract protected function getToolNames(): array;

	/**
	 * Get tool names to surface as suggestions for this handler.
	 *
	 * @return array<string>
	 */
	public function getSuggestedTools(): array {
		return $this->getToolNames();
	}

	/**
	 * Get the tools available to this handler from the registry.
	 *
	 * @return array Array of FunctionSchema instances.
	 */
	protected function getTools(): array {
		return $this->toolRegistry->getMany( $this->getToolNames() );
	}

	/**
	 * Get the default input when none is provided in context.
	 *
	 * Override in subclasses to provide intent-specific defaults.
	 *
	 * @return string Default user input.
	 */
	protected function getDefaultInput(): string {
		return '';
	}

	/**
	 * Handle the intent using the agentic loop.
	 *
	 * @param array $context Context data including 'input' key.
	 * @return Response
	 */
	public function handle( array $context ): Response {
		if ( ! $this->clientFactory->hasApiKey() ) {
			return Response::error( 'OpenAI API key is missing. Please configure it in AgentWP settings.', 401 );
		}

		$client = $this->createClient();
		$tools  = $this->getTools();

		$messages = $this->buildInitialMessages( $context );

		return $this->runAgenticLoop( $client, $messages, $tools, $context );
	}

	/**
	 * Create an AI client for this handler.
	 *
	 * @param array $options Optional client configuration.
	 * @return OpenAIClientInterface
	 */
	protected function createClient( array $options = array() ): OpenAIClientInterface {
		return $this->clientFactory->create( $this->intent, $options );
	}

	/**
	 * Build the initial messages array for the conversation.
	 *
	 * @param array $context Context data.
	 * @return array Messages array.
	 */
	protected function buildInitialMessages( array $context ): array {
		$messages = array();

		// Use custom system prompt if provided in context, otherwise use handler's default.
		$system_prompt = ! empty( $context['system_prompt'] )
			? $context['system_prompt']
			: $this->getSystemPrompt();

		$messages[] = array(
			'role'    => 'system',
			'content' => $system_prompt,
		);

		$input = ! empty( $context['input'] ) ? $context['input'] : $this->getDefaultInput();

		$messages[] = array(
			'role'    => 'user',
			'content' => $input,
		);

		return $messages;
	}

	/**
	 * Run the agentic interaction loop.
	 *
	 * @param OpenAIClientInterface $client AI client.
	 * @param array                 $messages Initial messages.
	 * @param array                 $tools Available tools.
	 * @param array                 $context Request context.
	 * @return Response
	 */
	protected function runAgenticLoop(
		OpenAIClientInterface $client,
		array $messages,
		array $tools,
		array $context
	): Response {
		$maxTurns = static::getMaxTurns();
		for ( $turn = 0; $turn < $maxTurns; $turn++ ) {
			$response = $client->chat( $messages, $tools );

			if ( ! $response->is_success() ) {
				return $response;
			}

			$data       = $response->get_data();
			$content    = $data['content'] ?? '';
			$tool_calls = $data['tool_calls'] ?? array();

			// Add assistant message to history.
			$assistant_msg = array(
				'role'    => 'assistant',
				'content' => $content,
			);

			if ( ! empty( $tool_calls ) ) {
				$assistant_msg['tool_calls'] = $tool_calls;
			}

			$messages[] = $assistant_msg;

			// If no tool calls, the assistant is done - return the response.
			if ( empty( $tool_calls ) ) {
				return $this->build_response( $context, $content );
			}

			// Execute each tool call via the dispatcher.
			$messages = $this->executeToolCalls( $messages, $tool_calls );
		}

		return Response::error(
			'I got stuck in a loop while processing your request. Please try again.',
			500
		);
	}

	/**
	 * Execute tool calls and add results to messages.
	 *
	 * Delegates to the ToolDispatcher for validation and execution.
	 *
	 * @param array $messages Current messages.
	 * @param array $tool_calls Tool calls from AI response.
	 * @return array Updated messages with tool results.
	 */
	protected function executeToolCalls( array $messages, array $tool_calls ): array {
		foreach ( $tool_calls as $call ) {
			// Safely extract function data with defensive checks.
			$function  = isset( $call['function'] ) && is_array( $call['function'] ) ? $call['function'] : array();
			$name      = isset( $function['name'] ) ? (string) $function['name'] : '';
			$args_json = isset( $function['arguments'] ) ? (string) $function['arguments'] : '{}';
			$args      = json_decode( $args_json, true );

			if ( ! is_array( $args ) ) {
				$args = array();
			}

			// Delegate to the tool dispatcher for validation and execution.
			$result = $this->toolDispatcher->dispatch( $name, $args );

			// Ensure JSON encoding succeeds.
			$encoded_result = wp_json_encode( $result );
			if ( false === $encoded_result ) {
				$encoded_result = wp_json_encode( array( 'error' => 'Failed to encode tool result' ) );
			}

			$messages[] = array(
				'role'         => 'tool',
				'tool_call_id' => isset( $call['id'] ) ? (string) $call['id'] : '',
				'content'      => $encoded_result,
			);
		}

		return $messages;
	}
}
