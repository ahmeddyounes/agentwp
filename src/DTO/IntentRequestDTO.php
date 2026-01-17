<?php
/**
 * Intent Request DTO.
 *
 * @package AgentWP\DTO
 */

namespace AgentWP\DTO;

/**
 * DTO for intent creation requests.
 */
final class IntentRequestDTO extends RequestDTO {

	/**
	 * Maximum allowed prompt length to prevent DoS via excessive input.
	 */
	private const MAX_PROMPT_LENGTH = 10000;

	/**
	 * {@inheritDoc}
	 */
	protected function getSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'prompt'   => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => self::MAX_PROMPT_LENGTH,
				),
				'input'    => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => self::MAX_PROMPT_LENGTH,
				),
				'context'  => array(
					'type' => 'object',
				),
				'metadata' => array(
					'type' => 'object',
				),
			),
		);
	}

	/**
	 * Get the prompt text.
	 *
	 * Supports both 'prompt' and 'input' keys for backwards compatibility.
	 *
	 * @return string
	 */
	public function getPrompt(): string {
		$prompt = $this->getString( 'prompt' );

		if ( '' === $prompt ) {
			$prompt = $this->getString( 'input' );
		}

		return $prompt;
	}

	/**
	 * Check if prompt is provided.
	 *
	 * @return bool
	 */
	public function hasPrompt(): bool {
		return '' !== $this->getPrompt();
	}

	/**
	 * Get context array.
	 *
	 * @return array
	 */
	public function getContext(): array {
		return $this->getArray( 'context' );
	}

	/**
	 * Get metadata array.
	 *
	 * @return array
	 */
	public function getMetadata(): array {
		return $this->getArray( 'metadata' );
	}
}
