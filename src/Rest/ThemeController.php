<?php
/**
 * Theme preference REST controller.
 *
 * @package AgentWP
 */

namespace AgentWP\Rest;

use AgentWP\Config\AgentWPConfig;
use AgentWP\DTO\ThemeRequestDTO;
use AgentWP\Rest\RestController;
use WP_REST_Server;

class ThemeController extends RestController {
	const THEME_META_KEY = AgentWPConfig::META_KEY_THEME;

	/**
	 * Valid theme options.
	 */
	private const VALID_THEMES = array( 'light', 'dark' );

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
	 * @openapi GET /agentwp/v1/theme
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request instance.
	 * @return \WP_REST_Response
	 */
	public function get_theme( $request ) {
		unset( $request );
		$user_id = get_current_user_id();
		$theme   = get_user_meta( $user_id, self::THEME_META_KEY, true );

		if ( ! in_array( $theme, self::VALID_THEMES, true ) ) {
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
	 * @openapi POST /agentwp/v1/theme
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request instance.
	 * @return \WP_REST_Response
	 */
	public function update_theme( $request ) {
		$dto = new ThemeRequestDTO( $request );

		if ( ! $dto->isValid() ) {
			$error = $dto->getError();
			return $this->response_error(
				AgentWPConfig::ERROR_CODE_INVALID_REQUEST,
				$error ? $error->get_error_message() : __( 'Invalid request.', 'agentwp' ),
				400
			);
		}

		$theme   = $dto->getTheme();
		$user_id = get_current_user_id();
		update_user_meta( $user_id, self::THEME_META_KEY, $theme );

		return $this->response_success(
			array(
				'updated' => true,
				'theme'   => $theme,
			)
		);
	}
}
