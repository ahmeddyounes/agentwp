<?php
/**
 * Function schema for order search.
 *
 * @package AgentWP
 */

namespace AgentWP\AI\Functions;

class SearchOrders extends AbstractFunction {
	/**
	 * Get the function name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'search_orders';
	}

	/**
	 * Get the function description.
	 *
	 * @return string
	 */
	public function get_description() {
		return 'Search for orders by query, ID, customer email, status, or date range.';
	}

	/**
	 * Get the JSON schema for function parameters.
	 *
	 * @return array
	 */
	public function get_parameters() {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'query'      => array(
					'type'        => 'string',
					'description' => 'Natural language query such as "last order".',
				),
				'order_id'   => array(
					'type'        => 'integer',
					'description' => 'Exact order ID to look up.',
				),
				'email'      => array(
					'type'        => 'string',
					'description' => 'Customer billing or shipping email address.',
				),
				'status'     => array(
					'type'        => 'string',
					'description' => 'WooCommerce order status slug.',
				),
				'date_range' => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'start' => array(
							'type'        => 'string',
							'description' => 'Start date (YYYY-MM-DD).',
						),
						'end'   => array(
							'type'        => 'string',
							'description' => 'End date (YYYY-MM-DD).',
						),
					),
					'required'             => array( 'start', 'end' ),
				),
				'limit'      => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'Maximum number of orders to return.',
				),
			),
		);
	}
}
