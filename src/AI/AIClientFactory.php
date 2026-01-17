<?php
/**
 * AI client factory.
 *
 * Creates appropriate AI clients based on configuration and demo mode status.
 * When demo mode is enabled, this factory NEVER uses the real API key.
 *
 * @package AgentWP\AI
 */

namespace AgentWP\AI;

use AgentWP\Contracts\AIClientFactoryInterface;
use AgentWP\Contracts\OpenAIClientInterface;
use AgentWP\Demo\DemoClient;
use AgentWP\Demo\DemoCredentials;
use AgentWP\Plugin\SettingsManager;

/**
 * Factory for creating AI clients.
 *
 * Demo mode behavior:
 * - If demo mode is ON and demo API key exists: creates OpenAIClient with demo key
 * - If demo mode is ON and no demo API key: creates DemoClient with stubbed responses
 * - If demo mode is OFF: creates OpenAIClient with real API key
 *
 * IMPORTANT: Real API keys are NEVER used when demo mode is enabled.
 */
class AIClientFactory implements AIClientFactoryInterface {

	/**
	 * Settings manager.
	 *
	 * @var SettingsManager
	 */
	private SettingsManager $settings;

	/**
	 * Demo credentials manager.
	 *
	 * @var DemoCredentials
	 */
	private DemoCredentials $demo_credentials;

	/**
	 * Default model to use.
	 *
	 * @var string
	 */
	private string $default_model;

	/**
	 * Create a new AIClientFactory.
	 *
	 * @param SettingsManager $settings Settings manager.
	 * @param string          $default_model Default model.
	 * @param DemoCredentials $demo_credentials Demo credentials manager.
	 */
	public function __construct(
		SettingsManager $settings,
		string $default_model,
		DemoCredentials $demo_credentials
	) {
		$this->settings         = $settings;
		$this->default_model    = $default_model;
		$this->demo_credentials = $demo_credentials;
	}

	/**
	 * Create an AI client for the given intent.
	 *
	 * Returns the appropriate client based on demo mode status:
	 * - Demo mode OFF: OpenAIClient with real API key
	 * - Demo mode ON + demo key: OpenAIClient with demo API key
	 * - Demo mode ON + no demo key: DemoClient with stubbed responses
	 *
	 * @param string $intent Intent identifier for usage tracking.
	 * @param array  $options Optional configuration overrides.
	 * @return OpenAIClientInterface
	 */
	public function create( string $intent, array $options = array() ): OpenAIClientInterface {
		$model = isset( $options['model'] ) ? $options['model'] : $this->default_model;

		$client_options = array_merge(
			array(
				'intent_type' => $intent,
			),
			$options
		);

		// Check if we should use stubbed demo responses.
		if ( $this->demo_credentials->shouldUseStubbed() ) {
			return new DemoClient( $model, $client_options );
		}

		// Get the effective API key (demo key in demo mode, real key otherwise).
		// CRITICAL: This method enforces the rule that real keys are never used in demo mode.
		$api_key = $this->demo_credentials->getEffectiveApiKey();

		return new OpenAIClient( $api_key, $model, $client_options );
	}

	/**
	 * Check if an API key is available for the current mode.
	 *
	 * In demo mode, checks for demo API key availability.
	 * In normal mode, checks for real API key availability.
	 * Stubbed demo mode always returns true (no key needed).
	 *
	 * @return bool
	 */
	public function hasApiKey(): bool {
		// Stubbed demo mode doesn't need an API key.
		if ( $this->demo_credentials->shouldUseStubbed() ) {
			return true;
		}

		// Otherwise check for an effective key.
		return '' !== $this->demo_credentials->getEffectiveApiKey();
	}

	/**
	 * Check if demo mode is currently active.
	 *
	 * @return bool
	 */
	public function isDemoMode(): bool {
		return $this->demo_credentials->isDemoModeEnabled();
	}

	/**
	 * Get information about the current credential configuration.
	 *
	 * @return array{mode: string, type: string, has_key: bool}
	 */
	public function getCredentialInfo(): array {
		$is_demo = $this->demo_credentials->isDemoModeEnabled();

		if ( ! $is_demo ) {
			return array(
				'mode'    => 'normal',
				'type'    => 'real_key',
				'has_key' => '' !== $this->settings->getApiKey(),
			);
		}

		$type = $this->demo_credentials->getCredentialType();

		return array(
			'mode'    => 'demo',
			'type'    => $type,
			'has_key' => DemoCredentials::TYPE_STUBBED !== $type,
		);
	}
}
