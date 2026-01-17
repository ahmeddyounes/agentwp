<?php
/**
 * WooCommerce price formatter implementation.
 *
 * @package AgentWP\Infrastructure
 */

namespace AgentWP\Infrastructure;

use AgentWP\Contracts\WooCommercePriceFormatterInterface;

/**
 * Wraps WooCommerce price formatting functions.
 */
final class WooCommercePriceFormatter implements WooCommercePriceFormatterInterface {

	/**
	 * {@inheritDoc}
	 */
	public function format_price( float $amount ): string {
		$normalized = $this->normalize_decimal( $amount, $this->get_price_decimals() );

		if ( function_exists( 'wc_price' ) ) {
			return wc_price( $normalized );
		}

		return number_format( $normalized, $this->get_price_decimals(), '.', '' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function normalize_decimal( mixed $value, int $decimals = 2 ): float {
		if ( function_exists( 'wc_format_decimal' ) ) {
			return (float) wc_format_decimal( $value, $decimals );
		}

		return round( (float) $value, $decimals );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_price_decimals(): int {
		if ( function_exists( 'wc_get_price_decimals' ) ) {
			return (int) wc_get_price_decimals();
		}

		return 2;
	}
}
