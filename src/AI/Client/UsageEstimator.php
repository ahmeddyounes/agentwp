<?php
/**
 * Usage estimator for OpenAI API.
 *
 * @package AgentWP\AI\Client
 */

namespace AgentWP\AI\Client;

use AgentWP\AI\TokenCounter;

/**
 * Estimates token usage when not provided by the API.
 */
final class UsageEstimator {

	/**
	 * Token counter.
	 *
	 * @var TokenCounter
	 */
	private TokenCounter $tokenCounter;

	/**
	 * Create a new UsageEstimator.
	 *
	 * @param TokenCounter $tokenCounter Token counter.
	 */
	public function __construct( TokenCounter $tokenCounter ) {
		$this->tokenCounter = $tokenCounter;
	}

	/**
	 * Estimate usage from parsed response.
	 *
	 * @param int           $inputTokens Estimated input tokens.
	 * @param ParsedResponse $parsed      Parsed response.
	 * @param string        $model       Model name.
	 * @return array Usage data.
	 */
	public function estimate( int $inputTokens, ParsedResponse $parsed, string $model ): array {
		$outputTokens = $this->estimateOutputTokens( $parsed, $model );

		return array(
			'prompt_tokens'     => $inputTokens,
			'completion_tokens' => $outputTokens,
			'total_tokens'      => $inputTokens + $outputTokens,
		);
	}

	/**
	 * Count input tokens for a request.
	 *
	 * @param array  $messages Chat messages.
	 * @param array  $tools    Tool definitions.
	 * @param string $model    Model name.
	 * @return int Token count.
	 */
	public function countInputTokens( array $messages, array $tools, string $model ): int {
		return $this->tokenCounter->count_request_tokens( $messages, $tools, $model );
	}

	/**
	 * Estimate output tokens from parsed response.
	 *
	 * @param ParsedResponse $parsed Parsed response.
	 * @param string        $model  Model name.
	 * @return int Estimated output tokens.
	 */
	private function estimateOutputTokens( ParsedResponse $parsed, string $model ): int {
		$tokens = 0;

		if ( '' !== $parsed->content ) {
			$tokens += $this->tokenCounter->count_text_tokens( $parsed->content, $model );
		}

		if ( ! empty( $parsed->toolCalls ) ) {
			$payload = wp_json_encode( $parsed->toolCalls );

			if ( false !== $payload ) {
				$tokens += $this->tokenCounter->count_text_tokens( $payload, $model );
			}
		}

		return $tokens;
	}

	/**
	 * Get the token counter.
	 *
	 * @return TokenCounter
	 */
	public function getTokenCounter(): TokenCounter {
		return $this->tokenCounter;
	}
}
