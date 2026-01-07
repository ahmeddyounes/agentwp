<?php
/**
 * Theme preference REST controller.
 *
 * @package AgentWP
 */

namespace AgentWP\API;

use WP_REST_Server;

class ThemeController extends RestController {
	const THEME_META_KEY = 'agentwp_theme_preference';

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/theme',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_theme' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_theme' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);
	}

	/**
	 * Return stored theme preference.
	 *
	 * @param \WP_REST_Request $request Request instance.
	 * @return \WP_REST_Response
	 */
	public function get_theme( $request ) {
		$user_id = get_current_user_id();
		$theme   = get_user_meta( $user_id, self::THEME_META_KEY, true );

		if ( ! in_array( $theme, array( 'light', 'dark' ), true ) ) {
			$theme = '';
		}

		return $this->response_success(
			array(
				'theme' => $theme,
			)
		);
	}

	/**
	 * Update stored theme preference.
	 *
	 * @param \WP_REST_Request $request Request instance.
	 * @return \WP_REST_Response
	 */
	public function update_theme( $request ) {
		$validation = $this->validate_request( $request, $this->get_theme_schema() );
		if ( is_wp_error( $validation ) ) {
			return $this->response_error( 'agentwp_invalid_request', $validation->get_error_message(), 400 );
		}

		$payload = $request->get_json_params();
		$payload = is_array( $payload ) ? $payload : array();
		$theme   = isset( $payload['theme'] ) ? sanitize_text_field( wp_unslash( $payload['theme'] ) ) : '';

		if ( ! in_array( $theme, array( 'light', 'dark' ), true ) ) {
			return $this->response_error(
				'agentwp_invalid_theme',
				__( 'Theme preference must be light or dark.', 'agentwp' ),
				400
			);
		}

		$user_id = get_current_user_id();
		update_user_meta( $user_id, self::THEME_META_KEY, $theme );

		return $this->response_success(
			array(
				'updated' => true,
				'theme'   => $theme,
			)
		);
	}

	/**
	 * Schema for theme payload.
	 *
	 * @return array
	 */
	private function get_theme_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'theme' => array(
					'type' => 'string',
					'enum' => array( 'light', 'dark' ),
				),
			),
			'required'   => array( 'theme' ),
		);
	}
}
