<?php
/**
 * Centralized configuration for AgentWP.
 *
 * @package AgentWP\Config
 */

namespace AgentWP\Config;

/**
 * Central configuration class for all AgentWP settings.
 *
 * This class consolidates magic numbers and configuration values
 * that were previously scattered across multiple classes.
 */
final class AgentWPConfig {

	/**
	 * Agentic loop settings.
	 */
	public const AGENTIC_MAX_TURNS = 5;

	/**
	 * Intent classification weights.
	 * These weights determine how strongly different intents are scored
	 * during intent classification. Higher values mean stronger bias.
	 */
	public const INTENT_WEIGHT_ORDER_SEARCH    = 1.0;
	public const INTENT_WEIGHT_ORDER_REFUND    = 1.0;
	public const INTENT_WEIGHT_ORDER_STATUS    = 1.0;
	public const INTENT_WEIGHT_PRODUCT_STOCK   = 1.0;
	public const INTENT_WEIGHT_EMAIL_DRAFT     = 1.0;
	public const INTENT_WEIGHT_ANALYTICS_QUERY = 1.0;
	public const INTENT_WEIGHT_CUSTOMER_LOOKUP = 1.0;

	/**
	 * Customer health score weights.
	 * These weights determine the importance of different factors
	 * when calculating customer health scores.
	 */
	public const HEALTH_WEIGHT_RECENCY   = 0.5;  // Order recency influence
	public const HEALTH_WEIGHT_FREQUENCY = 0.3;  // Order frequency influence
	public const HEALTH_WEIGHT_VALUE     = 0.2;  // Order value influence

	/**
	 * Intent classification thresholds.
	 * Minimum confidence scores for automatic intent classification.
	 */
	public const CONFIDENCE_THRESHOLD_HIGH   = 0.85; // High confidence threshold
	public const CONFIDENCE_THRESHOLD_MEDIUM = 0.70; // Medium confidence threshold
	public const CONFIDENCE_THRESHOLD_LOW    = 0.55; // Low confidence threshold

	/**
	 * Intent similarity threshold.
	 * Minimum similarity score for intent matching.
	 */
	public const INTENT_SIMILARITY_THRESHOLD = 0.6;

	/**
	 * Cache TTL settings (in seconds).
	 */
	public const CACHE_TTL_DEFAULT      = 3600; // 1 hour
	public const CACHE_TTL_SHORT        = 300;  // 5 minutes
	public const CACHE_TTL_DRAFT        = 3600; // 1 hour for draft storage

	/**
	 * Order search settings.
	 */
	public const ORDER_SEARCH_DEFAULT_LIMIT = 10;
	public const ORDER_SEARCH_MAX_LIMIT     = 50;

	/**
	 * Customer service settings.
	 */
	public const CUSTOMER_RECENT_LIMIT  = 5;
	public const CUSTOMER_TOP_LIMIT     = 5;
	public const CUSTOMER_ORDER_BATCH   = 200;
	public const CUSTOMER_MAX_ORDER_IDS = 2000;

	/**
	 * Health status thresholds (in days).
	 */
	public const HEALTH_ACTIVE_DAYS  = 60;
	public const HEALTH_AT_RISK_DAYS = 180;

	/**
	 * API client settings.
	 */
	public const API_TIMEOUT_DEFAULT = 60;
	public const API_TIMEOUT_MIN     = 1;
	public const API_TIMEOUT_MAX     = 300;
	public const API_MAX_RETRIES     = 10;
	public const API_INITIAL_DELAY   = 1;
	public const API_MAX_DELAY       = 60;

	/**
	 * Stream response limits.
	 */
	public const STREAM_MAX_CONTENT_LENGTH    = 1048576; // 1MB
	public const STREAM_MAX_TOOL_CALLS        = 50;
	public const STREAM_MAX_RAW_CHUNKS        = 100;
	public const STREAM_MAX_TOOL_ARG_LENGTH   = 102400;  // 100KB

	/**
	 * Order status update limits.
	 */
	public const ORDER_STATUS_MAX_BULK = 50;

	/**
	 * Prevent instantiation.
	 */
	private function __construct() {}

	/**
	 * Get a configuration value with optional filter support.
	 *
	 * @param string $key     Configuration key.
	 * @param mixed  $default Default value if key not found.
	 * @return mixed Configuration value.
	 */
	public static function get( string $key, $default = null ) {
		$map = array(
			'agentic.max_turns'              => self::AGENTIC_MAX_TURNS,
			'cache.ttl.default'              => self::CACHE_TTL_DEFAULT,
			'cache.ttl.short'                => self::CACHE_TTL_SHORT,
			'cache.ttl.draft'                => self::CACHE_TTL_DRAFT,
			'order_search.default_limit'     => self::ORDER_SEARCH_DEFAULT_LIMIT,
			'order_search.max_limit'         => self::ORDER_SEARCH_MAX_LIMIT,
			'customer.recent_limit'          => self::CUSTOMER_RECENT_LIMIT,
			'customer.top_limit'             => self::CUSTOMER_TOP_LIMIT,
			'customer.order_batch'           => self::CUSTOMER_ORDER_BATCH,
			'customer.max_order_ids'         => self::CUSTOMER_MAX_ORDER_IDS,
			'health.active_days'             => self::HEALTH_ACTIVE_DAYS,
			'health.at_risk_days'            => self::HEALTH_AT_RISK_DAYS,
			'api.timeout.default'            => self::API_TIMEOUT_DEFAULT,
			'api.timeout.min'                => self::API_TIMEOUT_MIN,
			'api.timeout.max'                => self::API_TIMEOUT_MAX,
			'api.max_retries'                => self::API_MAX_RETRIES,
			'api.initial_delay'              => self::API_INITIAL_DELAY,
			'api.max_delay'                  => self::API_MAX_DELAY,
			'order_status.max_bulk'          => self::ORDER_STATUS_MAX_BULK,
			// Intent classification weights.
			'intent.weight.order_search'     => self::INTENT_WEIGHT_ORDER_SEARCH,
			'intent.weight.order_refund'     => self::INTENT_WEIGHT_ORDER_REFUND,
			'intent.weight.order_status'     => self::INTENT_WEIGHT_ORDER_STATUS,
			'intent.weight.product_stock'    => self::INTENT_WEIGHT_PRODUCT_STOCK,
			'intent.weight.email_draft'      => self::INTENT_WEIGHT_EMAIL_DRAFT,
			'intent.weight.analytics_query'  => self::INTENT_WEIGHT_ANALYTICS_QUERY,
			'intent.weight.customer_lookup'  => self::INTENT_WEIGHT_CUSTOMER_LOOKUP,
			// Customer health weights.
			'health.weight.recency'          => self::HEALTH_WEIGHT_RECENCY,
			'health.weight.frequency'        => self::HEALTH_WEIGHT_FREQUENCY,
			'health.weight.value'            => self::HEALTH_WEIGHT_VALUE,
			// Classification thresholds.
			'confidence.threshold.high'      => self::CONFIDENCE_THRESHOLD_HIGH,
			'confidence.threshold.medium'    => self::CONFIDENCE_THRESHOLD_MEDIUM,
			'confidence.threshold.low'       => self::CONFIDENCE_THRESHOLD_LOW,
			'intent.similarity_threshold'    => self::INTENT_SIMILARITY_THRESHOLD,
		);

		$value = isset( $map[ $key ] ) ? $map[ $key ] : $default;

		// Allow filtering of configuration values.
		if ( function_exists( 'apply_filters' ) ) {
			$value = apply_filters( 'agentwp_config_' . str_replace( '.', '_', $key ), $value );
		}

		return $value;
	}
}
