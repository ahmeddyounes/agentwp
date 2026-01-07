<?php
/**
 * Function schema for product search.
 *
 * @package AgentWP
 */

namespace AgentWP\AI\Functions;

class SearchProduct extends AbstractFunction {
	/**
	 * Get the function name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'search_product';
	}

	/**
	 * Get the function description.
	 *
	 * @return string
	 */
	public function get_description() {
		return 'Search for products by name, SKU, or ID.';
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
			'required'             => array( 'query' ),
			'properties'           => array(
				'query' => array(
					'type'        => 'string',
					'description' => 'Product name, ID, or general query.',
				),
				'sku'   => array(
					'type'        => 'string',
					'description' => 'Exact product SKU.',
				),
			),
		);
	}
}
