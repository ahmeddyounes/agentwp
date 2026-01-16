<?php
/**
 * Handle order search intents.
 *
 * @package AgentWP\Intent\Handlers
 */

namespace AgentWP\Intent\Handlers;

use AgentWP\AI\Functions\SearchOrders;
use AgentWP\Contracts\AIClientFactoryInterface;
use AgentWP\Contracts\OrderSearchServiceInterface;
use AgentWP\Intent\Intent;

/**
 * Handles order search intents using the agentic loop.
 */
class OrderSearchHandler extends AbstractAgenticHandler {

	/**
	 * @var OrderSearchServiceInterface
	 */
	private OrderSearchServiceInterface $service;

	/**
	 * Initialize order search intent handler.
	 *
	 * @param OrderSearchServiceInterface $service       Search service.
	 * @param AIClientFactoryInterface    $clientFactory AI client factory.
	 */
	public function __construct(
		OrderSearchServiceInterface $service,
		AIClientFactoryInterface $clientFactory
	) {
		parent::__construct( Intent::ORDER_SEARCH, $clientFactory );
		$this->service = $service;
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
	 * Get the tools available for order search.
	 *
	 * @return array
	 */
	protected function getTools(): array {
		return array( new SearchOrders() );
	}

	/**
	 * Get the default input for order search.
	 *
	 * @return string
	 */
	protected function getDefaultInput(): string {
		return 'Find orders';
	}

	/**
	 * Execute a named tool with arguments.
	 *
	 * @param string $name      Tool name.
	 * @param array  $arguments Tool arguments.
	 * @return mixed Tool execution result.
	 */
	public function execute_tool( string $name, array $arguments ) {
		if ( 'search_orders' === $name ) {
			// Map arguments to service format with explicit type casting.
			$search_args = array(
				'query'    => isset( $arguments['query'] ) ? (string) $arguments['query'] : '',
				'status'   => isset( $arguments['status'] ) ? (string) $arguments['status'] : '',
				'limit'    => isset( $arguments['limit'] ) ? (int) $arguments['limit'] : 10,
				'email'    => isset( $arguments['email'] ) ? (string) $arguments['email'] : '',
				'order_id' => isset( $arguments['order_id'] ) ? (int) $arguments['order_id'] : 0,
			);

			if ( isset( $arguments['date_range'] ) && is_array( $arguments['date_range'] ) ) {
				$search_args['date_range'] = $arguments['date_range'];
			}

			return $this->service->handle( $search_args );
		}

		return array( 'error' => "Unknown tool: {$name}" );
	}
}
