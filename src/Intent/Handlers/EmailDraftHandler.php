<?php
/**
 * Handle email draft intents.
 *
 * @package AgentWP
 */

namespace AgentWP\Intent\Handlers;

use AgentWP\AI\Response;
use AgentWP\Intent\Intent;

class EmailDraftHandler extends BaseHandler {
	public function __construct() {
		parent::__construct( Intent::EMAIL_DRAFT );
	}

	/**
	 * @param array $context Context data.
	 * @return Response
	 */
	public function handle( array $context ): Response {
		$message = 'I can draft a customer email. Share the order ID and desired tone.';
		return $this->build_response( $context, $message );
	}
}
