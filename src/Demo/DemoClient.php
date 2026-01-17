<?php
/**
 * Demo AI client with stubbed responses.
 *
 * Used when demo mode is enabled but no demo API key is configured.
 * Provides deterministic, predictable responses without making real API calls.
 *
 * @package AgentWP\Demo
 */

namespace AgentWP\Demo;

use AgentWP\AI\Model;
use AgentWP\AI\Response;
use AgentWP\Contracts\OpenAIClientInterface;

/**
 * Demo client that returns stubbed responses.
 */
class DemoClient implements OpenAIClientInterface {

	/**
	 * Default model for demo responses.
	 */
	private const DEMO_MODEL = 'demo-stub-v1';

	/**
	 * Stubbed response content.
	 */
	private const DEMO_CONTENT = 'This is a demo response. In demo mode without an API key, responses are simulated for testing purposes. To use real AI capabilities, either configure a demo API key or disable demo mode and add your OpenAI API key.';

	/**
	 * Model to report in responses.
	 *
	 * @var string
	 */
	private string $model;

	/**
	 * Intent type for tracking.
	 *
	 * @var string
	 */
	private string $intent_type;

	/**
	 * Create a new DemoClient.
	 *
	 * @param string $model Model name.
	 * @param array  $options Configuration options.
	 */
	public function __construct( string $model = Model::GPT_4O_MINI, array $options = array() ) {
		$this->model       = $model;
		$this->intent_type = isset( $options['intent_type'] ) ? sanitize_text_field( $options['intent_type'] ) : '';
	}

	/**
	 * Send a chat completion request (stubbed).
	 *
	 * Returns a deterministic demo response without making any API calls.
	 *
	 * @param array $messages Chat messages.
	 * @param array $functions Tool definitions.
	 * @return Response
	 */
	public function chat( array $messages, array $functions ): Response {
		// Generate deterministic response based on input.
		$content = $this->generateDemoContent( $messages, $functions );

		// Simulate token counts for demo purposes.
		$input_tokens  = $this->estimateInputTokens( $messages, $functions );
		$output_tokens = $this->estimateOutputTokens( $content );

		$meta = array(
			'model'         => self::DEMO_MODEL,
			'input_tokens'  => $input_tokens,
			'output_tokens' => $output_tokens,
			'total_tokens'  => $input_tokens + $output_tokens,
			'usage'         => array(
				'prompt_tokens'     => $input_tokens,
				'completion_tokens' => $output_tokens,
				'total_tokens'      => $input_tokens + $output_tokens,
			),
			'usage_source'  => 'demo',
			'retries'       => 0,
			'stream'        => false,
			'demo_mode'     => true,
		);

		return Response::success(
			array(
				'content'    => $content,
				'tool_calls' => array(),
				'raw'        => $this->generateDemoRaw( $content ),
			),
			$meta
		);
	}

	/**
	 * Validate an API key (always returns true in demo mode).
	 *
	 * @param string $key API key (unused in demo mode).
	 * @return bool Always true.
	 */
	public function validateKey( string $key ): bool { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Interface requirement
		// In demo stub mode, any key is "valid" since we don't make real calls.
		return true;
	}

	/**
	 * Generate demo content based on input.
	 *
	 * @param array $messages Chat messages.
	 * @param array $functions Available functions (unused, kept for potential future use).
	 * @return string Demo response content.
	 */
	private function generateDemoContent( array $messages, array $functions ): string { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Kept for future function-based demo responses
		$last_message = $this->getLastUserMessage( $messages );

		if ( '' === $last_message ) {
			return self::DEMO_CONTENT;
		}

		// Provide context-aware demo responses.
		$responses = array(
			'help'       => 'I\'m running in demo mode. This is a simulated response to demonstrate the interface. Configure a demo API key for real AI responses.',
			'product'    => '[Demo] Here are some sample product details. In production with an API key, I would provide actual product analysis.',
			'order'      => '[Demo] Order information would appear here. Enable a real API key to see actual order data analysis.',
			'customer'   => '[Demo] Customer insights would be shown here. This is a demonstration response.',
			'analytics'  => '[Demo] Analytics data would be displayed here in production mode with an API key configured.',
			'default'    => self::DEMO_CONTENT,
		);

		// Simple keyword matching for demo purposes.
		$lower_message = strtolower( $last_message );
		foreach ( $responses as $keyword => $response ) {
			if ( 'default' !== $keyword && false !== strpos( $lower_message, $keyword ) ) {
				return $response;
			}
		}

		return $responses['default'];
	}

	/**
	 * Get the last user message from the messages array.
	 *
	 * @param array $messages Chat messages.
	 * @return string Last user message content.
	 */
	private function getLastUserMessage( array $messages ): string {
		$reversed = array_reverse( $messages );
		foreach ( $reversed as $message ) {
			if ( isset( $message['role'] ) && 'user' === $message['role'] && isset( $message['content'] ) ) {
				return is_string( $message['content'] ) ? $message['content'] : '';
			}
		}
		return '';
	}

	/**
	 * Estimate input tokens for demo purposes.
	 *
	 * @param array $messages Chat messages.
	 * @param array $functions Function definitions.
	 * @return int Estimated token count.
	 */
	private function estimateInputTokens( array $messages, array $functions ): int {
		// Simple estimation: ~4 chars per token.
		$text = wp_json_encode( $messages );
		if ( false === $text ) {
			$text = '';
		}
		$func_text = wp_json_encode( $functions );
		if ( false === $func_text ) {
			$func_text = '';
		}

		return (int) ceil( ( strlen( $text ) + strlen( $func_text ) ) / 4 );
	}

	/**
	 * Estimate output tokens for demo purposes.
	 *
	 * @param string $content Response content.
	 * @return int Estimated token count.
	 */
	private function estimateOutputTokens( string $content ): int {
		return (int) ceil( strlen( $content ) / 4 );
	}

	/**
	 * Generate demo raw response structure.
	 *
	 * @param string $content Response content.
	 * @return array Raw response structure.
	 */
	private function generateDemoRaw( string $content ): array {
		return array(
			'id'      => 'demo-' . bin2hex( random_bytes( 8 ) ),
			'object'  => 'chat.completion',
			'created' => time(),
			'model'   => self::DEMO_MODEL,
			'choices' => array(
				array(
					'index'         => 0,
					'message'       => array(
						'role'    => 'assistant',
						'content' => $content,
					),
					'finish_reason' => 'stop',
				),
			),
			'usage'   => array(
				'prompt_tokens'     => 0,
				'completion_tokens' => 0,
				'total_tokens'      => 0,
			),
		);
	}
}
