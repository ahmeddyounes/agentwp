<?php
/**
 * Handle order search intents.
 *
 * @package AgentWP\Intent\Handlers
 */

namespace AgentWP\Intent\Handlers;

use AgentWP\Contracts\AIClientFactoryInterface;
use AgentWP\Contracts\OrderSearchServiceInterface;
use AgentWP\Contracts\ToolDispatcherInterface;
use AgentWP\Contracts\ToolRegistryInterface;
use AgentWP\Intent\Attributes\HandlesIntent;
use AgentWP\Intent\Intent;

/**
 * Handles order search intents using the agentic loop.
 */
#[HandlesIntent( Intent::ORDER_SEARCH )]
class OrderSearchHandler extends AbstractAgenticHandler {

	/**
	 * @var OrderSearchServiceInterface
	 */
	private OrderSearchServiceInterface $service;

	/**
	 * Initialize order search intent handler.
	 *
	 * @param OrderSearchServiceInterface  $service        Search service.
	 * @param AIClientFactoryInterface     $clientFactory  AI client factory.
	 * @param ToolRegistryInterface        $toolRegistry   Tool registry.
	 * @param ToolDispatcherInterface|null $toolDispatcher Tool dispatcher (optional).
	 */
	public function __construct(
		OrderSearchServiceInterface $service,
		AIClientFactoryInterface $clientFactory,
		ToolRegistryInterface $toolRegistry,
		?ToolDispatcherInterface $toolDispatcher = null
	) {
		$this->service = $service;
		parent::__construct( Intent::ORDER_SEARCH, $clientFactory, $toolRegistry, $toolDispatcher );
	}

	/**
	 * Register tool executors with the dispatcher.
	 *
	 * @param ToolDispatcherInterface $dispatcher The tool dispatcher.
	 * @return void
	 */
	protected function registerToolExecutors( ToolDispatcherInterface $dispatcher ): void {
		$dispatcher->register(
			'search_orders',
			function ( array $args ): array {
				// Map arguments to service format with explicit type casting.
				$search_args = array(
					'query'    => isset( $args['query'] ) ? (string) $args['query'] : '',
					'status'   => isset( $args['status'] ) ? (string) $args['status'] : '',
					'limit'    => isset( $args['limit'] ) ? (int) $args['limit'] : 10,
					'email'    => isset( $args['email'] ) ? (string) $args['email'] : '',
					'order_id' => isset( $args['order_id'] ) ? (int) $args['order_id'] : 0,
				);

				if ( isset( $args['date_range'] ) && is_array( $args['date_range'] ) ) {
					$search_args['date_range'] = $args['date_range'];
				}

				$result = $this->service->handle( $search_args );

				// Return structured result for AI consumption.
				if ( $result->isFailure() ) {
					return array(
						'success' => false,
						'error'   => $result->message,
						'code'    => $result->code,
					);
				}

				return array(
					'success' => true,
					'orders'  => $result->get( 'orders', array() ),
					'count'   => $result->get( 'count', 0 ),
					'cached'  => $result->get( 'cached', false ),
					'query'   => $result->get( 'query', array() ),
				);
			}
		);
	}

	/**
	 * Get the system prompt for order search.
	 *
	 * @return string
	 */
	protected function getSystemPrompt(): string {
		return 'You are an order search assistant. Find orders based on user criteria (date, status, customer).';
	}

	/**
	 * Get the tool names for order search.
	 *
	 * @return array<string>
	 */
	protected function getToolNames(): array {
		return array( 'search_orders' );
	}

	/**
	 * Get the default input for order search.
	 *
	 * @return string
	 */
	protected function getDefaultInput(): string {
		return 'Find orders';
	}
}
