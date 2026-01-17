<?php
/**
 * Settings REST controller.
 *
 * @package AgentWP
 */

namespace AgentWP\Rest;

use AgentWP\API\RestController;
use AgentWP\Billing\UsageTracker;
use AgentWP\Config\AgentWPConfig;
use AgentWP\Contracts\OpenAIKeyValidatorInterface;
use AgentWP\Plugin\SettingsManager;
use AgentWP\Security\ApiKeyStorage;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class SettingsController extends RestController {

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/settings/api-key',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_api_key' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/usage',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_usage' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);
	}

	/**
	 * Get settings payload.
	 *
	 * @openapi GET /agentwp/v1/settings
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request instance.
	 * @return WP_REST_Response
	 */
	public function get_settings( $request ) {
		unset( $request );

		$storage = $this->getApiKeyStorage();
		if ( $storage ) {
			$storage->rotatePrimary();
		}

		$settings = $this->read_settings();
		$last4    = $storage ? $storage->getPrimaryLast4() : '';
		$has_key  = $storage ? $storage->hasPrimaryKey() : false;

		return $this->response_success(
			array(
				'settings'       => $settings,
				'api_key_last4'  => $last4,
				'has_api_key'    => $has_key,
				'api_key_status' => $has_key ? 'stored' : 'missing',
			)
		);
	}

	/**
	 * Update settings payload.
	 *
	 * @openapi POST /agentwp/v1/settings
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request instance.
	 * @return WP_REST_Response
	 */
	public function update_settings( $request ) {
		$validation = $this->validate_request( $request, $this->get_settings_update_schema() );
		if ( is_wp_error( $validation ) ) {
			return $this->response_error( AgentWPConfig::ERROR_CODE_INVALID_REQUEST, $validation->get_error_message(), 400 );
		}

		$payload  = $request->get_json_params();
		$payload  = is_array( $payload ) ? $payload : array();
		$settings = $this->read_settings();
		$updated  = $this->apply_settings_updates( $settings, $payload );

		$settings_manager = $this->getSettingsManager();
		if ( $settings_manager ) {
			$settings_manager->update( $updated );
		} else {
			update_option( SettingsManager::OPTION_SETTINGS, $updated, false );
		}

		return $this->response_success(
			array(
				'updated'  => true,
				'settings' => $updated,
			)
		);
	}

	/**
	 * Validate and store API key.
	 *
	 * @openapi POST /agentwp/v1/settings/api-key
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request instance.
	 * @return WP_REST_Response
	 */
	public function update_api_key( $request ) {
		$validation = $this->validate_request( $request, $this->get_api_key_schema() );
		if ( is_wp_error( $validation ) ) {
			return $this->response_error( AgentWPConfig::ERROR_CODE_INVALID_REQUEST, $validation->get_error_message(), 400 );
		}

		$storage = $this->getApiKeyStorage();
		if ( ! $storage ) {
			return $this->response_error( AgentWPConfig::ERROR_CODE_ENCRYPTION_FAILED, __( 'API key storage service unavailable.', 'agentwp' ), 500 );
		}

		$payload = $request->get_json_params();
		$payload = is_array( $payload ) ? $payload : array();
		$api_key = isset( $payload['api_key'] ) ? sanitize_text_field( wp_unslash( $payload['api_key'] ) ) : '';

		if ( '' === $api_key ) {
			$storage->deletePrimary();

			return $this->response_success(
				array(
					'stored' => false,
					'last4'  => '',
				)
			);
		}

		if ( 0 !== strpos( $api_key, 'sk-' ) ) {
			return $this->response_error( AgentWPConfig::ERROR_CODE_INVALID_KEY, __( 'API key format looks invalid.', 'agentwp' ), 400 );
		}

		$validator = $this->getOpenAIKeyValidator();
		if ( $validator ) {
			$openai_validation = $validator->validate( $api_key );
			if ( is_wp_error( $openai_validation ) ) {
				return $this->response_error( (string) $openai_validation->get_error_code(), $openai_validation->get_error_message(), 400 );
			}
		}

		$result = $storage->storePrimary( $api_key );
		if ( is_wp_error( $result ) ) {
			return $this->response_error( AgentWPConfig::ERROR_CODE_ENCRYPTION_FAILED, $result->get_error_message(), 500 );
		}

		return $this->response_success(
			array(
				'stored' => true,
				'last4'  => $storage->extractLast4( $api_key ),
			)
		);
	}

	/**
	 * Get usage statistics.
	 *
	 * @openapi GET /agentwp/v1/usage
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request instance.
	 * @return WP_REST_Response
	 */
	public function get_usage( $request ) {
		$validation = $this->validate_request( $request, $this->get_usage_schema(), 'query' );
		if ( is_wp_error( $validation ) ) {
			return $this->response_error( AgentWPConfig::ERROR_CODE_INVALID_REQUEST, $validation->get_error_message(), 400 );
		}

		$period = $request->get_param( 'period' );
		$period = is_string( $period ) ? sanitize_text_field( $period ) : 'month';

		if ( ! in_array( $period, array( 'day', 'week', 'month' ), true ) ) {
			return $this->response_error( AgentWPConfig::ERROR_CODE_INVALID_PERIOD, __( 'Invalid usage period.', 'agentwp' ), 400 );
		}

		$usage = class_exists( 'AgentWP\\Billing\\UsageTracker' )
			? UsageTracker::get_usage_summary( $period )
			: SettingsManager::getDefaultUsageStats();

		return $this->response_success(
			array(
				'period'              => $period,
				'total_tokens'        => isset( $usage['total_tokens'] ) ? $usage['total_tokens'] : 0,
				'total_cost_usd'      => isset( $usage['total_cost_usd'] ) ? $usage['total_cost_usd'] : 0,
				'breakdown_by_intent' => isset( $usage['breakdown_by_intent'] ) ? $usage['breakdown_by_intent'] : array(),
				'daily_trend'         => isset( $usage['daily_trend'] ) ? $usage['daily_trend'] : array(),
				'period_start'        => isset( $usage['period_start'] ) ? $usage['period_start'] : '',
				'period_end'          => isset( $usage['period_end'] ) ? $usage['period_end'] : '',
			)
		);
	}

	/**
	 * Read stored settings with defaults via SettingsManager.
	 *
	 * @return array
	 */
	private function read_settings(): array {
		$settings_manager = $this->getSettingsManager();
		if ( $settings_manager ) {
			return $settings_manager->getAll();
		}

		// Fallback to static defaults if container not available.
		return SettingsManager::getDefaults();
	}

	/**
	 * Apply settings updates with sanitization.
	 *
	 * @param array $settings Existing settings.
	 * @param array $payload Raw payload.
	 * @return array
	 */
	private function apply_settings_updates( array $settings, array $payload ) {
		if ( isset( $payload['model'] ) ) {
			$model = sanitize_text_field( wp_unslash( $payload['model'] ) );
			if ( in_array( $model, array( 'gpt-4o', 'gpt-4o-mini' ), true ) ) {
				$settings['model'] = $model;
			}
		}

		if ( isset( $payload['budget_limit'] ) ) {
			$budget_limit = floatval( $payload['budget_limit'] );
			if ( $budget_limit >= 0 ) {
				$settings['budget_limit'] = $budget_limit;
			}
		}

		if ( isset( $payload['draft_ttl_minutes'] ) ) {
			$draft_ttl = intval( $payload['draft_ttl_minutes'] );
			if ( $draft_ttl >= 0 ) {
				$settings['draft_ttl_minutes'] = $draft_ttl;
			}
		}

		if ( isset( $payload['hotkey'] ) ) {
			$hotkey = sanitize_text_field( wp_unslash( $payload['hotkey'] ) );
			if ( '' !== $hotkey ) {
				$settings['hotkey'] = $hotkey;
			}
		}

		if ( isset( $payload['theme'] ) ) {
			$theme = sanitize_text_field( wp_unslash( $payload['theme'] ) );
			if ( in_array( $theme, array( 'light', 'dark' ), true ) ) {
				$settings['theme'] = $theme;
			}
		}

		if ( array_key_exists( 'dark_mode', $payload ) ) {
			$dark_mode_raw = $payload['dark_mode'];
			$dark_mode     = ( is_bool( $dark_mode_raw ) || is_int( $dark_mode_raw ) || is_string( $dark_mode_raw ) )
				? rest_sanitize_boolean( $dark_mode_raw )
				: false;
			$settings['theme'] = $dark_mode ? 'dark' : 'light';
		}

		if ( array_key_exists( 'demo_mode', $payload ) ) {
			$demo_mode_raw       = $payload['demo_mode'];
			$settings['demo_mode'] = ( is_bool( $demo_mode_raw ) || is_int( $demo_mode_raw ) || is_string( $demo_mode_raw ) )
				? rest_sanitize_boolean( $demo_mode_raw )
				: false;
		}

		return $settings;
	}

	/**
	 * Get the SettingsManager service from the container.
	 *
	 * @return SettingsManager|null
	 */
	private function getSettingsManager(): ?SettingsManager {
		$manager = $this->resolve( SettingsManager::class );
		return $manager instanceof SettingsManager ? $manager : null;
	}

	/**
	 * Get the ApiKeyStorage service from the container.
	 *
	 * @return ApiKeyStorage|null
	 */
	private function getApiKeyStorage(): ?ApiKeyStorage {
		$storage = $this->resolve( ApiKeyStorage::class );
		return $storage instanceof ApiKeyStorage ? $storage : null;
	}

	/**
	 * Get the OpenAI key validator service from the container.
	 *
	 * @return OpenAIKeyValidatorInterface|null
	 */
	private function getOpenAIKeyValidator(): ?OpenAIKeyValidatorInterface {
		$validator = $this->resolve( OpenAIKeyValidatorInterface::class );
		return $validator instanceof OpenAIKeyValidatorInterface ? $validator : null;
	}

	/**
	 * Schema for settings update payload.
	 *
	 * @return array
	 */
	private function get_settings_update_schema() {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'model'             => array(
					'type' => 'string',
					'enum' => array( 'gpt-4o', 'gpt-4o-mini' ),
				),
				'budget_limit'      => array(
					'type'    => 'number',
					'minimum' => 0,
					'maximum' => 100000, // Reasonable maximum budget limit.
				),
				'draft_ttl_minutes' => array(
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => 10080, // Maximum 7 days (60 * 24 * 7).
				),
				'hotkey'            => array(
					'type'      => 'string',
					'maxLength' => 50, // Reasonable keyboard shortcut length.
				),
				'theme'             => array(
					'type' => 'string',
					'enum' => array( 'light', 'dark' ),
				),
				'dark_mode'         => array(
					'type' => 'boolean',
				),
				'demo_mode'         => array(
					'type' => 'boolean',
				),
			),
		);
	}

	/**
	 * Schema for API key payload.
	 *
	 * @return array
	 */
	private function get_api_key_schema() {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'api_key' => array(
					'type'      => 'string',
					'minLength' => 20,  // OpenAI API keys are at least 20 chars.
					'maxLength' => 256, // Reasonable maximum for API keys.
				),
			),
		);
	}

	/**
	 * Schema for usage query params.
	 *
	 * @return array
	 */
	private function get_usage_schema() {
		return array(
			'type'       => 'object',
			'properties'           => array(
				'period' => array(
					'type' => 'string',
					'enum' => array( 'day', 'week', 'month' ),
				),
			),
		);
	}
}
