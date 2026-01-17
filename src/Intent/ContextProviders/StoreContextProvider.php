<?php
/**
 * Store context provider.
 *
 * @package AgentWP
 */

namespace AgentWP\Intent\ContextProviders;

/**
 * Provides store context information.
 *
 * Wraps WordPress/WooCommerce settings functions for testability.
 */
class StoreContextProvider implements ContextProviderInterface {
	/**
	 * Provide store context data.
	 *
	 * @param array $context Request context.
	 * @param array $metadata Request metadata.
	 * @return array Store context data.
	 */
	public function provide( array $context, array $metadata ): array {
		unset( $context, $metadata );

		$timezone = $this->get_timezone();
		$currency = $this->get_currency();

		return [
			'timezone' => sanitize_text_field( $timezone ),
			'currency' => sanitize_text_field( $currency ),
		];
	}

	/**
	 * Get store timezone.
	 *
	 * @return string Timezone string.
	 */
	private function get_timezone(): string {
		$timezone = '';

		if ( function_exists( 'wp_timezone_string' ) ) {
			$timezone = wp_timezone_string();
		}

		if ( '' === $timezone && function_exists( 'get_option' ) ) {
			$timezone = (string) get_option( 'timezone_string' );
		}

		if ( '' === $timezone && function_exists( 'get_option' ) ) {
			$offset   = (float) get_option( 'gmt_offset' );
			$sign     = $offset >= 0 ? '+' : '-';
			$timezone = sprintf( 'UTC%s%s', $sign, abs( $offset ) );
		}

		return $timezone;
	}

	/**
	 * Get store currency.
	 *
	 * @return string Currency code.
	 */
	private function get_currency(): string {
		$currency = '';

		if ( function_exists( 'get_woocommerce_currency' ) ) {
			$currency = get_woocommerce_currency();
		} elseif ( function_exists( 'get_option' ) ) {
			$currency = (string) get_option( 'woocommerce_currency' );
		}

		return $currency;
	}
}
