<?php
/**
 * Draft payload DTO.
 *
 * @package AgentWP\DTO
 */

namespace AgentWP\DTO;

/**
 * Immutable value object representing a draft's standardized payload.
 *
 * All draft-based flows use this consistent shape to ensure uniform
 * handling of draft lifecycle operations.
 */
final class DraftPayload {

	/**
	 * Create a new DraftPayload.
	 *
	 * @param string $id        Unique draft identifier.
	 * @param string $type      Draft type (e.g., 'refund', 'status', 'stock').
	 * @param array  $payload   Operation-specific data.
	 * @param array  $preview   Human-readable preview data.
	 * @param int    $createdAt Unix timestamp when draft was created.
	 * @param int    $expiresAt Unix timestamp when draft expires.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $type,
		public readonly array $payload,
		public readonly array $preview,
		public readonly int $createdAt,
		public readonly int $expiresAt,
	) {
	}

	/**
	 * Create from stored array data.
	 *
	 * @param array $data Stored draft data.
	 * @return self|null Payload or null if data is invalid.
	 */
	public static function fromArray( array $data ): ?self {
		if ( empty( $data['id'] ) || empty( $data['type'] ) ) {
			return null;
		}

		return new self(
			id: $data['id'],
			type: $data['type'],
			payload: $data['payload'] ?? array(),
			preview: $data['preview'] ?? array(),
			createdAt: $data['created_at'] ?? 0,
			expiresAt: $data['expires_at'] ?? 0,
		);
	}

	/**
	 * Convert to array for storage.
	 *
	 * @return array
	 */
	public function toArray(): array {
		return array(
			'id'         => $this->id,
			'type'       => $this->type,
			'payload'    => $this->payload,
			'preview'    => $this->preview,
			'created_at' => $this->createdAt,
			'expires_at' => $this->expiresAt,
		);
	}

	/**
	 * Check if the draft has expired.
	 *
	 * @param int|null $now Current timestamp (defaults to time()).
	 * @return bool
	 */
	public function isExpired( ?int $now = null ): bool {
		$now = $now ?? time();
		return $this->expiresAt > 0 && $now >= $this->expiresAt;
	}

	/**
	 * Get a specific payload value.
	 *
	 * @param string $key     Payload key.
	 * @param mixed  $default Default value if key doesn't exist.
	 * @return mixed
	 */
	public function get( string $key, $default = null ) {
		return $this->payload[ $key ] ?? $default;
	}

	/**
	 * Get remaining time until expiration in seconds.
	 *
	 * @param int|null $now Current timestamp (defaults to time()).
	 * @return int Seconds remaining, or 0 if expired.
	 */
	public function getRemainingSeconds( ?int $now = null ): int {
		$now       = $now ?? time();
		$remaining = $this->expiresAt - $now;
		return max( 0, $remaining );
	}
}
