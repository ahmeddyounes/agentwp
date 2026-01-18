<?php
/**
 * History Request DTO.
 *
 * @package AgentWP\DTO
 */

namespace AgentWP\DTO;

use AgentWP\Config\AgentWPConfig;

/**
 * DTO for history update requests.
 */
final class HistoryRequestDTO extends RequestDTO {

	/**
	 * Get maximum history entries.
	 *
	 * Configurable via 'agentwp_config_history_limit' filter.
	 *
	 * @return int
	 */
	private static function getHistoryLimit(): int {
		return (int) AgentWPConfig::get( 'history.limit', AgentWPConfig::HISTORY_LIMIT );
	}

	/**
	 * Get maximum favorites entries.
	 *
	 * Configurable via 'agentwp_config_favorites_limit' filter.
	 *
	 * @return int
	 */
	private static function getFavoritesLimit(): int {
		return (int) AgentWPConfig::get( 'favorites.limit', AgentWPConfig::FAVORITES_LIMIT );
	}

	/**
	 * Cached normalized history entries.
	 *
	 * @var array|null
	 */
	private ?array $normalized_history = null;

	/**
	 * Cached normalized favorites entries.
	 *
	 * @var array|null
	 */
	private ?array $normalized_favorites = null;

	/**
	 * {@inheritDoc}
	 */
	protected function getSchema(): array {
		$entry_schema = array(
			'type'       => 'object',
			'properties' => array(
				'raw_input'      => array(
					'type'      => 'string',
					'minLength' => 1,
				),
				'parsed_intent'  => array(
					'type' => 'string',
				),
				'timestamp'      => array(
					'type' => 'string',
				),
				'was_successful' => array(
					'type' => 'boolean',
				),
			),
		);

		return array(
			'type'       => 'object',
			'properties' => array(
				'history'   => array(
					'type'     => 'array',
					'items'    => $entry_schema,
					'maxItems' => self::getHistoryLimit(),
				),
				'favorites' => array(
					'type'     => 'array',
					'items'    => $entry_schema,
					'maxItems' => self::getFavoritesLimit(),
				),
			),
		);
	}

	/**
	 * Get normalized history entries.
	 *
	 * @return array<int, array{raw_input: string, parsed_intent: string, timestamp: string, was_successful: bool}>
	 */
	public function getHistory(): array {
		if ( null === $this->normalized_history ) {
			$this->normalized_history = $this->normalizeEntries(
				$this->getArray( 'history' ),
				self::getHistoryLimit()
			);
		}

		return $this->normalized_history;
	}

	/**
	 * Get normalized favorites entries.
	 *
	 * @return array<int, array{raw_input: string, parsed_intent: string, timestamp: string, was_successful: bool}>
	 */
	public function getFavorites(): array {
		if ( null === $this->normalized_favorites ) {
			$this->normalized_favorites = $this->normalizeEntries(
				$this->getArray( 'favorites' ),
				self::getFavoritesLimit()
			);
		}

		return $this->normalized_favorites;
	}

	/**
	 * Normalize and sanitize entry arrays.
	 *
	 * @param array $entries Raw entries from request.
	 * @param int   $limit   Maximum entries to return.
	 * @return array<int, array{raw_input: string, parsed_intent: string, timestamp: string, was_successful: bool}>
	 */
	private function normalizeEntries( array $entries, int $limit ): array {
		$normalized = array();

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$raw_input = isset( $entry['raw_input'] )
				? sanitize_text_field( wp_unslash( $entry['raw_input'] ) )
				: '';

			if ( '' === $raw_input ) {
				continue;
			}

			$parsed_intent = isset( $entry['parsed_intent'] )
				? sanitize_text_field( wp_unslash( $entry['parsed_intent'] ) )
				: '';

			$timestamp = isset( $entry['timestamp'] )
				? sanitize_text_field( wp_unslash( $entry['timestamp'] ) )
				: '';

			$timestamp      = '' !== $timestamp ? $timestamp : gmdate( 'c' );
			$was_successful = isset( $entry['was_successful'] ) ? (bool) $entry['was_successful'] : false;

			$normalized[] = array(
				'raw_input'      => $raw_input,
				'parsed_intent'  => $parsed_intent,
				'timestamp'      => $timestamp,
				'was_successful' => $was_successful,
			);
		}

		if ( $limit > 0 && count( $normalized ) > $limit ) {
			$normalized = array_slice( $normalized, 0, $limit );
		}

		return $normalized;
	}
}
