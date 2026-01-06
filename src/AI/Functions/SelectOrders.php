<?php
/**
 * Function schema for selecting orders for bulk actions.
 *
 * @package AgentWP
 */

namespace AgentWP\AI\Functions;

class SelectOrders extends AbstractFunction {
	public function get_name() {
		return 'select_orders';
	}

	public function get_description() {
		return 'Select orders matching a set of criteria for bulk actions.';
	}

	public function get_parameters() {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'criteria' ),
			'properties'           => array(
				'criteria' => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'status'         => array(
							'type'        => 'string',
							'description' => 'Order status filter.',
						),
						'date_range'     => array(
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
						'customer_email' => array(
							'type'        => 'string',
							'description' => 'Customer email filter.',
						),
						'total_min'      => array(
							'type'        => 'number',
							'description' => 'Minimum order total.',
						),
						'total_max'      => array(
							'type'        => 'number',
							'description' => 'Maximum order total.',
						),
						'country'        => array(
							'type'        => 'string',
							'description' => 'Billing or shipping country code.',
						),
					),
				),
			),
		);
	}
}
