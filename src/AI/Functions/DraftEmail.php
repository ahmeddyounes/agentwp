<?php
/**
 * Function schema for drafting emails.
 *
 * @package AgentWP
 */

namespace AgentWP\AI\Functions;

class DraftEmail extends AbstractFunction {
	/**
	 * Get the function name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'draft_email';
	}

	/**
	 * Get the function description.
	 *
	 * @return string
	 */
	public function get_description() {
		return 'Draft a customer email for a given order and intent.';
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
			'required'             => array( 'order_id', 'intent', 'tone' ),
			'properties'           => array(
				'order_id'            => array(
					'type'        => 'integer',
					'description' => 'Order ID for context.',
				),
				'intent'              => array(
					'type'        => 'string',
					'enum'        => array( 'shipping_update', 'refund_confirmation', 'order_issue', 'general_inquiry', 'review_request' ),
					'description' => 'The reason for the email.',
				),
				'tone'                => array(
					'type'        => 'string',
					'enum'        => array( 'professional', 'friendly', 'apologetic' ),
					'description' => 'Desired tone for the email.',
				),
				'custom_instructions' => array(
					'type'        => 'string',
					'description' => 'Optional instructions for the draft.',
				),
			),
		);
	}
}
