<?php
/**
 * Handle order status intents.
 *
 * @package AgentWP
 */

namespace AgentWP\Intent\Handlers;

use AgentWP\AI\Response;
use AgentWP\Intent\Intent;

class OrderStatusHandler extends BaseHandler {
	public function __construct() {
		parent::__construct( Intent::ORDER_STATUS );
	}

	/**
	 * @param array $context Context data.
	 * @return Response
	 */
	public function handle( array $context ): Response {
		$message = 'I can check or update order status. Tell me the order ID or customer.';
		return $this->build_response( $context, $message );
	}
}
