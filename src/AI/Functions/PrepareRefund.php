<?php
/**
 * Function schema for refund preparation.
 *
 * @package AgentWP
 */

namespace AgentWP\AI\Functions;

class PrepareRefund extends AbstractFunction {
	/**
	 * Get the function name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'prepare_refund';
	}

	/**
	 * Get the function description.
	 *
	 * @return string
	 */
	public function get_description() {
		return 'Prepare a draft refund for an order without executing it.';
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
			'required'             => array( 'order_id' ),
			'properties'           => array(
				'order_id'      => array(
					'type'        => 'integer',
					'description' => 'Order ID to refund.',
				),
				'amount'        => array(
					'type'        => 'number',
					'description' => 'Refund amount. Leave empty for full refund.',
				),
				'reason'        => array(
					'type'        => 'string',
					'description' => 'Refund reason to include in the audit log.',
				),
				'restock_items' => array(
					'type'        => 'boolean',
					'description' => 'Whether to restock refunded items.',
				),
			),
		);
	}
}
