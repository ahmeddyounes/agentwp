<?php
/**
 * Function schema for refund confirmation.
 *
 * @package AgentWP
 */

namespace AgentWP\AI\Functions;

class ConfirmRefund extends AbstractFunction {
	public function get_name() {
		return 'confirm_refund';
	}

	public function get_description() {
		return 'Confirm and execute a previously prepared refund draft.';
	}

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
