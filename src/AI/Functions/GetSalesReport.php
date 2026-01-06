<?php
/**
 * Function schema for sales reports.
 *
 * @package AgentWP
 */

namespace AgentWP\AI\Functions;

class GetSalesReport extends AbstractFunction {
	public function get_name() {
		return 'get_sales_report';
	}

	public function get_description() {
		return 'Fetch a sales report for a specified period.';
	}

	public function get_parameters() {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'period' ),
			'properties'           => array(
				'period'           => array(
					'type'        => 'string',
					'enum'        => array( 'today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'custom' ),
					'description' => 'Reporting window.',
				),
				'start_date'       => array(
					'type'        => 'string',
					'description' => 'Start date for custom ranges (YYYY-MM-DD).',
				),
				'end_date'         => array(
					'type'        => 'string',
					'description' => 'End date for custom ranges (YYYY-MM-DD).',
				),
				'compare_previous' => array(
					'type'        => 'boolean',
					'description' => 'Whether to compare against the previous period.',
				),
			),
		);
	}
}
