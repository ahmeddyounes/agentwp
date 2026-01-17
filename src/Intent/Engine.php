<?php
/**
 * Intent engine for routing requests.
 *
 * @package AgentWP
 */

namespace AgentWP\Intent;

use AgentWP\AI\Response;
use AgentWP\Contracts\ContextBuilderInterface;
use AgentWP\Contracts\IntentClassifierInterface;
use AgentWP\Contracts\MemoryStoreInterface;
use AgentWP\Intent\Attributes\HandlesIntent;

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
	 * @var Handler[]
	 */
	private $handlers = array();

	/**
	 * @var Handler
	 */
	private $fallback_handler;

	/**
	 * Flag to track if we need fallback lookup.
	 * Set to true when any handler lacks explicit intent registration.
	 *
	 * @var bool
	 */
	private $needs_fallback_lookup = false;

	/**
	 * @param array                     $handlers          Handlers to register.
	 * @param FunctionRegistry          $function_registry Function registry.
	 * @param ContextBuilderInterface   $context_builder   Context builder.
	 * @param IntentClassifierInterface $classifier        Intent classifier.
	 * @param MemoryStoreInterface      $memory            Memory store.
	 * @param HandlerRegistry           $handler_registry  Handler registry.
	 * @param Handler                   $fallback_handler  Fallback handler for unknown intents.
	 */
	public function __construct(
		array $handlers,
		FunctionRegistry $function_registry,
		ContextBuilderInterface $context_builder,
		IntentClassifierInterface $classifier,
		MemoryStoreInterface $memory,
		HandlerRegistry $handler_registry,
		Handler $fallback_handler
	) {
		$this->classifier        = $classifier;
		$this->context_builder   = $context_builder;
		$this->memory            = $memory;
		$this->function_registry = $function_registry;
		$this->handler_registry  = $handler_registry;
		$this->fallback_handler  = $fallback_handler;

		$resolved_handlers = $handlers;
		if ( function_exists( 'apply_filters' ) ) {
			$resolved_handlers = apply_filters( 'agentwp_intent_handlers', $resolved_handlers, $this );
		}

		$this->register_handlers( is_array( $resolved_handlers ) ? $resolved_handlers : array() );
		$this->register_default_functions();

		if ( function_exists( 'do_action' ) ) {
			do_action( 'agentwp_register_intent_functions', $this->function_registry, $this );
		}
	}

	/**
	 * Register handlers with the registry.
	 *
	 * For backward compatibility, this also maintains a list of handlers
	 * for fallback O(n) lookup when HandlerRegistry doesn't have the intent.
	 *
	 * @param Handler[] $handlers Array of handlers to register.
	 * @return void
	 */
	private function register_handlers( array $handlers ): void {
		foreach ( $handlers as $handler ) {
			if ( $handler instanceof Handler ) {
				// Store handler for potential fallback lookup.
				$this->handlers[] = $handler;

				// Get supported intents from the handler.
				$intents = $this->get_handler_intents( $handler );

				if ( ! empty( $intents ) ) {
					// Register with explicit intents from attribute or method.
					$this->handler_registry->register( $intents, $handler );
				} else {
					// Handler has no explicit intent registration - need fallback.
					$this->needs_fallback_lookup = true;
				}
			}
		}
	}

	/**
	 * Trigger a deprecation warning for legacy handler discovery methods.
	 *
	 * @param Handler $handler Handler instance.
	 * @param string  $method  The deprecated method name.
	 * @return void
	 */
	private function triggerLegacyMethodWarning( Handler $handler, string $method ): void {
		// Only trigger warnings in development mode.
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		// Avoid duplicate warnings by tracking which deprecations have been triggered.
		static $triggered = array();
		$handler_class = get_class( $handler );
		$key           = $handler_class . '::' . $method;
		if ( isset( $triggered[ $key ] ) ) {
			return;
		}
		$triggered[ $key ] = true;

		$message = sprintf(
			'AgentWP Deprecation: Handler %s uses deprecated method %s() for intent discovery. ' .
			'Please migrate to the #[HandlesIntent] attribute. This method will be removed in a future release. ' .
			'See docs/adr/0002-intent-handler-registration.md for migration instructions.',
			$handler_class,
			$method
		);

		if ( function_exists( '_doing_it_wrong' ) ) {
			_doing_it_wrong( $handler_class . '::' . $method, esc_html( $message ), '2.0.0' );
		} elseif ( function_exists( 'trigger_error' ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
			trigger_error( esc_html( $message ), E_USER_DEPRECATED );
		}
	}

	/**
	 * Get intents supported by a handler.
	 *
	 * @param Handler $handler Handler instance.
	 * @return string[] Array of intent identifiers.
	 */
	private function get_handler_intents( Handler $handler ): array {
		try {
			// Try to get intents from HandlesIntent attribute.
			$reflection = new \ReflectionClass( $handler );

			$attributes = $reflection->getAttributes( HandlesIntent::class );
			if ( ! empty( $attributes ) ) {
				$attribute = $attributes[0]->newInstance();
				return $attribute->getIntents();
			}
		} catch ( \ReflectionException ) {
			// Reflection failed - fall through to other detection methods.
		}

		// Fallback 1: Try to get intents from getSupportedIntents() method.
		// @deprecated 2.0.0 getSupportedIntents() is deprecated. Use #[HandlesIntent] attribute instead.
		if ( method_exists( $handler, 'getSupportedIntents' ) ) {
			$this->triggerLegacyMethodWarning( $handler, 'getSupportedIntents' );
			return $handler->getSupportedIntents();
		}

		// Fallback 2: Try to get intent from BaseHandler using public getter.
		// @deprecated 2.0.0 getIntent() is deprecated. Use #[HandlesIntent] attribute instead.
		// This maintains backward compatibility with existing handlers.
		if ( method_exists( $handler, 'getIntent' ) ) {
			$this->triggerLegacyMethodWarning( $handler, 'getIntent' );
			return array( $handler->getIntent() );
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
	 * @param string $intent Intent identifier.
	 * @return Handler
	 */
	private function resolve_handler( $intent ) {
		// Try O(1) lookup using HandlerRegistry first.
		$handler = $this->handler_registry->get( $intent );

		if ( null !== $handler ) {
			return $handler;
		}

		// Fallback: O(n) linear search for backward compatibility.
		// Only runs if we have handlers that weren't registered with explicit intents.
		if ( $this->needs_fallback_lookup ) {
			foreach ( $this->handlers as $handler ) {
				if ( $handler instanceof Handler && $handler->canHandle( $intent ) ) {
					return $handler;
				}
			}
		}

		return $this->fallback_handler;
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
	 * @return void
	 */
	private function register_default_functions() {
		$mapping = array(
			Intent::ORDER_SEARCH    => array( 'search_orders', 'select_orders' ),
			Intent::ORDER_REFUND    => array( 'prepare_refund', 'confirm_refund' ),
			Intent::ORDER_STATUS    => array( 'prepare_status_update', 'prepare_bulk_status_update', 'bulk_update' ),
			Intent::PRODUCT_STOCK   => array( 'prepare_stock_update', 'search_product' ),
			Intent::EMAIL_DRAFT     => array( 'draft_email' ),
			Intent::ANALYTICS_QUERY => array( 'get_sales_report' ),
			Intent::CUSTOMER_LOOKUP => array( 'get_customer_profile' ),
		);
		if ( function_exists( 'apply_filters' ) ) {
			$mapping = apply_filters( 'agentwp_default_function_mapping', $mapping, $this );
		}

		foreach ( $mapping as $intent => $functions ) {
			$handler = $this->resolve_handler( $intent );
			if ( ! $handler || ! $handler->canHandle( $intent ) ) {
				continue;
			}

			foreach ( $functions as $function_name ) {
				$this->function_registry->register( $function_name, $handler );
			}
		}
	}
}
