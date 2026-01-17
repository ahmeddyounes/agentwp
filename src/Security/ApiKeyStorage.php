<?php
/**
 * API key storage service.
 *
 * Encapsulates all encrypt/decrypt/rotate/last4 operations for API keys.
 *
 * @package AgentWP\Security
 */

namespace AgentWP\Security;

use AgentWP\Contracts\OptionsInterface;
use AgentWP\Plugin\SettingsManager;
use WP_Error;

/**
 * Service for storing and retrieving encrypted API keys.
 */
class ApiKeyStorage {

	/**
	 * Encryption service.
	 *
	 * @var Encryption
	 */
	private Encryption $encryption;

	/**
	 * Options interface.
	 *
	 * @var OptionsInterface
	 */
	private OptionsInterface $options;

	/**
	 * Create a new ApiKeyStorage instance.
	 *
	 * @param Encryption       $encryption Encryption service.
	 * @param OptionsInterface $options    Options interface.
	 */
	public function __construct( Encryption $encryption, OptionsInterface $options ) {
		$this->encryption = $encryption;
		$this->options    = $options;
	}

	/**
	 * Store an API key (encrypted) with its last4.
	 *
	 * @param string $api_key    The plaintext API key.
	 * @param string $key_option Option name for the encrypted key.
	 * @param string $last4_option Option name for the last4.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public function store( string $api_key, string $key_option, string $last4_option ) {
		if ( '' === $api_key ) {
			$this->options->delete( $key_option );
			$this->options->delete( $last4_option );
			return true;
		}

		$encrypted = $this->encryption->encrypt( $api_key );
		if ( '' === $encrypted ) {
			return new WP_Error( 'encryption_failed', __( 'Unable to encrypt the API key.', 'agentwp' ) );
		}

		$this->options->set( $key_option, $encrypted );
		$this->options->set( $last4_option, $this->extractLast4( $api_key ) );

		return true;
	}

	/**
	 * Store the primary API key.
	 *
	 * @param string $api_key The plaintext API key.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public function storePrimary( string $api_key ) {
		return $this->store(
			$api_key,
			SettingsManager::OPTION_API_KEY,
			SettingsManager::OPTION_API_KEY_LAST4
		);
	}

	/**
	 * Store the demo API key.
	 *
	 * @param string $api_key The plaintext API key.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public function storeDemo( string $api_key ) {
		return $this->store(
			$api_key,
			SettingsManager::OPTION_DEMO_API_KEY,
			SettingsManager::OPTION_DEMO_API_KEY_LAST4
		);
	}

	/**
	 * Retrieve and decrypt an API key.
	 *
	 * @param string $key_option Option name for the encrypted key.
	 * @return string The decrypted API key or empty string.
	 */
	public function retrieve( string $key_option ): string {
		$stored = $this->options->get( $key_option, '' );
		$stored = is_string( $stored ) ? $stored : '';

		if ( '' === $stored ) {
			return '';
		}

		$decrypted = $this->encryption->decrypt( $stored );
		if ( '' !== $decrypted ) {
			return $decrypted;
		}

		// If decryption fails, check if it's actually encrypted.
		// Return empty if encrypted but undecryptable, otherwise return raw value.
		return $this->encryption->isEncrypted( $stored ) ? '' : $stored;
	}

	/**
	 * Retrieve the primary API key.
	 *
	 * @return string The decrypted API key or empty string.
	 */
	public function retrievePrimary(): string {
		return $this->retrieve( SettingsManager::OPTION_API_KEY );
	}

	/**
	 * Retrieve the demo API key.
	 *
	 * @return string The decrypted API key or empty string.
	 */
	public function retrieveDemo(): string {
		return $this->retrieve( SettingsManager::OPTION_DEMO_API_KEY );
	}

	/**
	 * Get the last 4 characters of a stored key.
	 *
	 * @param string $last4_option Option name for the last4.
	 * @return string The last 4 characters or empty string.
	 */
	public function getLast4( string $last4_option ): string {
		$last4 = $this->options->get( $last4_option, '' );
		return is_string( $last4 ) ? $last4 : '';
	}

	/**
	 * Get the last 4 characters of the primary API key.
	 *
	 * @return string The last 4 characters or empty string.
	 */
	public function getPrimaryLast4(): string {
		return $this->getLast4( SettingsManager::OPTION_API_KEY_LAST4 );
	}

	/**
	 * Get the last 4 characters of the demo API key.
	 *
	 * @return string The last 4 characters or empty string.
	 */
	public function getDemoLast4(): string {
		return $this->getLast4( SettingsManager::OPTION_DEMO_API_KEY_LAST4 );
	}

	/**
	 * Check if a key is stored.
	 *
	 * @param string $key_option   Option name for the encrypted key.
	 * @param string $last4_option Option name for the last4.
	 * @return bool True if a key is stored.
	 */
	public function hasKey( string $key_option, string $last4_option ): bool {
		$last4  = $this->getLast4( $last4_option );
		$stored = $this->options->get( $key_option, '' );
		$stored = is_string( $stored ) ? $stored : '';

		return '' !== $last4 || '' !== $stored;
	}

	/**
	 * Check if the primary API key is stored.
	 *
	 * @return bool True if the primary key is stored.
	 */
	public function hasPrimaryKey(): bool {
		return $this->hasKey(
			SettingsManager::OPTION_API_KEY,
			SettingsManager::OPTION_API_KEY_LAST4
		);
	}

	/**
	 * Check if the demo API key is stored.
	 *
	 * @return bool True if the demo key is stored.
	 */
	public function hasDemoKey(): bool {
		return $this->hasKey(
			SettingsManager::OPTION_DEMO_API_KEY,
			SettingsManager::OPTION_DEMO_API_KEY_LAST4
		);
	}

	/**
	 * Delete a stored key.
	 *
	 * @param string $key_option   Option name for the encrypted key.
	 * @param string $last4_option Option name for the last4.
	 * @return void
	 */
	public function delete( string $key_option, string $last4_option ): void {
		$this->options->delete( $key_option );
		$this->options->delete( $last4_option );
	}

	/**
	 * Delete the primary API key.
	 *
	 * @return void
	 */
	public function deletePrimary(): void {
		$this->delete(
			SettingsManager::OPTION_API_KEY,
			SettingsManager::OPTION_API_KEY_LAST4
		);
	}

	/**
	 * Delete the demo API key.
	 *
	 * @return void
	 */
	public function deleteDemo(): void {
		$this->delete(
			SettingsManager::OPTION_DEMO_API_KEY,
			SettingsManager::OPTION_DEMO_API_KEY_LAST4
		);
	}

	/**
	 * Rotate a stored key to use current encryption salts.
	 *
	 * @param string $key_option Option name for the encrypted key.
	 * @return bool True if rotation occurred, false otherwise.
	 */
	public function rotate( string $key_option ): bool {
		$stored = $this->options->get( $key_option, '' );
		$stored = is_string( $stored ) ? $stored : '';

		if ( '' === $stored ) {
			return false;
		}

		$rotated = $this->encryption->rotate( $stored );
		if ( '' === $rotated || $rotated === $stored ) {
			return false;
		}

		$this->options->set( $key_option, $rotated );
		return true;
	}

	/**
	 * Rotate the primary API key.
	 *
	 * @return bool True if rotation occurred.
	 */
	public function rotatePrimary(): bool {
		return $this->rotate( SettingsManager::OPTION_API_KEY );
	}

	/**
	 * Rotate the demo API key.
	 *
	 * @return bool True if rotation occurred.
	 */
	public function rotateDemo(): bool {
		return $this->rotate( SettingsManager::OPTION_DEMO_API_KEY );
	}

	/**
	 * Extract the last 4 characters from an API key.
	 *
	 * @param string $api_key The API key.
	 * @return string The last 4 characters.
	 */
	public function extractLast4( string $api_key ): string {
		return strlen( $api_key ) >= 4 ? substr( $api_key, -4 ) : '';
	}
}
