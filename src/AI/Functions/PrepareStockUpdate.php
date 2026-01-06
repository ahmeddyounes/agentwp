<?php
/**
 * Function schema for stock updates.
 *
 * @package AgentWP
 */

namespace AgentWP\AI\Functions;

class PrepareStockUpdate extends AbstractFunction {
	public function get_name() {
		return 'prepare_stock_update';
	}

	public function get_description() {
		return 'Prepare a draft stock update for a product.';
	}

	public function get_parameters() {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'product_id', 'quantity', 'operation' ),
			'properties'           => array(
				'product_id' => array(
					'type'        => 'integer',
					'description' => 'Product ID to update.',
				),
				'quantity'   => array(
					'type'        => 'integer',
					'description' => 'Quantity to set or adjust.',
				),
				'operation'  => array(
					'type'        => 'string',
					'enum'        => array( 'set', 'increase', 'decrease' ),
					'description' => 'How the quantity should be applied.',
				),
			),
		);
	}
}
