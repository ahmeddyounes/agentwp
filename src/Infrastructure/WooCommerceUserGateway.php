<?php
/**
 * WooCommerce user gateway implementation.
 *
 * @package AgentWP\Infrastructure
 */

namespace AgentWP\Infrastructure;

use AgentWP\Contracts\WooCommerceUserGatewayInterface;

/**
 * Wraps WordPress user functions.
 */
final class WooCommerceUserGateway implements WooCommerceUserGatewayInterface {

	/**
	 * {@inheritDoc}
	 */
	public function get_user( int $user_id ): ?array {
		if ( $user_id <= 0 ) {
			return null;
		}

		if ( ! function_exists( 'get_userdata' ) ) {
			return null;
		}

		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return null;
		}

		return array(
			'id'           => $user->ID,
			'email'        => isset( $user->user_email ) ? sanitize_email( $user->user_email ) : '',
			'first_name'   => isset( $user->first_name ) ? sanitize_text_field( $user->first_name ) : '',
			'last_name'    => isset( $user->last_name ) ? sanitize_text_field( $user->last_name ) : '',
			'display_name' => isset( $user->display_name ) ? sanitize_text_field( $user->display_name ) : '',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_user_email( int $user_id ): ?string {
		$user = $this->get_user( $user_id );

		if ( null === $user || '' === $user['email'] ) {
			return null;
		}

		return $user['email'];
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_user_display_name( int $user_id ): ?string {
		$user = $this->get_user( $user_id );

		if ( null === $user ) {
			return null;
		}

		$name = trim( $user['first_name'] . ' ' . $user['last_name'] );

		if ( '' === $name ) {
			$name = $user['display_name'];
		}

		return '' !== $name ? $name : null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_current_timestamp(): int {
		if ( function_exists( 'current_datetime' ) ) {
			return current_datetime()->getTimestamp();
		}

		return time();
	}
}
