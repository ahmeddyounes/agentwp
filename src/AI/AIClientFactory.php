<?php
/**
 * AI client factory.
 *
 * @package AgentWP\AI
 */

namespace AgentWP\AI;

use AgentWP\Contracts\AIClientFactoryInterface;
use AgentWP\Contracts\OpenAIClientInterface;
use AgentWP\Plugin\SettingsManager;

/**
 * Factory for creating AI clients.
 */
class AIClientFactory implements AIClientFactoryInterface {

	/**
	 * @var SettingsManager
	 */
	private SettingsManager $settings;

	/**
	 * @var string Default model to use.
	 */
	private string $default_model;

	/**
	 * @param SettingsManager $settings Settings manager.
	 * @param string          $default_model Default model (optional).
	 */
	public function __construct( SettingsManager $settings, string $default_model = Model::GPT_4O_MINI ) {
		$this->settings      = $settings;
		$this->default_model = $default_model;
	}

	/**
	 * Create an AI client for the given intent.
	 *
	 * @param string $intent Intent identifier for usage tracking.
	 * @param array  $options Optional configuration overrides.
	 * @return OpenAIClientInterface
	 */
	public function create( string $intent, array $options = array() ): OpenAIClientInterface {
		$api_key = $this->settings->getApiKey();
		$model   = isset( $options['model'] ) ? $options['model'] : $this->default_model;

		$client_options = array_merge(
			array(
				'intent_type' => $intent,
			),
			$options
		);

		return new OpenAIClient( $api_key, $model, $client_options );
	}

	/**
	 * Check if API key is configured.
	 *
	 * @return bool
	 */
	public function hasApiKey(): bool {
		return '' !== $this->settings->getApiKey();
	}
}
