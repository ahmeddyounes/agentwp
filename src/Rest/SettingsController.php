<?php
/**
 * Settings REST controller.
 *
 * @package AgentWP
 */

namespace AgentWP\Rest;

use AgentWP\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class SettingsController {
	/**
	 * REST namespace.
	 */
	const REST_NAMESPACE = 'agentwp/v1';

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
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
					'args'                => array(
						'model'     => array(
							'type' => 'string',
						),
						'budget_limit' => array(
							'type' => 'number',
						),
						'draft_ttl_minutes' => array(
							'type' => 'integer',
						),
						'hotkey'    => array(
							'type' => 'string',
						),
						'theme'     => array(
							'type' => 'string',
						),
						'dark_mode' => array(
							'type' => 'boolean',
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/settings/api-key',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_api_key' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'api_key' => array(
						'type' => 'string',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/usage',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_usage' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'period' => array(
						'type'    => 'string',
						'default' => 'month',
					),
				),
			)
		);
	}

	/**
	 * Permissions check for settings routes.
	 *
	 * @return bool
	 */
	public function permissions_check() {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Get settings payload.
	 *
	 * @param WP_REST_Request $request Request instance.
	 * @return WP_REST_Response
	 */
	public function get_settings( $request ) {
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
	 * @param WP_REST_Request $request Request instance.
	 * @return WP_REST_Response
	 */
	public function update_settings( $request ) {
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
	 * @param WP_REST_Request $request Request instance.
	 * @return WP_REST_Response
	 */
	public function update_api_key( $request ) {
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
			return $this->response_error( 'agentwp_invalid_key', __( 'API key format looks invalid.', 'agentwp' ), 400 );
		}

		$validation = $this->validate_openai_api_key( $api_key );
		if ( is_wp_error( $validation ) ) {
			return $this->response_error( $validation->get_error_code(), $validation->get_error_message(), 400 );
		}

		$encrypted = $this->encrypt_api_key( $api_key );
		if ( is_wp_error( $encrypted ) ) {
			return $this->response_error( $encrypted->get_error_code(), $encrypted->get_error_message(), 500 );
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
	 * @param WP_REST_Request $request Request instance.
	 * @return WP_REST_Response
	 */
	public function get_usage( $request ) {
		$period = $request->get_param( 'period' );
		$period = is_string( $period ) ? sanitize_text_field( $period ) : 'month';

		if ( ! in_array( $period, array( 'day', 'week', 'month' ), true ) ) {
			return $this->response_error( 'agentwp_invalid_period', __( 'Invalid usage period.', 'agentwp' ), 400 );
		}

		$defaults = Plugin::get_default_usage_stats();
		$usage    = get_option( Plugin::OPTION_USAGE_STATS, array() );
		$usage    = is_array( $usage ) ? $usage : array();
		$usage    = wp_parse_args( $usage, $defaults );

		$usage['last_sync'] = gmdate( 'c' );
		update_option( Plugin::OPTION_USAGE_STATS, $usage, false );

		return $this->response_success(
			array(
				'period' => $period,
				'usage'  => $usage,
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
			$dark_mode         = rest_sanitize_boolean( $payload['dark_mode'] );
			$settings['theme'] = $dark_mode ? 'dark' : 'light';
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
		$response = wp_remote_get(
			'https://api.openai.com/v1/models',
			array(
				'timeout'     => 3,
				'redirection' => 0,
				'headers'     => array(
					'Authorization' => 'Bearer ' . $api_key,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'agentwp_openai_unreachable', __( 'OpenAI API is unreachable.', 'agentwp' ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error( 'agentwp_openai_invalid', __( 'OpenAI rejected the API key.', 'agentwp' ) );
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
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return new WP_Error( 'agentwp_encryption_missing', __( 'Encryption is unavailable on this server.', 'agentwp' ) );
		}

		try {
			$nonce = random_bytes( 12 );
		} catch ( \Exception $exception ) {
			return new WP_Error( 'agentwp_encryption_nonce', __( 'Unable to generate encryption nonce.', 'agentwp' ) );
		}

		$key = $this->get_encryption_key();
		$tag = '';

		$ciphertext = openssl_encrypt( $api_key, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag );
		if ( false === $ciphertext ) {
			return new WP_Error( 'agentwp_encryption_failed', __( 'Unable to encrypt the API key.', 'agentwp' ) );
		}

		return base64_encode( $nonce . $tag . $ciphertext );
	}

	/**
	 * Derive encryption key from WordPress salts.
	 *
	 * @return string
	 */
	private function get_encryption_key() {
		$material = '';

		if ( defined( 'LOGGED_IN_KEY' ) ) {
			$material .= LOGGED_IN_KEY;
		}

		if ( defined( 'LOGGED_IN_SALT' ) ) {
			$material .= LOGGED_IN_SALT;
		}

		return hash( 'sha256', $material, true );
	}

	/**
	 * Format success response.
	 *
	 * @param array $data Response data.
	 * @return WP_REST_Response
	 */
	private function response_success( array $data ) {
		$response = rest_ensure_response(
			array(
				'success' => true,
				'data'    => $data,
				'error'   => (object) array(),
			)
		);

		return $response;
	}

	/**
	 * Format error response.
	 *
	 * @param string $code Error code.
	 * @param string $message Error message.
	 * @param int    $status HTTP status.
	 * @return WP_REST_Response
	 */
	private function response_error( $code, $message, $status = 400 ) {
		$response = rest_ensure_response(
			array(
				'success' => false,
				'data'    => array(),
				'error'   => array(
					'code'    => $code,
					'message' => $message,
				),
			)
		);

		if ( $response instanceof WP_REST_Response ) {
			$response->set_status( $status );
		}

		return $response;
	}
}
