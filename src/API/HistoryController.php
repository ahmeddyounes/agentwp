<?php
/**
 * History REST controller.
 *
 * @package AgentWP
 */

namespace AgentWP\API;

use AgentWP\Config\AgentWPConfig;
use WP_REST_Server;

class HistoryController extends RestController {
	const HISTORY_META_KEY   = AgentWPConfig::META_KEY_HISTORY;
	const FAVORITES_META_KEY = AgentWPConfig::META_KEY_FAVORITES;
	const HISTORY_LIMIT      = 50;
	const FAVORITES_LIMIT    = 50;

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
		$user_id  = get_current_user_id();
		$history  = get_user_meta( $user_id, self::HISTORY_META_KEY, true );
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
		$validation = $this->validate_request( $request, $this->get_history_schema() );
		if ( is_wp_error( $validation ) ) {
			return $this->response_error( AgentWPConfig::ERROR_CODE_INVALID_REQUEST, $validation->get_error_message(), 400 );
		}

		$payload   = $request->get_json_params();
		$payload   = is_array( $payload ) ? $payload : array();
		$history   = isset( $payload['history'] ) ? $payload['history'] : array();
		$favorites = isset( $payload['favorites'] ) ? $payload['favorites'] : array();

		$history   = $this->sanitize_entries( $history, self::HISTORY_LIMIT );
		$favorites = $this->sanitize_entries( $favorites, self::FAVORITES_LIMIT );

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

	/**
	 * Schema for history payload.
	 *
	 * @return array
	 */
	private function get_history_schema() {
		$entry_schema = array(
			'type'       => 'object',
			'properties' => array(
				'raw_input'      => array(
					'type' => 'string',
				),
				'parsed_intent'  => array(
					'type' => 'string',
				),
				'timestamp'      => array(
					'type' => 'string',
				),
				'was_successful' => array(
					'type' => 'boolean',
				),
			),
		);

		return array(
			'type'       => 'object',
			'properties' => array(
				'history'   => array(
					'type'  => 'array',
					'items' => $entry_schema,
				),
				'favorites' => array(
					'type'  => 'array',
					'items' => $entry_schema,
				),
			),
		);
	}

	/**
	 * Normalize stored entries.
	 *
	 * @param array $entries History entries.
	 * @param int   $limit Max entries to store.
	 * @return array
	 */
	private function sanitize_entries( $entries, $limit ) {
		$entries    = is_array( $entries ) ? $entries : array();
		$normalized = array();

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$raw_input = isset( $entry['raw_input'] ) ? sanitize_text_field( wp_unslash( $entry['raw_input'] ) ) : '';
			if ( '' === $raw_input ) {
				continue;
			}

			$parsed_intent = isset( $entry['parsed_intent'] ) ? sanitize_text_field( wp_unslash( $entry['parsed_intent'] ) ) : '';
			$timestamp     = isset( $entry['timestamp'] ) ? sanitize_text_field( wp_unslash( $entry['timestamp'] ) ) : '';
			$timestamp     = '' !== $timestamp ? $timestamp : gmdate( 'c' );
			$was_successful = isset( $entry['was_successful'] ) ? (bool) $entry['was_successful'] : false;

			$normalized[] = array(
				'raw_input'      => $raw_input,
				'parsed_intent'  => $parsed_intent,
				'timestamp'      => $timestamp,
				'was_successful' => $was_successful,
			);
		}

		if ( $limit > 0 && count( $normalized ) > $limit ) {
			$normalized = array_slice( $normalized, 0, $limit );
		}

		return $normalized;
	}
}
