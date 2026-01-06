<?php
/**
 * Handle analytics query intents.
 *
 * @package AgentWP
 */

namespace AgentWP\Intent\Handlers;

use AgentWP\AI\Response;
use AgentWP\Intent\Intent;

class AnalyticsQueryHandler extends BaseHandler {
	public function __construct() {
		parent::__construct( Intent::ANALYTICS_QUERY );
	}

	/**
	 * @param array $context Context data.
	 * @return Response
	 */
	public function handle( array $context ): Response {
		$message = 'I can pull analytics. Specify a timeframe or metric you need.';
		return $this->build_response( $context, $message );
	}
}
