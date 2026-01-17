<?php
/**
 * Settings manager.
 *
 * @package AgentWP\Plugin
 */

namespace AgentWP\Plugin;

use AgentWP\Config\AgentWPConfig;
use AgentWP\Contracts\OptionsInterface;
use AgentWP\Security\ApiKeyStorage;

/**
 * Manages plugin settings and defaults.
 */
class SettingsManager {

	/**
	 * Option keys.
	 */
	public const OPTION_SETTINGS           = 'agentwp_settings';
	public const OPTION_API_KEY            = 'agentwp_api_key';
	public const OPTION_API_KEY_LAST4      = 'agentwp_api_key_last4';
	public const OPTION_DEMO_API_KEY       = 'agentwp_demo_api_key';
	public const OPTION_DEMO_API_KEY_LAST4 = 'agentwp_demo_api_key_last4';
	public const OPTION_BUDGET_LIMIT       = 'agentwp_budget_limit';
	public const OPTION_DRAFT_TTL          = 'agentwp_draft_ttl_minutes';
	public const OPTION_USAGE_STATS        = 'agentwp_usage_stats';
	public const OPTION_MEMORY_LIMIT       = 'agentwp_memory_limit';
	public const OPTION_MEMORY_TTL         = 'agentwp_memory_ttl';

	/**
	 * Default values for standalone options.
	 */
	public const DEFAULT_BUDGET_LIMIT       = 0;
	public const DEFAULT_DRAFT_TTL          = 10;
	public const DEFAULT_MEMORY_LIMIT       = 5;
	public const DEFAULT_MEMORY_TTL         = 1800;
	public const DEFAULT_API_KEY            = '';
	public const DEFAULT_API_KEY_LAST4      = '';
	public const DEFAULT_DEMO_API_KEY       = '';
	public const DEFAULT_DEMO_API_KEY_LAST4 = '';

	/**
	 * Options interface.
	 *
	 * @var OptionsInterface
	 */
	private OptionsInterface $options;

	/**
	 * API key storage service.
	 *
	 * @var ApiKeyStorage|null
	 */
	private ?ApiKeyStorage $apiKeyStorage = null;

	/**
	 * Create a new SettingsManager.
	 *
	 * @param OptionsInterface    $options       Options interface.
	 * @param ApiKeyStorage|null  $apiKeyStorage API key storage service.
	 */
	public function __construct( OptionsInterface $options, ?ApiKeyStorage $apiKeyStorage = null ) {
		$this->options       = $options;
		$this->apiKeyStorage = $apiKeyStorage;
	}

	/**
	 * Get all settings merged with defaults.
	 *
	 * @return array
	 */
	public function getAll(): array {
		$settings = $this->options->get( self::OPTION_SETTINGS, array() );
		$settings = is_array( $settings ) ? $settings : array();

		return array_merge( self::getDefaults(), $settings );
	}

	/**
	 * Get a specific setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public function get( string $key, mixed $default = null ): mixed {
		$settings = $this->getAll();

		return $settings[ $key ] ?? $default;
	}

	/**
	 * Update settings.
	 *
	 * @param array $settings Settings to update.
	 * @return bool
	 */
	public function update( array $settings ): bool {
		$current = $this->getAll();
		$merged  = array_merge( $current, $settings );

		return $this->options->set( self::OPTION_SETTINGS, $merged );
	}

	/**
	 * Set a specific setting.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Setting value.
	 * @return bool
	 */
	public function set( string $key, mixed $value ): bool {
		return $this->update( array( $key => $value ) );
	}

	/**
	 * Get the decrypted API key.
	 *
	 * @return string
	 */
	public function getApiKey(): string {
		if ( $this->apiKeyStorage ) {
			return $this->apiKeyStorage->retrievePrimary();
		}

		return (string) $this->options->get( self::OPTION_API_KEY, '' );
	}

	/**
	 * Store the API key (encrypts and manages last4).
	 *
	 * @param string $key Plaintext API key.
	 * @return bool
	 */
	public function setApiKey( string $key ): bool {
		if ( $this->apiKeyStorage ) {
			$result = $this->apiKeyStorage->storePrimary( $key );
			return true === $result;
		}

		// Fallback to raw storage (unencrypted) when no storage service.
		$last4 = strlen( $key ) >= 4 ? substr( $key, -4 ) : '';

		$last4Saved = $this->options->set( self::OPTION_API_KEY_LAST4, $last4 );
		$keySaved   = $this->options->set( self::OPTION_API_KEY, $key );

		return $last4Saved && $keySaved;
	}

	/**
	 * Get the decrypted demo API key.
	 *
	 * @return string
	 */
	public function getDemoApiKey(): string {
		if ( $this->apiKeyStorage ) {
			return $this->apiKeyStorage->retrieveDemo();
		}

		return (string) $this->options->get( self::OPTION_DEMO_API_KEY, '' );
	}

	/**
	 * Check if demo mode is enabled.
	 *
	 * @return bool
	 */
	public function isDemoMode(): bool {
		return (bool) $this->get( 'demo_mode', false );
	}

	/**
	 * Get the budget limit.
	 *
	 * @return float
	 */
	public function getBudgetLimit(): float {
		return (float) $this->options->get( self::OPTION_BUDGET_LIMIT, self::DEFAULT_BUDGET_LIMIT );
	}

	/**
	 * Set the budget limit.
	 *
	 * @param float $limit Budget limit.
	 * @return bool
	 */
	public function setBudgetLimit( float $limit ): bool {
		return $this->options->set( self::OPTION_BUDGET_LIMIT, $limit );
	}

	/**
	 * Get draft TTL in minutes.
	 *
	 * @return int
	 */
	public function getDraftTtl(): int {
		return (int) $this->options->get( self::OPTION_DRAFT_TTL, self::DEFAULT_DRAFT_TTL );
	}

	/**
	 * Get memory store limit (max entries).
	 *
	 * @return int
	 */
	public function getMemoryLimit(): int {
		return max( 1, (int) $this->options->get( self::OPTION_MEMORY_LIMIT, self::DEFAULT_MEMORY_LIMIT ) );
	}

	/**
	 * Set memory store limit.
	 *
	 * @param int $limit Max memory entries.
	 * @return bool
	 */
	public function setMemoryLimit( int $limit ): bool {
		return $this->options->set( self::OPTION_MEMORY_LIMIT, max( 1, $limit ) );
	}

	/**
	 * Get memory store TTL in seconds.
	 *
	 * @return int
	 */
	public function getMemoryTtl(): int {
		return max( 60, (int) $this->options->get( self::OPTION_MEMORY_TTL, self::DEFAULT_MEMORY_TTL ) );
	}

	/**
	 * Set memory store TTL in seconds.
	 *
	 * @param int $ttl TTL in seconds.
	 * @return bool
	 */
	public function setMemoryTtl( int $ttl ): bool {
		return $this->options->set( self::OPTION_MEMORY_TTL, max( 60, $ttl ) );
	}

	/**
	 * Get usage stats.
	 *
	 * @return array
	 */
	public function getUsageStats(): array {
		$stats = $this->options->get( self::OPTION_USAGE_STATS, array() );
		$stats = is_array( $stats ) ? $stats : array();

		return array_merge( self::getDefaultUsageStats(), $stats );
	}

	/**
	 * Update usage stats.
	 *
	 * @param array $stats Stats to update.
	 * @return bool
	 */
	public function updateUsageStats( array $stats ): bool {
		$current = $this->getUsageStats();
		$merged  = array_merge( $current, $stats );

		return $this->options->set( self::OPTION_USAGE_STATS, $merged );
	}

	/**
	 * Initialize default options on activation.
	 *
	 * @return void
	 */
	public function initializeDefaults(): void {
		if ( ! $this->options->has( self::OPTION_SETTINGS ) ) {
			$this->options->set( self::OPTION_SETTINGS, self::getDefaults() );
		}

		if ( ! $this->options->has( self::OPTION_USAGE_STATS ) ) {
			$this->options->set( self::OPTION_USAGE_STATS, self::getDefaultUsageStats() );
		}

		if ( ! $this->options->has( self::OPTION_BUDGET_LIMIT ) ) {
			$this->options->set( self::OPTION_BUDGET_LIMIT, self::DEFAULT_BUDGET_LIMIT );
		}

		if ( ! $this->options->has( self::OPTION_DRAFT_TTL ) ) {
			$this->options->set( self::OPTION_DRAFT_TTL, self::DEFAULT_DRAFT_TTL );
		}

		if ( ! $this->options->has( self::OPTION_API_KEY ) ) {
			$this->options->set( self::OPTION_API_KEY, self::DEFAULT_API_KEY );
		}

		if ( ! $this->options->has( self::OPTION_API_KEY_LAST4 ) ) {
			$this->options->set( self::OPTION_API_KEY_LAST4, self::DEFAULT_API_KEY_LAST4 );
		}

		if ( ! $this->options->has( self::OPTION_DEMO_API_KEY ) ) {
			$this->options->set( self::OPTION_DEMO_API_KEY, self::DEFAULT_DEMO_API_KEY );
		}

		if ( ! $this->options->has( self::OPTION_DEMO_API_KEY_LAST4 ) ) {
			$this->options->set( self::OPTION_DEMO_API_KEY_LAST4, self::DEFAULT_DEMO_API_KEY_LAST4 );
		}

		if ( ! $this->options->has( self::OPTION_MEMORY_LIMIT ) ) {
			$this->options->set( self::OPTION_MEMORY_LIMIT, self::DEFAULT_MEMORY_LIMIT );
		}

		if ( ! $this->options->has( self::OPTION_MEMORY_TTL ) ) {
			$this->options->set( self::OPTION_MEMORY_TTL, self::DEFAULT_MEMORY_TTL );
		}
	}

	/**
	 * Get default settings.
	 *
	 * Uses centralized configuration from AgentWPConfig for default model.
	 *
	 * @return array
	 */
	public static function getDefaults(): array {
		return array(
			'model'             => AgentWPConfig::OPENAI_DEFAULT_MODEL,
			'budget_limit'      => 0,
			'draft_ttl_minutes' => 10,
			'hotkey'            => 'Cmd+K / Ctrl+K',
			'theme'             => 'light',
			'demo_mode'         => false,
		);
	}

	/**
	 * Get default usage stats.
	 *
	 * @return array
	 */
	public static function getDefaultUsageStats(): array {
		return array(
			'total_tokens'        => 0,
			'total_cost_usd'      => 0,
			'breakdown_by_intent' => array(),
			'daily_trend'         => array(),
			'period_start'        => '',
			'period_end'          => '',
		);
	}
}
