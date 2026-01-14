<?php
/**
 * Intent engine for routing requests.
 *
 * @package AgentWP
 */

namespace AgentWP\Intent;

use AgentWP\AI\Response;
use AgentWP\Contracts\MemoryStoreInterface;
use AgentWP\Intent\Handlers\AnalyticsQueryHandler;
use AgentWP\Intent\Handlers\CustomerLookupHandler;
use AgentWP\Intent\Handlers\EmailDraftHandler;
use AgentWP\Intent\Handlers\FallbackHandler;
use AgentWP\Intent\Handlers\OrderRefundHandler;
use AgentWP\Intent\Handlers\OrderSearchHandler;
use AgentWP\Intent\Handlers\OrderStatusHandler;
use AgentWP\Intent\Handlers\ProductStockHandler;

class Engine {
	/**
	 * @var IntentClassifier
	 */
	private $classifier;

	/**
	 * @var ContextBuilder
	 */
	private $context_builder;

	/**
	 * @var MemoryStoreInterface|MemoryStore
	 */
	private $memory;

	/**
	 * @var FunctionRegistry
	 */
	private $function_registry;

	/**
	 * @var Handler[]
	 */
	private $handlers = array();

	/**
	 * @var Handler
	 */
	private $fallback_handler;

		/**
		 * @param array                             $handlers          Optional handlers.
		 * @param FunctionRegistry|null             $function_registry Optional registry.
		 * @param ContextBuilder|null               $context_builder   Optional context builder.
		 * @param IntentClassifier|null             $classifier        Optional classifier.
		 * @param MemoryStoreInterface|MemoryStore|null $memory        Optional memory store.
		 */
	public function __construct(
		array $handlers = array(),
		?FunctionRegistry $function_registry = null,
		?ContextBuilder $context_builder = null,
		?IntentClassifier $classifier = null,
		MemoryStoreInterface|MemoryStore|null $memory = null
	) {
		$this->classifier      = $classifier ? $classifier : new IntentClassifier();
		$this->context_builder = $context_builder ? $context_builder : new ContextBuilder();
		$this->memory          = $memory ? $memory : new MemoryStore( 5 );
		$this->function_registry = $function_registry ? $function_registry : new FunctionRegistry();
		$this->fallback_handler  = new FallbackHandler();

		$resolved_handlers = ! empty( $handlers ) ? $handlers : $this->default_handlers();
		if ( function_exists( 'apply_filters' ) ) {
			$resolved_handlers = apply_filters( 'agentwp_intent_handlers', $resolved_handlers, $this );
		}
		$this->handlers = is_array( $resolved_handlers ) ? $resolved_handlers : array();
		$this->register_default_functions();
		if ( function_exists( 'do_action' ) ) {
			do_action( 'agentwp_register_intent_functions', $this->function_registry, $this );
		}
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
	 * @return array
	 */
	private function default_handlers() {
		return array(
			new OrderSearchHandler(),
			new OrderRefundHandler(),
			new OrderStatusHandler(),
			new ProductStockHandler(),
			new EmailDraftHandler(),
			new AnalyticsQueryHandler(),
			new CustomerLookupHandler(),
		);
	}

	/**
	 * @param string $intent Intent identifier.
	 * @return Handler
	 */
	private function resolve_handler( $intent ) {
		foreach ( $this->handlers as $handler ) {
			if ( $handler instanceof Handler && $handler->canHandle( $intent ) ) {
				return $handler;
			}
		}

		return $this->fallback_handler;
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
