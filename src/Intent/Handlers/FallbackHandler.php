<?php
/**
 * Handle unknown intents.
 *
 * @package AgentWP
 */

namespace AgentWP\Intent\Handlers;

use AgentWP\AI\Response;
use AgentWP\Intent\Attributes\HandlesIntent;
use AgentWP\Intent\Intent;

#[HandlesIntent( Intent::UNKNOWN )]
class FallbackHandler extends BaseHandler {
	/**
	 * Initialize fallback intent handler.
	 *
	 * @return void
	 */
	public function __construct() {
		parent::__construct( Intent::UNKNOWN );
	}

	/**
	 * @param array $context Context data.
	 * @return Response
	 */
	public function handle( array $context ): Response {
		$message = 'I did not recognize that request. Try one of these suggestions.';
		return $this->build_response(
			$context,
			$message,
			array(
				'suggestions' => Intent::suggestions(),
			)
		);
	}
}
