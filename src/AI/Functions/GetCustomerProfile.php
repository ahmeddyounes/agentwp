<?php
/**
 * Function schema for customer profile lookup.
 *
 * @package AgentWP
 */

namespace AgentWP\AI\Functions;

class GetCustomerProfile extends AbstractFunction {
	public function get_name() {
		return 'get_customer_profile';
	}

	public function get_description() {
		return 'Retrieve a customer profile by email or customer ID.';
	}

	public function get_parameters() {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'email'       => array(
					'type'        => 'string',
					'description' => 'Customer email address.',
				),
				'customer_id' => array(
					'type'        => 'integer',
					'description' => 'Registered customer ID.',
				),
			),
			'anyOf'               => array(
				array(
					'required' => array( 'email' ),
				),
				array(
					'required' => array( 'customer_id' ),
				),
			),
		);
	}
}
