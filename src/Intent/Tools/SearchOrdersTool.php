<?php
/**
 * Executable tool for searching orders.
 *
 * @package AgentWP\Intent\Tools
 */

namespace AgentWP\Intent\Tools;

use AgentWP\Contracts\ExecutableToolInterface;
use AgentWP\Contracts\OrderSearchServiceInterface;

/**
 * Executes order search operations.
 *
 * Calls the OrderSearchService and returns a stable result payload
 * suitable for AI consumption.
 */
class SearchOrdersTool implements ExecutableToolInterface {

	/**
	 * @var OrderSearchServiceInterface
	 */
	private OrderSearchServiceInterface $service;

	/**
	 * Initialize the tool.
	 *
	 * @param OrderSearchServiceInterface $service Order search service.
	 */
	public function __construct( OrderSearchServiceInterface $service ) {
		$this->service = $service;
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'search_orders';
	}

	/**
	 * Execute the order search.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Search results.
	 */
	public function execute( array $arguments ): array {
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
}
