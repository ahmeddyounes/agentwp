<?php
/**
 * Function schema for order status updates.
 *
 * @package AgentWP
 */

namespace AgentWP\AI\Functions;

class PrepareStatusUpdate extends AbstractFunction {
	public function get_name() {
		return 'prepare_status_update';
	}

	public function get_description() {
		return 'Prepare a draft order status update without applying it.';
	}

	public function get_parameters() {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'order_id', 'new_status' ),
			'properties'           => array(
				'order_id'       => array(
					'type'        => 'integer',
					'description' => 'Order ID to update.',
				),
				'new_status'     => array(
					'type'        => 'string',
					'enum'        => array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' ),
					'description' => 'Target WooCommerce order status.',
				),
				'note'           => array(
					'type'        => 'string',
					'description' => 'Optional note to attach to the order.',
				),
				'notify_customer' => array(
					'type'        => 'boolean',
					'description' => 'Whether to notify the customer.',
				),
			),
		);
	}
}
