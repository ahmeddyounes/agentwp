<?php
/**
 * Token counting helpers using tiktoken-php.
 *
 * @package AgentWP
 */

namespace AgentWP\AI;

class TokenCounter {
	/**
	 * @var array<string, mixed>
	 */
	private $encoders = array();

	/**
	 * Count tokens for a chat request payload.
	 *
	 * @param array  $messages Chat messages.
	 * @param array  $tools Tool definitions.
	 * @param string $model Model name.
	 * @return int
	 */
	public function count_request_tokens( array $messages, array $tools, $model ) {
		$model = Model::normalize( $model );

		return $this->count_message_tokens( $messages, $model )
			+ $this->count_tool_tokens( $tools, $model );
	}

	/**
	 * Count tokens for messages using OpenAI chat heuristics.
	 *
	 * @param array  $messages Chat messages.
	 * @param string $model Model name.
	 * @return int
	 */
	public function count_message_tokens( array $messages, $model ) {
		$model = Model::normalize( $model );
		$tokens_per_message = 3;
		$tokens_per_name    = 1;
		$total              = 0;

		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}

			$total += $tokens_per_message;

			foreach ( $message as $key => $value ) {
				$string = $this->stringify_value( $value );
				$total += $this->count_text_tokens( $string, $model );

				if ( 'name' === $key ) {
					$total += $tokens_per_name;
				}
			}
		}

		$total += 3;

		return $total;
	}

	/**
	 * Count tokens for tool definitions.
	 *
	 * @param array  $tools Tool definitions.
	 * @param string $model Model name.
	 * @return int
	 */
	public function count_tool_tokens( array $tools, $model ) {
		if ( empty( $tools ) ) {
			return 0;
		}

		$payload = wp_json_encode( $tools );
		if ( false === $payload ) {
			$payload = '';
		}

		return $this->count_text_tokens( $payload, $model );
	}

	/**
	 * Count tokens for plain text.
	 *
	 * @param string $text Text input.
	 * @param string $model Model name.
	 * @return int
	 */
	public function count_text_tokens( $text, $model ) {
		$text  = is_string( $text ) ? $text : '';
		$model = Model::normalize( $model );

		$encoder = $this->get_encoder( $model );
		if ( null === $encoder ) {
			return $this->approximate_tokens( $text );
		}

		$tokens = $encoder->encode( $text );
		return is_array( $tokens ) ? count( $tokens ) : 0;
	}

	/**
	 * @param string $model Model name.
	 * @return mixed|null
	 */
	private function get_encoder( $model ) {
		$model = Model::normalize( $model );

		if ( ! class_exists( '\\Tiktoken\\EncoderProvider' ) ) {
			return null;
		}

		if ( isset( $this->encoders[ $model ] ) ) {
			return $this->encoders[ $model ];
		}

		$provider = new \Tiktoken\EncoderProvider();
		$encoder  = $provider->getEncoderForModel( $model );

		$this->encoders[ $model ] = $encoder;

		return $encoder;
	}

	/**
	 * Convert values to a string for tokenization.
	 *
	 * @param mixed $value Value to stringify.
	 * @return string
	 */
	private function stringify_value( $value ) {
		if ( is_string( $value ) ) {
			return $value;
		}

		if ( is_numeric( $value ) ) {
			return (string) $value;
		}

		if ( is_array( $value ) || is_object( $value ) ) {
			$encoded = wp_json_encode( $value );
			return false === $encoded ? '' : $encoded;
		}

		return '';
	}

	/**
	 * Approximate tokens when tiktoken is unavailable.
	 *
	 * Uses mb_strlen to count UTF-8 characters, not bytes.
	 * This provides more accurate estimates for multibyte text.
	 *
	 * @param string $text Text input.
	 * @return int
	 */
	private function approximate_tokens( $text ) {
		if ( '' === $text ) {
			return 0;
		}

		// Use mb_strlen for accurate character count with multibyte text.
		$char_count = function_exists( 'mb_strlen' )
			? mb_strlen( $text, 'UTF-8' )
			: strlen( $text );

		return (int) ceil( $char_count / 4 );
	}
}
