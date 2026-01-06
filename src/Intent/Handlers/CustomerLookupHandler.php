<?php
/**
 * Handle customer lookup intents.
 *
 * @package AgentWP
 */

namespace AgentWP\Intent\Handlers;

use AgentWP\AI\Response;
use AgentWP\Intent\Intent;

class CustomerLookupHandler extends BaseHandler {
	public function __construct() {
		parent::__construct( Intent::CUSTOMER_LOOKUP );
	}

	/**
	 * @param array $context Context data.
	 * @return Response
	 */
	public function handle( array $context ): Response {
		$message = 'I can look up customer profiles. Share an email or name.';
		return $this->build_response( $context, $message );
	}
}
