<?php
/**
 * Stream parser for OpenAI SSE responses.
 *
 * @package AgentWP\AI\Client
 */

namespace AgentWP\AI\Client;

/**
 * Parses Server-Sent Events (SSE) streaming responses from OpenAI.
 */
final class StreamParser {

	/**
	 * Stream callback.
	 *
	 * @var callable|null
	 */
	private $onStream;

	/**
	 * Create a new StreamParser.
	 *
	 * @param callable|null $onStream Optional callback for each chunk.
	 */
	public function __construct( ?callable $onStream = null ) {
		$this->onStream = $onStream;
	}

	/**
	 * Parse a streaming response body.
	 *
	 * @param string $body The full SSE response body.
	 * @return ParsedResponse
	 */
	public function parse( string $body ): ParsedResponse {
		$lines      = $this->splitLines( $body );
		$content    = '';
		$toolCalls  = array();
		$usage      = array();
		$raw        = array();
		$model      = '';

		foreach ( $lines as $line ) {
			$chunk = $this->parseLine( $line );

			if ( null === $chunk ) {
				continue;
			}

			// Limit raw chunks to prevent unbounded memory growth.
			if ( count( $raw ) < 100 ) {
				$raw[] = $chunk;
			}
			$this->notifyListener( $chunk );

			$model    = $this->updateModel( $model, $chunk );
			$usage    = $this->updateUsage( $usage, $chunk );
			$content .= $this->extractDeltaContent( $chunk );

			$toolCalls = $this->mergeToolCallDeltas(
				$toolCalls,
				$this->extractToolCallDeltas( $chunk )
			);
		}

		return ParsedResponse::success(
			$content,
			array_values( $toolCalls ),
			$usage,
			$raw,
			$model
		);
	}

	/**
	 * Split the body into lines.
	 *
	 * @param string $body The response body.
	 * @return array Lines.
	 */
	private function splitLines( string $body ): array {
		$lines = preg_split( "/\r\n|\n|\r/", $body );

		return is_array( $lines ) ? $lines : array();
	}

	/**
	 * Parse a single SSE line.
	 *
	 * @param string $line The line.
	 * @return array|null Parsed chunk or null.
	 */
	private function parseLine( string $line ): ?array {
		$line = trim( $line );

		// Skip empty lines.
		if ( '' === $line ) {
			return null;
		}

		// Skip non-data lines.
		if ( 0 !== strpos( $line, 'data:' ) ) {
			return null;
		}

		$payload = trim( substr( $line, 5 ) );

		// Skip done markers (handles both "data: [DONE]" and "data:[DONE]").
		if ( '[DONE]' === $payload ) {
			return null;
		}

		// Limit JSON depth to prevent DoS attacks via deeply nested structures.
		$chunk = json_decode( $payload, true, 64 );

		// Check for JSON parsing errors.
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return null;
		}

		return is_array( $chunk ) ? $chunk : null;
	}

	/**
	 * Notify the stream listener.
	 *
	 * @param array $chunk The chunk.
	 * @return void
	 */
	private function notifyListener( array $chunk ): void {
		if ( is_callable( $this->onStream ) ) {
			call_user_func( $this->onStream, $chunk );
		}
	}

	/**
	 * Update the model name.
	 *
	 * @param string $current Current model.
	 * @param array  $chunk   The chunk.
	 * @return string Updated model.
	 */
	private function updateModel( string $current, array $chunk ): string {
		if ( isset( $chunk['model'] ) && is_string( $chunk['model'] ) ) {
			return $chunk['model'];
		}

		return $current;
	}

	/**
	 * Update usage information.
	 *
	 * @param array $current Current usage.
	 * @param array $chunk   The chunk.
	 * @return array Updated usage.
	 */
	private function updateUsage( array $current, array $chunk ): array {
		if ( isset( $chunk['usage'] ) && is_array( $chunk['usage'] ) ) {
			return $chunk['usage'];
		}

		return $current;
	}

	/**
	 * Extract content from a delta.
	 *
	 * @param array $chunk The chunk.
	 * @return string Content string.
	 */
	private function extractDeltaContent( array $chunk ): string {
		$delta = $this->extractDelta( $chunk );

		if ( isset( $delta['content'] ) && is_string( $delta['content'] ) ) {
			return $delta['content'];
		}

		return '';
	}

	/**
	 * Extract tool call deltas.
	 *
	 * @param array $chunk The chunk.
	 * @return array Tool call deltas.
	 */
	private function extractToolCallDeltas( array $chunk ): array {
		$delta = $this->extractDelta( $chunk );

		// Modern tool_calls format.
		if ( isset( $delta['tool_calls'] ) && is_array( $delta['tool_calls'] ) ) {
			return $delta['tool_calls'];
		}

		// Legacy function_call format.
		if ( isset( $delta['function_call'] ) && is_array( $delta['function_call'] ) ) {
			return array(
				array(
					'index'    => 0,
					'type'     => 'function',
					'function' => $delta['function_call'],
				),
			);
		}

		return array();
	}

	/**
	 * Extract the delta from a chunk.
	 *
	 * @param array $chunk The chunk.
	 * @return array The delta.
	 */
	private function extractDelta( array $chunk ): array {
		if ( ! isset( $chunk['choices'][0]['delta'] ) ) {
			return array();
		}

		$delta = $chunk['choices'][0]['delta'];

		return is_array( $delta ) ? $delta : array();
	}

	/**
	 * Merge tool call deltas into accumulated tool calls.
	 *
	 * @param array $toolCalls Accumulated tool calls.
	 * @param array $deltas    New deltas.
	 * @return array Updated tool calls.
	 */
	private function mergeToolCallDeltas( array $toolCalls, array $deltas ): array {
		foreach ( $deltas as $delta ) {
			$index = isset( $delta['index'] ) ? (int) $delta['index'] : 0;

			if ( ! isset( $toolCalls[ $index ] ) ) {
				$toolCalls[ $index ] = $this->initializeToolCall( $delta );
				continue;
			}

			$toolCalls[ $index ] = $this->mergeToolCall( $toolCalls[ $index ], $delta );
		}

		return $toolCalls;
	}

	/**
	 * Initialize a new tool call from a delta.
	 *
	 * @param array $delta The delta.
	 * @return array Tool call structure.
	 */
	private function initializeToolCall( array $delta ): array {
		$function = isset( $delta['function'] ) && is_array( $delta['function'] )
			? $delta['function']
			: array();

		return array(
			'id'       => $delta['id'] ?? '',
			'type'     => $delta['type'] ?? 'function',
			'function' => array(
				'name'      => $function['name'] ?? '',
				'arguments' => $function['arguments'] ?? '',
			),
		);
	}

	/**
	 * Merge a delta into an existing tool call.
	 *
	 * @param array $toolCall Existing tool call.
	 * @param array $delta    The delta.
	 * @return array Updated tool call.
	 */
	private function mergeToolCall( array $toolCall, array $delta ): array {
		if ( isset( $delta['id'] ) ) {
			$toolCall['id'] = $delta['id'];
		}

		if ( isset( $delta['type'] ) ) {
			$toolCall['type'] = $delta['type'];
		}

		if ( isset( $delta['function'] ) && is_array( $delta['function'] ) ) {
			if ( isset( $delta['function']['name'] ) ) {
				$toolCall['function']['name'] = $delta['function']['name'];
			}

			if ( isset( $delta['function']['arguments'] ) ) {
				$toolCall['function']['arguments'] .= $delta['function']['arguments'];
			}
		}

		return $toolCall;
	}
}
