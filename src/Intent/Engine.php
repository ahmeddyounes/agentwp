<?php
/**
 * Intent engine for routing requests.
 *
 * @package AgentWP
 */

namespace AgentWP\Intent;

use AgentWP\AI\Response;
use AgentWP\Contracts\ContextBuilderInterface;
use AgentWP\Contracts\HooksInterface;
use AgentWP\Contracts\IntentClassifierInterface;
use AgentWP\Contracts\MemoryStoreInterface;
use AgentWP\Contracts\ToolRegistryInterface;
use AgentWP\Intent\ToolSuggestionProvider;

class Engine {
	/**
	 * @var IntentClassifierInterface
	 */
	private $classifier;

	/**
	 * @var ContextBuilderInterface
	 */
	private $context_builder;

	/**
	 * @var MemoryStoreInterface
	 */
	private $memory;

	/**
	 * @var FunctionRegistry
	 */
	private $function_registry;

	/**
	 * @var HandlerRegistry
	 */
	private $handler_registry;

	/**
	 * Fallback handler for unknown intents.
	 *
	 * @var Handler
	 */
	private $fallback_handler;

	/**
	 * WordPress hooks adapter for filters and actions.
	 *
	 * @var HooksInterface
	 */
	private $hooks;

	/**
	 * @param array                     $handlers          Handlers to register.
	 * @param FunctionRegistry          $function_registry Function registry.
	 * @param ContextBuilderInterface   $context_builder   Context builder.
	 * @param IntentClassifierInterface $classifier        Intent classifier.
	 * @param MemoryStoreInterface      $memory            Memory store.
	 * @param HandlerRegistry           $handler_registry  Handler registry.
	 * @param Handler                   $fallback_handler  Fallback handler for unknown intents.
	 * @param HooksInterface|null       $hooks             Hooks adapter (optional for backward compatibility).
	 * @param ToolRegistryInterface|null $tool_registry    Tool registry (optional, used for suggestions).
	 */
	public function __construct(
		array $handlers,
		FunctionRegistry $function_registry,
		ContextBuilderInterface $context_builder,
		IntentClassifierInterface $classifier,
		MemoryStoreInterface $memory,
		HandlerRegistry $handler_registry,
		Handler $fallback_handler,
		?HooksInterface $hooks = null,
		?ToolRegistryInterface $tool_registry = null
	) {
		$this->classifier        = $classifier;
		$this->context_builder   = $context_builder;
		$this->memory            = $memory;
		$this->function_registry = $function_registry;
		$this->handler_registry  = $handler_registry;
		$this->fallback_handler  = $fallback_handler;
		$this->hooks             = $hooks;

		$resolved_handlers = $this->hooks
			? $this->hooks->applyFilters( 'agentwp_intent_handlers', $handlers, $this )
			: $handlers;

		$this->register_handlers( is_array( $resolved_handlers ) ? $resolved_handlers : array() );
		$this->function_registry->set_handler_registry( $this->handler_registry );
		if ( $tool_registry ) {
			$this->function_registry->set_tool_registry( $tool_registry );
		}

		$this->register_default_functions();

		if ( $this->hooks ) {
			$this->hooks->doAction( 'agentwp_register_intent_functions', $this->function_registry, $this );
		}
	}

	/**
	 * Register handlers with the registry.
	 *
	 * Handlers must declare their supported intents via the #[HandlesIntent] attribute.
	 * Handlers without the attribute will be skipped with a warning in WP_DEBUG mode.
	 *
	 * @param Handler[] $handlers Array of handlers to register.
	 * @return void
	 */
	private function register_handlers( array $handlers ): void {
		foreach ( $handlers as $handler ) {
			if ( ! ( $handler instanceof Handler ) ) {
				continue;
			}

			$intents = $this->get_handler_intents( $handler );

			if ( ! empty( $intents ) ) {
				$this->handler_registry->register( $intents, $handler );
			} elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// Warn about handlers missing #[HandlesIntent] attribute.
				$message = sprintf(
					'AgentWP: Handler %s is missing #[HandlesIntent] attribute and will not be registered. ' .
					'See docs/adr/0002-intent-handler-registration.md for migration instructions.',
					get_class( $handler )
				);
				if ( function_exists( '_doing_it_wrong' ) ) {
					_doing_it_wrong( esc_html( get_class( $handler ) ), esc_html( $message ), '2.0.0' );
				} elseif ( function_exists( 'trigger_error' ) ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
					trigger_error( esc_html( $message ), E_USER_WARNING );
				}
			}
		}
	}

	/**
	 * Get intents supported by a handler from its #[HandlesIntent] attribute.
	 *
	 * @param Handler $handler Handler instance.
	 * @return string[] Array of intent identifiers.
	 */
	private function get_handler_intents( Handler $handler ): array {
		try {
			$reflection = new \ReflectionClass( $handler );
			$attributes = $reflection->getAttributes( Attributes\HandlesIntent::class );

			if ( ! empty( $attributes ) ) {
				$attribute = $attributes[0]->newInstance();
				return $attribute->getIntents();
			}
		} catch ( \ReflectionException ) {
			// Reflection failed - handler will not be registered.
		}

		return array();
	}

	/**
	 * Handle a natural language input.
	 *
	 * @param string $input User input.
	 * @param array  $context Request context.
	 * @param array  $metadata Request metadata.
	 * @return Response
	 */
	public function handle( $input, array $context = array(), array $metadata = array() ) {
		$input = is_string( $input ) ? trim( $input ) : '';
		if ( '' === $input ) {
			return Response::error( 'Missing intent input.', 400 );
		}

		$enriched = $this->context_builder->build( $context, $metadata );
		$enriched['input']   = $input;
		$enriched['memory']  = $this->memory->get();
		$enriched['intent']  = $this->classifier->classify( $input, $enriched );
		$enriched['intent']  = Intent::normalize( $enriched['intent'] );
		$enriched['function_suggestions'] = $this->function_registry->get_functions_for_intent( $enriched['intent'] );

		$handler  = $this->resolve_handler( $enriched['intent'] );
		$response = $handler->handle( $enriched );

		$this->memory->addExchange(
			array(
				'time'    => gmdate( 'c' ),
				'input'   => $input,
				'intent'  => $enriched['intent'],
				'message' => $this->response_message( $response ),
			)
		);

		return $response;
	}

	/**
	 * @return FunctionRegistry
	 */
	public function get_function_registry() {
		return $this->function_registry;
	}

	/**
	 * @return array
	 */
	public function get_memory() {
		return $this->memory->get();
	}

	/**
	 * Resolve handler for an intent using O(1) registry lookup.
	 *
	 * @param string $intent Intent identifier.
	 * @return Handler
	 */
	private function resolve_handler( string $intent ): Handler {
		return $this->handler_registry->getOrFallback( $intent, $this->fallback_handler );
	}

	/**
	 * Get the handler registry.
	 *
	 * Allows external code to inspect and modify registered handlers.
	 *
	 * @return HandlerRegistry The handler registry.
	 */
	public function get_handler_registry(): HandlerRegistry {
		return $this->handler_registry;
	}

	/**
	 * @param Response $response Response instance.
	 * @return string
	 */
	private function response_message( Response $response ) {
		if ( ! $response->is_success() ) {
			return $response->get_message();
		}

		$data = $response->get_data();
		if ( is_array( $data ) && isset( $data['message'] ) ) {
			return (string) $data['message'];
		}

		return '';
	}

	/**
	 * Register default functions with their associated handlers.
	 *
	 * @return void
	 */
	private function register_default_functions(): void {
		$mapping = array();

		foreach ( $this->handler_registry->intents() as $intent ) {
			$handler = $this->handler_registry->get( $intent );
			if ( ! $handler instanceof ToolSuggestionProvider ) {
				continue;
			}

			$tools = $handler->getSuggestedTools();
			if ( ! empty( $tools ) ) {
				$mapping[ $intent ] = $tools;
			}
		}

		if ( $this->hooks ) {
			$mapping = $this->hooks->applyFilters( 'agentwp_default_function_mapping', $mapping, $this );
		}

		foreach ( $mapping as $intent => $functions ) {
			// Only register functions if a handler is explicitly registered for the intent.
			if ( ! $this->handler_registry->has( $intent ) ) {
				continue;
			}

			$handler = $this->handler_registry->get( $intent );
			foreach ( $functions as $function_name ) {
				$this->function_registry->register( $function_name, $handler );
			}
		}
	}
}
