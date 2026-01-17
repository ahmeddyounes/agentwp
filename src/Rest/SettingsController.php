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
use AgentWP\Plugin;
use AgentWP\Security\Encryption;
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

		$this->maybe_rotate_api_key();

		$settings = $this->read_settings();
		$last4    = get_option( Plugin::OPTION_API_KEY_LAST4, '' );
		$has_key  = ! empty( $last4 ) || ! empty( get_option( Plugin::OPTION_API_KEY ) );

		return $this->response_success(
			array(
				'settings'       => $settings,
				'api_key_last4'  => $last4 ? $last4 : '',
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

		update_option( Plugin::OPTION_SETTINGS, $updated, false );

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

		$payload = $request->get_json_params();
		$payload = is_array( $payload ) ? $payload : array();
		$api_key = isset( $payload['api_key'] ) ? sanitize_text_field( wp_unslash( $payload['api_key'] ) ) : '';

		if ( '' === $api_key ) {
			delete_option( Plugin::OPTION_API_KEY );
			delete_option( Plugin::OPTION_API_KEY_LAST4 );

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

		$validation = $this->validate_openai_api_key( $api_key );
		if ( is_wp_error( $validation ) ) {
			return $this->response_error( (string) $validation->get_error_code(), $validation->get_error_message(), 400 );
		}

		$encrypted = $this->encrypt_api_key( $api_key );
		if ( is_wp_error( $encrypted ) ) {
			return $this->response_error( (string) $encrypted->get_error_code(), $encrypted->get_error_message(), 500 );
		}

		update_option( Plugin::OPTION_API_KEY, $encrypted, false );

		$last4 = substr( $api_key, -4 );
		update_option( Plugin::OPTION_API_KEY_LAST4, $last4, false );

		return $this->response_success(
			array(
				'stored' => true,
				'last4'  => $last4,
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
			: Plugin::get_default_usage_stats();

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
	 * Read stored settings with defaults.
	 *
	 * @return array
	 */
	private function read_settings() {
		$settings = get_option( Plugin::OPTION_SETTINGS, array() );
		$settings = is_array( $settings ) ? $settings : array();

		return wp_parse_args( $settings, Plugin::get_default_settings() );
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
	 * Validate API key against OpenAI.
	 *
	 * @param string $api_key API key.
	 * @return true|WP_Error
	 */
	private function validate_openai_api_key( $api_key ) {
		$args = array(
			'timeout'     => 3,
			'redirection' => 0,
			'sslverify'   => true,
			'headers'     => array(
				'Authorization' => 'Bearer ' . $api_key,
			),
		);

		if ( function_exists( 'vip_safe_wp_remote_get' ) ) {
			$response = vip_safe_wp_remote_get( 'https://api.openai.com/v1/models', $args );
		} else {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get -- Fallback outside VIP.
			$response = wp_remote_get( 'https://api.openai.com/v1/models', $args );
		}

		if ( is_wp_error( $response ) ) {
			return new WP_Error( AgentWPConfig::ERROR_CODE_OPENAI_UNREACHABLE, __( 'OpenAI API is unreachable.', 'agentwp' ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error( AgentWPConfig::ERROR_CODE_OPENAI_INVALID, __( 'OpenAI rejected the API key.', 'agentwp' ) );
		}

		return true;
	}

	/**
	 * Encrypt API key at rest.
	 *
	 * @param string $api_key API key.
	 * @return string|WP_Error
	 */
	private function encrypt_api_key( $api_key ) {
		$encryption = new Encryption();
		$encrypted  = $encryption->encrypt( $api_key );

		if ( '' === $encrypted ) {
			return new WP_Error( AgentWPConfig::ERROR_CODE_ENCRYPTION_FAILED, __( 'Unable to encrypt the API key.', 'agentwp' ) );
		}

		return $encrypted;
	}

	/**
	 * Re-encrypt stored API key with current salts when needed.
	 *
	 * @return void
	 */
	private function maybe_rotate_api_key() {
		$stored = get_option( Plugin::OPTION_API_KEY, '' );
		if ( '' === $stored ) {
			return;
		}

		$encryption = new Encryption();
		$rotated    = $encryption->rotate( $stored );

		if ( '' === $rotated || $rotated === $stored ) {
			return;
		}

		update_option( Plugin::OPTION_API_KEY, $rotated, false );
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
