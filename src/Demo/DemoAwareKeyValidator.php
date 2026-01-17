<?php
/**
 * Demo-aware OpenAI key validator.
 *
 * Wraps the real OpenAIKeyValidator with demo-mode awareness.
 * When demo mode is enabled, validation behavior is deterministic
 * and never leaks real-key behavior.
 *
 * @package AgentWP\Demo
 */

namespace AgentWP\Demo;

use AgentWP\Contracts\OpenAIKeyValidatorInterface;
use WP_Error;

/**
 * Validates API keys with demo mode awareness.
 *
 * Behavior matrix:
 * - Demo mode OFF: Delegates to real validator
 * - Demo mode ON + stubbed: Always returns true (no API calls needed)
 * - Demo mode ON + demo key: Validates the demo key with real validator
 */
final class DemoAwareKeyValidator implements OpenAIKeyValidatorInterface {

	/**
	 * Demo credentials manager.
	 *
	 * @var DemoCredentials
	 */
	private DemoCredentials $demo_credentials;

	/**
	 * Real OpenAI key validator.
	 *
	 * @var OpenAIKeyValidatorInterface
	 */
	private OpenAIKeyValidatorInterface $real_validator;

	/**
	 * Create a new DemoAwareKeyValidator.
	 *
	 * @param DemoCredentials             $demo_credentials Demo credentials manager.
	 * @param OpenAIKeyValidatorInterface $real_validator   Real OpenAI key validator.
	 */
	public function __construct(
		DemoCredentials $demo_credentials,
		OpenAIKeyValidatorInterface $real_validator
	) {
		$this->demo_credentials = $demo_credentials;
		$this->real_validator   = $real_validator;
	}

	/**
	 * Validate an OpenAI API key with demo mode awareness.
	 *
	 * When demo mode is enabled:
	 * - Stubbed mode: Returns true without making API calls
	 * - Demo key mode: Validates only the demo key (ignores provided key)
	 *
	 * When demo mode is disabled:
	 * - Delegates to the real validator
	 *
	 * @param string $api_key The API key to validate.
	 * @return true|WP_Error True if valid, WP_Error on failure.
	 */
	public function validate( string $api_key ): bool|WP_Error {
		// If not in demo mode, use real validator as normal.
		if ( ! $this->demo_credentials->isDemoModeEnabled() ) {
			return $this->real_validator->validate( $api_key );
		}

		// Demo mode: stubbed behavior - always valid (no API calls).
		if ( $this->demo_credentials->shouldUseStubbed() ) {
			return true;
		}

		// Demo mode with demo key: validate the demo key, not the provided key.
		// This ensures that in demo mode, we never validate or expose real keys.
		$demo_key = $this->demo_credentials->getDemoApiKey();

		return $this->real_validator->validate( $demo_key );
	}

	/**
	 * Check if we're currently in demo mode.
	 *
	 * @return bool
	 */
	public function isDemoMode(): bool {
		return $this->demo_credentials->isDemoModeEnabled();
	}

	/**
	 * Get information about the current validation mode.
	 *
	 * @return array{mode: string, type: string, will_call_api: bool}
	 */
	public function getValidationInfo(): array {
		if ( ! $this->demo_credentials->isDemoModeEnabled() ) {
			return array(
				'mode'          => 'normal',
				'type'          => 'real_validation',
				'will_call_api' => true,
			);
		}

		if ( $this->demo_credentials->shouldUseStubbed() ) {
			return array(
				'mode'          => 'demo',
				'type'          => DemoCredentials::TYPE_STUBBED,
				'will_call_api' => false,
			);
		}

		return array(
			'mode'          => 'demo',
			'type'          => DemoCredentials::TYPE_DEMO_KEY,
			'will_call_api' => true,
		);
	}
}
