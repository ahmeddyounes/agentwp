<?php
/**
 * Function schema for bulk order status updates.
 *
 * @package AgentWP
 */

namespace AgentWP\AI\Functions;

class PrepareBulkStatusUpdate extends AbstractFunction {
	public function get_name() {
		return 'prepare_bulk_status_update';
	}

	public function get_description() {
		return 'Prepare a draft bulk status update for multiple orders.';
	}

	public function get_parameters() {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'order_ids', 'new_status' ),
			'properties'           => array(
				'order_ids'  => array(
					'type'        => 'array',
					'description' => 'List of order IDs to update.',
					'items'       => array(
						'type' => 'integer',
					),
				),
				'new_status' => array(
					'type'        => 'string',
					'enum'        => array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' ),
					'description' => 'Target WooCommerce order status.',
				),
			),
		);
	}
}
