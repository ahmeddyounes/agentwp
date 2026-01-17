<?php
/**
 * Demo credentials manager.
 *
 * Provides explicit rules for demo mode credential handling:
 * - If a demo API key is configured, use it for real API calls
 * - If no demo API key is available, use stubbed responses
 * - Real API keys are NEVER used when demo mode is enabled
 *
 * @package AgentWP\Demo
 */

namespace AgentWP\Demo;

use AgentWP\Plugin\SettingsManager;

/**
 * Manages demo mode credentials and behavior rules.
 */
class DemoCredentials {

	/**
	 * Demo credential type: use demo API key for real calls.
	 */
	public const TYPE_DEMO_KEY = 'demo_key';

	/**
	 * Demo credential type: use stubbed responses (no API calls).
	 */
	public const TYPE_STUBBED = 'stubbed';

	/**
	 * Settings manager.
	 *
	 * @var SettingsManager
	 */
	private SettingsManager $settings;

	/**
	 * Create a new DemoCredentials instance.
	 *
	 * @param SettingsManager $settings Settings manager.
	 */
	public function __construct( SettingsManager $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Check if demo mode is enabled.
	 *
	 * @return bool
	 */
	public function isDemoModeEnabled(): bool {
		return $this->settings->isDemoMode();
	}

	/**
	 * Get the demo API key (from config, env, or stored option).
	 *
	 * Priority:
	 * 1. AGENTWP_DEMO_API_KEY constant
	 * 2. AGENTWP_DEMO_API_KEY environment variable
	 * 3. Stored demo API key in database
	 *
	 * @return string Demo API key or empty string.
	 */
	public function getDemoApiKey(): string {
		// Check constant first.
		if ( defined( 'AGENTWP_DEMO_API_KEY' ) && is_string( AGENTWP_DEMO_API_KEY ) && '' !== AGENTWP_DEMO_API_KEY ) {
			return AGENTWP_DEMO_API_KEY;
		}

		// Check environment variable.
		$env_key = getenv( 'AGENTWP_DEMO_API_KEY' );
		if ( is_string( $env_key ) && '' !== $env_key ) {
			return $env_key;
		}

		// Fall back to stored demo API key.
		return $this->settings->getDemoApiKey();
	}

	/**
	 * Determine which credential type to use in demo mode.
	 *
	 * @return string One of TYPE_DEMO_KEY or TYPE_STUBBED.
	 */
	public function getCredentialType(): string {
		if ( '' !== $this->getDemoApiKey() ) {
			return self::TYPE_DEMO_KEY;
		}

		return self::TYPE_STUBBED;
	}

	/**
	 * Check if stubbed responses should be used.
	 *
	 * @return bool True if no demo API key is available.
	 */
	public function shouldUseStubbed(): bool {
		return $this->isDemoModeEnabled() && self::TYPE_STUBBED === $this->getCredentialType();
	}

	/**
	 * Check if demo API key should be used.
	 *
	 * @return bool True if demo mode is on AND a demo key is available.
	 */
	public function shouldUseDemoKey(): bool {
		return $this->isDemoModeEnabled() && self::TYPE_DEMO_KEY === $this->getCredentialType();
	}

	/**
	 * Get the appropriate API key for the current mode.
	 *
	 * IMPORTANT: When demo mode is enabled, this NEVER returns the real API key.
	 *
	 * @return string API key to use, or empty string for stubbed mode.
	 */
	public function getEffectiveApiKey(): string {
		if ( ! $this->isDemoModeEnabled() ) {
			// Normal mode: return the real API key.
			return $this->settings->getApiKey();
		}

		// Demo mode: only use demo key, NEVER the real key.
		return $this->getDemoApiKey();
	}

	/**
	 * Validate demo mode configuration.
	 *
	 * @return array{valid: bool, type: string, message: string}
	 */
	public function validate(): array {
		if ( ! $this->isDemoModeEnabled() ) {
			return array(
				'valid'   => true,
				'type'    => 'normal',
				'message' => 'Demo mode is disabled.',
			);
		}

		$demo_key = $this->getDemoApiKey();

		if ( '' !== $demo_key ) {
			return array(
				'valid'   => true,
				'type'    => self::TYPE_DEMO_KEY,
				'message' => 'Demo mode active with demo API key.',
			);
		}

		return array(
			'valid'   => true,
			'type'    => self::TYPE_STUBBED,
			'message' => 'Demo mode active with stubbed responses (no demo API key configured).',
		);
	}
}
