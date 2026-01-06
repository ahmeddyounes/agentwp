<?php
/**
 * Handle order search intents.
 *
 * @package AgentWP
 */

namespace AgentWP\Intent\Handlers;

use AgentWP\AI\Response;
use AgentWP\Intent\Intent;

class OrderSearchHandler extends BaseHandler {
	public function __construct() {
		parent::__construct( Intent::ORDER_SEARCH );
	}

	/**
	 * @param array $context Context data.
	 * @return Response
	 */
	public function handle( array $context ): Response {
		$message = 'I can search orders. Share an order ID, customer email, or date range.';
		return $this->build_response( $context, $message );
	}
}
