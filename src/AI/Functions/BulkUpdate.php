<?php
/**
 * Function schema for executing bulk order actions.
 *
 * @package AgentWP
 */

namespace AgentWP\AI\Functions;

class BulkUpdate extends AbstractFunction {
	/**
	 * Get the function name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'bulk_update';
	}

	/**
	 * Get the function description.
	 *
	 * @return string
	 */
	public function get_description() {
		return 'Apply a bulk action to a list of order IDs.';
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
			'required'             => array( 'order_ids', 'action', 'params' ),
			'properties'           => array(
				'order_ids' => array(
					'type'        => 'array',
					'description' => 'Order IDs to update.',
					'items'       => array(
						'type' => 'integer',
					),
				),
				'action'    => array(
					'type'        => 'string',
					'enum'        => array( 'update_status', 'add_tag', 'add_note', 'export_csv' ),
					'description' => 'Bulk action to perform.',
				),
				'params'    => array(
					'type'        => 'object',
					'description' => 'Action-specific parameters.',
				),
			),
		);
	}
}
