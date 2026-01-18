<?php
/**
 * Executable tool for retrieving customer profiles.
 *
 * @package AgentWP\Intent\Tools
 */

namespace AgentWP\Intent\Tools;

use AgentWP\Contracts\CustomerServiceInterface;
use AgentWP\Contracts\ExecutableToolInterface;

/**
 * Retrieves customer profile data by email or customer ID.
 *
 * Returns comprehensive customer information including order history,
 * lifetime value, and health status.
 */
class GetCustomerProfileTool implements ExecutableToolInterface {

	/**
	 * @var CustomerServiceInterface
	 */
	private CustomerServiceInterface $service;

	/**
	 * Initialize the tool.
	 *
	 * @param CustomerServiceInterface $service Customer service.
	 */
	public function __construct( CustomerServiceInterface $service ) {
		$this->service = $service;
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'get_customer_profile';
	}

	/**
	 * Get customer profile data.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Customer profile data.
	 */
	public function execute( array $arguments ): array {
		$email       = isset( $arguments['email'] ) ? (string) $arguments['email'] : '';
		$customer_id = isset( $arguments['customer_id'] ) ? (int) $arguments['customer_id'] : 0;

		if ( '' === $email && 0 === $customer_id ) {
			return array(
				'success' => false,
				'error'   => 'Either email or customer_id is required.',
			);
		}

		$args = array();
		if ( '' !== $email ) {
			$args['email'] = $email;
		}
		if ( $customer_id > 0 ) {
			$args['customer_id'] = $customer_id;
		}

		return $this->service->handle( $args )->toLegacyArray();
	}
}
