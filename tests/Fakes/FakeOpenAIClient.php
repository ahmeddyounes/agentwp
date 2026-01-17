<?php
/**
 * Fake OpenAI client for unit tests.
 */

namespace AgentWP\Tests\Fakes;

use AgentWP\AI\Response;
use AgentWP\Contracts\OpenAIClientInterface;

final class FakeOpenAIClient implements OpenAIClientInterface {
	/**
	 * @var Response[]
	 */
	private array $responses;

	/**
	 * @param Response[] $responses
	 */
	public function __construct( array $responses = array() ) {
		$this->responses = array_values( $responses );
	}

	/**
	 * {@inheritDoc}
	 */
	public function chat( array $messages, array $functions ): Response {
		unset( $messages, $functions );

		if ( empty( $this->responses ) ) {
			return Response::error( 'FakeOpenAIClient: no responses queued.', 500 );
		}

		return array_shift( $this->responses );
	}

	/**
	 * {@inheritDoc}
	 */
	public function validateKey( string $key ): bool {
		unset( $key );
		return true;
	}
}
