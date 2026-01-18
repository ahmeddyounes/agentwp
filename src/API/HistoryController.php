<?php
/**
 * History REST controller.
 *
 * @package AgentWP
 */

namespace AgentWP\API;

use AgentWP\Config\AgentWPConfig;
use AgentWP\DTO\HistoryRequestDTO;
use WP_REST_Server;

class HistoryController extends RestController {
	const HISTORY_META_KEY   = AgentWPConfig::META_KEY_HISTORY;
	const FAVORITES_META_KEY = AgentWPConfig::META_KEY_FAVORITES;

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/history',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_history' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_history' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);
	}

	/**
	 * Return stored history and favorites.
	 *
	 * @openapi GET /agentwp/v1/history
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request instance.
	 * @return \WP_REST_Response
	 */
	public function get_history( $request ) {
		unset( $request );
		$user_id   = get_current_user_id();
		$history   = get_user_meta( $user_id, self::HISTORY_META_KEY, true );
		$favorites = get_user_meta( $user_id, self::FAVORITES_META_KEY, true );

		$history   = is_array( $history ) ? $history : array();
		$favorites = is_array( $favorites ) ? $favorites : array();

		return $this->response_success(
			array(
				'history'   => $history,
				'favorites' => $favorites,
			)
		);
	}

	/**
	 * Store updated history payload.
	 *
	 * @openapi POST /agentwp/v1/history
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request instance.
	 * @return \WP_REST_Response
	 */
	public function update_history( $request ) {
		$dto = new HistoryRequestDTO( $request );

		if ( ! $dto->isValid() ) {
			$error = $dto->getError();
			return $this->response_error(
				AgentWPConfig::ERROR_CODE_INVALID_REQUEST,
				$error ? $error->get_error_message() : __( 'Invalid request.', 'agentwp' ),
				400
			);
		}

		$history   = $dto->getHistory();
		$favorites = $dto->getFavorites();

		$user_id = get_current_user_id();
		update_user_meta( $user_id, self::HISTORY_META_KEY, $history );
		update_user_meta( $user_id, self::FAVORITES_META_KEY, $favorites );

		return $this->response_success(
			array(
				'updated'   => true,
				'history'   => $history,
				'favorites' => $favorites,
			)
		);
	}
}
