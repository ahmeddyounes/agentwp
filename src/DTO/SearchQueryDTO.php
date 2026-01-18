<?php
/**
 * Search Query Request DTO.
 *
 * @package AgentWP\DTO
 */

namespace AgentWP\DTO;

/**
 * DTO for search query requests.
 */
final class SearchQueryDTO extends RequestDTO {

	/**
	 * Maximum query length to prevent DoS.
	 */
	private const MAX_QUERY_LENGTH = 500;

	/**
	 * {@inheritDoc}
	 */
	protected function getSource(): string {
		return 'query';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function getSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'q'     => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => self::MAX_QUERY_LENGTH,
				),
				'types' => array(
					'type' => array( 'string', 'array' ),
				),
			),
			'required'   => array( 'q' ),
		);
	}

	/**
	 * Get the search query.
	 *
	 * @return string
	 */
	public function getQuery(): string {
		return sanitize_text_field( $this->getString( 'q' ) );
	}

	/**
	 * Get the search types as an array.
	 *
	 * Handles both comma-separated string and array formats.
	 *
	 * @return array<string>
	 */
	public function getTypes(): array {
		$types = array();

		if ( ! $this->has( 'types' ) ) {
			return $types;
		}

		$raw_types = $this->data['types'];

		if ( is_array( $raw_types ) ) {
			$types = $raw_types;
		} elseif ( is_string( $raw_types ) ) {
			$types = explode( ',', $raw_types );
		}

		return array_map( 'trim', array_filter( $types ) );
	}

	/**
	 * Check if query is provided.
	 *
	 * @return bool
	 */
	public function hasQuery(): bool {
		return '' !== $this->getQuery();
	}
}
