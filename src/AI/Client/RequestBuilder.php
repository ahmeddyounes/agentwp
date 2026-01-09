<?php
/**
 * Request builder for OpenAI API.
 *
 * @package AgentWP\AI\Client
 */

namespace AgentWP\AI\Client;

/**
 * Builds request payloads for OpenAI chat completions.
 */
final class RequestBuilder {

	/**
	 * Tool normalizer.
	 *
	 * @var ToolNormalizer
	 */
	private ToolNormalizer $toolNormalizer;

	/**
	 * Create a new RequestBuilder.
	 *
	 * @param ToolNormalizer $toolNormalizer Tool normalizer.
	 */
	public function __construct( ToolNormalizer $toolNormalizer ) {
		$this->toolNormalizer = $toolNormalizer;
	}

	/**
	 * Build a chat completion request payload.
	 *
	 * @param string $model     Model name.
	 * @param array  $messages  Chat messages.
	 * @param array  $functions Tool definitions.
	 * @param bool   $stream    Whether to stream the response.
	 * @return array Request payload.
	 */
	public function buildChatPayload(
		string $model,
		array $messages,
		array $functions,
		bool $stream = false
	): array {
		$payload = array(
			'model'    => $model,
			'messages' => array_values( $messages ),
		);

		$tools = $this->toolNormalizer->normalize( $functions );

		if ( ! empty( $tools ) ) {
			$payload['tools']       = $tools;
			$payload['tool_choice'] = 'auto';
		}

		if ( $stream ) {
			$payload['stream']         = true;
			$payload['stream_options'] = array( 'include_usage' => true );
		}

		return $payload;
	}

	/**
	 * Build HTTP request arguments.
	 *
	 * @param string $apiKey  API key.
	 * @param array  $payload Request payload.
	 * @param int    $timeout Request timeout in seconds.
	 * @return array|null HTTP request arguments, or null on JSON encoding failure.
	 */
	public function buildHttpArgs( string $apiKey, array $payload, int $timeout = 60 ): ?array {
		$body = wp_json_encode( $payload );

		if ( false === $body ) {
			return null;
		}

		return array(
			'timeout'     => $timeout,
			'redirection' => 0,
			'headers'     => array(
				'Authorization' => 'Bearer ' . $apiKey,
				'Content-Type'  => 'application/json',
			),
			'body'        => $body,
		);
	}

	/**
	 * Get the tool normalizer.
	 *
	 * @return ToolNormalizer
	 */
	public function getToolNormalizer(): ToolNormalizer {
		return $this->toolNormalizer;
	}
}
