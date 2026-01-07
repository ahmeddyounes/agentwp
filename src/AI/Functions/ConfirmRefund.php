<?php
/**
 * Function schema for refund confirmation.
 *
 * @package AgentWP
 */

namespace AgentWP\AI\Functions;

class ConfirmRefund extends AbstractFunction {
	/**
	 * Get the function name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'confirm_refund';
	}

	/**
	 * Get the function description.
	 *
	 * @return string
	 */
	public function get_description() {
		return 'Confirm and execute a previously prepared refund draft.';
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
					'description' => 'Draft refund identifier to confirm.',
				),
			),
		);
	}
}
