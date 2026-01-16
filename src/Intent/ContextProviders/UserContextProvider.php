<?php
/**
 * User context provider.
 *
 * @package AgentWP
 */

namespace AgentWP\Intent\ContextProviders;

/**
 * Provides user context information.
 *
 * Wraps WordPress user functions for testability.
 */
class UserContextProvider implements ContextProviderInterface {
	/**
	 * Provide user context data.
	 *
	 * @param array $context Request context.
	 * @param array $metadata Request metadata.
	 * @return array User context data.
	 */
	public function provide( array $context, array $metadata ): array {
		if ( ! function_exists( 'wp_get_current_user' ) ) {
			return [];
		}

		$user = wp_get_current_user();
		if ( 0 === intval( $user->ID ) ) {
			return [];
		}

		return [
			'id'           => intval( $user->ID ),
			'display_name' => sanitize_text_field( $user->display_name ),
			'email'        => sanitize_email( $user->user_email ),
			'roles'        => is_array( $user->roles ) ? array_values( $user->roles ) : [],
		];
	}
}
