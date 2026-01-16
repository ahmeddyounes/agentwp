<?php
/**
 * Base handler for intent responses.
 *
 * @package AgentWP
 */

namespace AgentWP\Intent\Handlers;

use AgentWP\AI\Response;
use AgentWP\Intent\Handler;
use AgentWP\Intent\Intent;

abstract class BaseHandler implements Handler {
	protected string $intent;

	/**
	 * @param string $intent Intent identifier.
	 */
	public function __construct( string $intent ) {
		$this->intent = Intent::normalize( $intent );
	}

	/**
	 * @param string $intent Intent identifier.
	 * @return bool
	 */
	public function canHandle( string $intent ): bool {
		return Intent::normalize( $intent ) === $this->intent;
	}

	/**
	 * @param array  $context Context data.
	 * @param string $message Response message.
	 * @param array  $data Additional payload data.
	 * @return Response
	 */
	protected function build_response( array $context, $message, array $data = array() ) {
		$payload = array_merge(
			array(
				'intent'  => $this->intent,
				'message' => $message,
			),
			$data
		);

		if ( isset( $context['store'] ) ) {
			$payload['store'] = $context['store'];
		}

		if ( isset( $context['user'] ) ) {
			$payload['user'] = $context['user'];
		}

		if ( isset( $context['recent_orders'] ) ) {
			$payload['recent_orders'] = $context['recent_orders'];
		}

		if ( isset( $context['function_suggestions'] ) ) {
			$payload['function_suggestions'] = $context['function_suggestions'];
		}

		return Response::success( $payload );
	}
}
