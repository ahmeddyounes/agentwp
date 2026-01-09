<?php
/**
 * Response parser for OpenAI API.
 *
 * @package AgentWP\AI\Client
 */

namespace AgentWP\AI\Client;

/**
 * Parses non-streaming responses from OpenAI API.
 */
final class ResponseParser {

	/**
	 * Parse a response body.
	 *
	 * @param string $body Response body JSON.
	 * @return ParsedResponse
	 */
	public function parse( string $body ): ParsedResponse {
		// Limit JSON depth to prevent DoS attacks via deeply nested structures.
		$payload = json_decode( $body, true, 64 );

		// Check for JSON parsing errors.
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return ParsedResponse::error( 'Invalid JSON response from OpenAI: ' . json_last_error_msg() );
		}

		if ( ! is_array( $payload ) ) {
			return ParsedResponse::error( 'Invalid response from OpenAI.' );
		}

		$message    = $this->extractMessage( $payload );
		$content    = $this->extractContent( $message );
		$toolCalls  = $this->extractToolCalls( $message );
		$usage      = $this->extractUsage( $payload );
		$model      = $this->extractModel( $payload );

		return ParsedResponse::success(
			$content,
			$toolCalls,
			$usage,
			$payload,
			$model
		);
	}

	/**
	 * Extract the message from the response.
	 *
	 * @param array $payload Response payload.
	 * @return array Message or empty array.
	 */
	private function extractMessage( array $payload ): array {
		if ( ! isset( $payload['choices'][0]['message'] ) ) {
			return array();
		}

		$message = $payload['choices'][0]['message'];

		return is_array( $message ) ? $message : array();
	}

	/**
	 * Extract content from the message.
	 *
	 * @param array $message The message.
	 * @return string Content string.
	 */
	private function extractContent( array $message ): string {
		if ( ! isset( $message['content'] ) ) {
			return '';
		}

		return is_string( $message['content'] ) ? $message['content'] : '';
	}

	/**
	 * Extract tool calls from the message.
	 *
	 * @param array $message The message.
	 * @return array Tool calls.
	 */
	private function extractToolCalls( array $message ): array {
		// Modern tool_calls format.
		if ( isset( $message['tool_calls'] ) && is_array( $message['tool_calls'] ) ) {
			return $message['tool_calls'];
		}

		// Legacy function_call format.
		if ( isset( $message['function_call'] ) && is_array( $message['function_call'] ) ) {
			return array(
				array(
					'id'       => 'legacy',
					'type'     => 'function',
					'function' => $message['function_call'],
				),
			);
		}

		return array();
	}

	/**
	 * Extract usage information.
	 *
	 * @param array $payload Response payload.
	 * @return array Usage data or empty array.
	 */
	private function extractUsage( array $payload ): array {
		if ( ! isset( $payload['usage'] ) || ! is_array( $payload['usage'] ) ) {
			return array();
		}

		return $payload['usage'];
	}

	/**
	 * Extract model name.
	 *
	 * @param array $payload Response payload.
	 * @return string Model name.
	 */
	private function extractModel( array $payload ): string {
		if ( ! isset( $payload['model'] ) ) {
			return '';
		}

		return is_string( $payload['model'] ) ? $payload['model'] : '';
	}
}
