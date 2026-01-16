<?php
/**
 * Function schema for order status confirmation.
 *
 * @package AgentWP
 */

namespace AgentWP\AI\Functions;

class ConfirmStatusUpdate extends AbstractFunction {
	/**
	 * Get the function name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'confirm_status_update';
	}

	/**
	 * Get the function description.
	 *
	 * @return string
	 */
	public function get_description() {
		return 'Confirm and execute a previously prepared order status update.';
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
			'required'             => array( 'draft_id' ),
			'properties'           => array(
				'draft_id' => array(
					'type'        => 'string',
					'description' => 'Draft status update identifier to confirm.',
				),
			),
		);
	}
}
