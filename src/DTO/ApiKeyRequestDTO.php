<?php
/**
 * API Key Request DTO.
 *
 * @package AgentWP\DTO
 */

namespace AgentWP\DTO;

/**
 * DTO for API key update requests.
 */
final class ApiKeyRequestDTO extends RequestDTO {

	/**
	 * {@inheritDoc}
	 */
	protected function getSchema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'api_key' => array(
					'type'      => 'string',
					'minLength' => 20,
					'maxLength' => 256,
				),
			),
		);
	}

	/**
	 * Get the API key.
	 *
	 * @return string Sanitized API key.
	 */
	public function getApiKey(): string {
		return sanitize_text_field( wp_unslash( $this->getString( 'api_key' ) ) );
	}

	/**
	 * Check if API key is empty (deletion request).
	 *
	 * @return bool
	 */
	public function isEmpty(): bool {
		return '' === $this->getApiKey();
	}

	/**
	 * Check if API key has valid format.
	 *
	 * @return bool
	 */
	public function hasValidFormat(): bool {
		$key = $this->getApiKey();
		return '' === $key || 0 === strpos( $key, 'sk-' );
	}
}
