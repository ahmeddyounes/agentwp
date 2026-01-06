<?php
/**
 * Encryption helper for API keys.
 *
 * @package AgentWP
 */

namespace AgentWP\Security;

class Encryption {
	const VERSION = 'awp1';
	const DELIMITER = ':';
	const CIPHER = 'aes-256-ctr';
	const LEGACY_CIPHER = 'aes-256-gcm';
	const LEGACY_NONCE_LENGTH = 12;
	const LEGACY_TAG_LENGTH = 16;

	/**
	 * Encrypt plaintext.
	 *
	 * @param string $plaintext Plaintext value.
	 * @return string
	 */
	public function encrypt( string $plaintext ): string {
		if ( '' === $plaintext ) {
			return '';
		}

		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return '';
		}

		$material = $this->get_key_material();
		if ( '' === $material ) {
			return '';
		}

		$iv_length = $this->get_iv_length();
		if ( $iv_length <= 0 ) {
			return '';
		}

		try {
			$iv = random_bytes( $iv_length );
		} catch ( \Exception $exception ) {
			return '';
		}

		$key = $this->derive_key( $material );

		$ciphertext = openssl_encrypt( $plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $ciphertext ) {
			return '';
		}

		$fingerprint = $this->get_fingerprint( $material );

		return self::VERSION . self::DELIMITER . $fingerprint . self::DELIMITER . base64_encode( $iv . $ciphertext );
	}

	/**
	 * Decrypt ciphertext.
	 *
	 * @param string $ciphertext Ciphertext value.
	 * @return string
	 */
	public function decrypt( string $ciphertext ): string {
		if ( '' === $ciphertext ) {
			return '';
		}

		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}

		$payload = $this->parse_payload( $ciphertext );
		if ( null !== $payload ) {
			$materials = $this->get_key_material_candidates();
			foreach ( $materials as $material ) {
				if ( $payload['fingerprint'] !== $this->get_fingerprint( $material ) ) {
					continue;
				}

				$key       = $this->derive_key( $material );
				$plaintext = openssl_decrypt( $payload['ciphertext'], self::CIPHER, $key, OPENSSL_RAW_DATA, $payload['iv'] );

				if ( false !== $plaintext ) {
					return $plaintext;
				}
			}

			return '';
		}

		$legacy = $this->parse_legacy_payload( $ciphertext );
		if ( null === $legacy ) {
			return '';
		}

		$materials = $this->get_key_material_candidates();
		foreach ( $materials as $material ) {
			$key       = $this->derive_key( $material );
			$plaintext = openssl_decrypt(
				$legacy['ciphertext'],
				self::LEGACY_CIPHER,
				$key,
				OPENSSL_RAW_DATA,
				$legacy['nonce'],
				$legacy['tag']
			);

			if ( false !== $plaintext ) {
				return $plaintext;
			}
		}

		return '';
	}

	/**
	 * Check if a value is encrypted.
	 *
	 * @param string $data Input value.
	 * @return bool
	 */
	public function isEncrypted( string $data ): bool {
		if ( '' === $data ) {
			return false;
		}

		if ( null !== $this->parse_payload( $data ) ) {
			return true;
		}

		return null !== $this->parse_legacy_payload( $data );
	}

	/**
	 * Re-encrypt ciphertext with current salts when possible.
	 *
	 * @param string $ciphertext Ciphertext value.
	 * @return string
	 */
	public function rotate( string $ciphertext ): string {
		if ( '' === $ciphertext ) {
			return '';
		}

		if ( $this->is_current_encryption( $ciphertext ) ) {
			return $ciphertext;
		}

		$plaintext = $this->decrypt( $ciphertext );
		if ( '' === $plaintext ) {
			return '';
		}

		return $this->encrypt( $plaintext );
	}

	/**
	 * Parse current payload format.
	 *
	 * @param string $data Payload.
	 * @return array|null
	 */
	private function parse_payload( string $data ) {
		if ( 0 !== strpos( $data, self::VERSION . self::DELIMITER ) ) {
			return null;
		}

		$parts = explode( self::DELIMITER, $data, 3 );
		if ( 3 !== count( $parts ) ) {
			return null;
		}

		list( $version, $fingerprint, $payload ) = $parts;
		if ( self::VERSION !== $version || '' === $fingerprint || '' === $payload ) {
			return null;
		}

		$decoded = base64_decode( $payload, true );
		if ( false === $decoded ) {
			return null;
		}

		$iv_length = $this->get_iv_length();
		if ( $iv_length <= 0 || strlen( $decoded ) <= $iv_length ) {
			return null;
		}

		return array(
			'fingerprint' => $fingerprint,
			'iv'          => substr( $decoded, 0, $iv_length ),
			'ciphertext'  => substr( $decoded, $iv_length ),
		);
	}

	/**
	 * Parse legacy payload format (AES-256-GCM).
	 *
	 * @param string $data Payload.
	 * @return array|null
	 */
	private function parse_legacy_payload( string $data ) {
		$decoded = base64_decode( $data, true );
		if ( false === $decoded ) {
			return null;
		}

		$min_length = self::LEGACY_NONCE_LENGTH + self::LEGACY_TAG_LENGTH + 1;
		if ( strlen( $decoded ) < $min_length ) {
			return null;
		}

		return array(
			'nonce'      => substr( $decoded, 0, self::LEGACY_NONCE_LENGTH ),
			'tag'        => substr( $decoded, self::LEGACY_NONCE_LENGTH, self::LEGACY_TAG_LENGTH ),
			'ciphertext' => substr( $decoded, self::LEGACY_NONCE_LENGTH + self::LEGACY_TAG_LENGTH ),
		);
	}

	/**
	 * Determine if ciphertext is encrypted with current salts.
	 *
	 * @param string $ciphertext Ciphertext value.
	 * @return bool
	 */
	private function is_current_encryption( string $ciphertext ): bool {
		$payload = $this->parse_payload( $ciphertext );
		if ( null === $payload ) {
			return false;
		}

		$material = $this->get_key_material();
		if ( '' === $material ) {
			return false;
		}

		return hash_equals( $payload['fingerprint'], $this->get_fingerprint( $material ) );
	}

	/**
	 * Collect key material candidates for rotation.
	 *
	 * @return array
	 */
	private function get_key_material_candidates(): array {
		$materials = array();
		$current   = $this->get_key_material();

		if ( '' !== $current ) {
			$materials[] = $current;
		}

		if ( function_exists( 'apply_filters' ) ) {
			$rotations = apply_filters( 'agentwp_encryption_rotation_materials', array() );
			if ( is_array( $rotations ) ) {
				foreach ( $rotations as $rotation ) {
					if ( is_string( $rotation ) && '' !== $rotation ) {
						$materials[] = $rotation;
					}
				}
			}
		}

		return array_values( array_unique( $materials ) );
	}

	/**
	 * Derive key material.
	 *
	 * @return string
	 */
	private function get_key_material(): string {
		$material = '';

		if ( defined( 'LOGGED_IN_KEY' ) ) {
			$material .= (string) LOGGED_IN_KEY;
		}

		if ( defined( 'LOGGED_IN_SALT' ) ) {
			$material .= (string) LOGGED_IN_SALT;
		}

		return $material;
	}

	/**
	 * Derive a 256-bit key from material.
	 *
	 * @param string $material Key material.
	 * @return string
	 */
	private function derive_key( string $material ): string {
		return hash( 'sha256', $material, true );
	}

	/**
	 * Get a fingerprint for key material.
	 *
	 * @param string $material Key material.
	 * @return string
	 */
	private function get_fingerprint( string $material ): string {
		return hash( 'sha256', $material );
	}

	/**
	 * Return IV length for the cipher.
	 *
	 * @return int
	 */
	private function get_iv_length(): int {
		$length = openssl_cipher_iv_length( self::CIPHER );

		return false === $length ? 0 : (int) $length;
	}
}
