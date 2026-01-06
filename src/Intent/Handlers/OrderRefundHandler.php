<?php
/**
 * Handle order refund intents.
 *
 * @package AgentWP
 */

namespace AgentWP\Intent\Handlers;

use AgentWP\AI\Response;
use AgentWP\Intent\Intent;

class OrderRefundHandler extends BaseHandler {
	public function __construct() {
		parent::__construct( Intent::ORDER_REFUND );
	}

	/**
	 * @param array $context Context data.
	 * @return Response
	 */
	public function handle( array $context ): Response {
		$message = 'I can prepare a refund draft. Provide the order ID and refund amount if needed.';
		return $this->build_response( $context, $message );
	}
}
