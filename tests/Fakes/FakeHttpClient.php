<?php
/**
 * Fake HTTP client for testing.
 *
 * @package AgentWP\Tests\Fakes
 */

namespace AgentWP\Tests\Fakes;

use AgentWP\Contracts\HttpClientInterface;
use AgentWP\DTO\HttpResponse;
use RuntimeException;

/**
 * Queue-based HTTP client for testing.
 */
final class FakeHttpClient implements HttpClientInterface {

	/**
	 * Queued responses.
	 *
	 * @var HttpResponse[]
	 */
	private array $responseQueue = array();

	/**
	 * Request log.
	 *
	 * @var array<array{method: string, url: string, options: array}>
	 */
	private array $requestLog = array();

	/**
	 * Queue a response to be returned.
	 *
	 * @param HttpResponse $response The response to queue.
	 * @return self
	 */
	public function queueResponse( HttpResponse $response ): self {
		$this->responseQueue[] = $response;
		return $this;
	}

	/**
	 * Queue multiple responses.
	 *
	 * @param HttpResponse[] $responses Responses to queue.
	 * @return self
	 */
	public function queueResponses( array $responses ): self {
		foreach ( $responses as $response ) {
			$this->queueResponse( $response );
		}
		return $this;
	}

	/**
	 * Queue a success response.
	 *
	 * @param string $body       Response body.
	 * @param int    $statusCode HTTP status code.
	 * @param array  $headers    Response headers.
	 * @return self
	 */
	public function queueSuccess( string $body, int $statusCode = 200, array $headers = array() ): self {
		return $this->queueResponse( HttpResponse::success( $body, $statusCode, $headers ) );
	}

	/**
	 * Queue an error response.
	 *
	 * @param string      $error     Error message.
	 * @param string|null $errorCode Error code.
	 * @param int         $status    HTTP status code.
	 * @return self
	 */
	public function queueError( string $error, ?string $errorCode = null, int $status = 0 ): self {
		return $this->queueResponse( HttpResponse::error( $error, $errorCode, $status ) );
	}

	/**
	 * Queue a JSON success response.
	 *
	 * @param array $data       Response data.
	 * @param int   $statusCode HTTP status code.
	 * @return self
	 * @throws RuntimeException If JSON encoding fails.
	 */
	public function queueJson( array $data, int $statusCode = 200 ): self {
		$json = json_encode( $data );

		if ( false === $json ) {
			throw new RuntimeException( 'Failed to encode JSON data in FakeHttpClient::queueJson()' );
		}

		return $this->queueSuccess(
			$json,
			$statusCode,
			array( 'content-type' => 'application/json' )
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function post( string $url, array $options = array() ): HttpResponse {
		$this->requestLog[] = array(
			'method'  => 'POST',
			'url'     => $url,
			'options' => $options,
		);

		return $this->dequeueResponse();
	}

	/**
	 * {@inheritDoc}
	 */
	public function get( string $url, array $options = array() ): HttpResponse {
		$this->requestLog[] = array(
			'method'  => 'GET',
			'url'     => $url,
			'options' => $options,
		);

		return $this->dequeueResponse();
	}

	/**
	 * Dequeue the next response.
	 *
	 * @return HttpResponse
	 * @throws RuntimeException If no responses are queued.
	 */
	private function dequeueResponse(): HttpResponse {
		if ( empty( $this->responseQueue ) ) {
			throw new RuntimeException( 'No responses queued in FakeHttpClient. Queue responses before making requests.' );
		}

		return array_shift( $this->responseQueue );
	}

	// Test helpers.

	/**
	 * Get the request log.
	 *
	 * @return array<array{method: string, url: string, options: array}>
	 */
	public function getRequestLog(): array {
		return $this->requestLog;
	}

	/**
	 * Get the last request made.
	 *
	 * @return array{method: string, url: string, options: array}|null
	 */
	public function getLastRequest(): ?array {
		if ( empty( $this->requestLog ) ) {
			return null;
		}

		return $this->requestLog[ count( $this->requestLog ) - 1 ];
	}

	/**
	 * Get request count.
	 *
	 * @return int
	 */
	public function getRequestCount(): int {
		return count( $this->requestLog );
	}

	/**
	 * Check if a URL was requested.
	 *
	 * @param string $url The URL to check.
	 * @return bool
	 */
	public function wasRequested( string $url ): bool {
		foreach ( $this->requestLog as $request ) {
			if ( $request['url'] === $url ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Get number of remaining queued responses.
	 *
	 * @return int
	 */
	public function getQueuedCount(): int {
		return count( $this->responseQueue );
	}

	/**
	 * Reset the client.
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->responseQueue = array();
		$this->requestLog    = array();
	}
}
