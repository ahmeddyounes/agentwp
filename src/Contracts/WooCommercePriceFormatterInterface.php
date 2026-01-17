<?php
/**
 * WooCommerce price formatter interface.
 *
 * @package AgentWP\Contracts
 */

namespace AgentWP\Contracts;

/**
 * Contract for WooCommerce price formatting operations.
 *
 * Abstracts wc_price(), wc_format_decimal() and related functions.
 */
interface WooCommercePriceFormatterInterface {

	/**
	 * Format a price value.
	 *
	 * @param float $amount The amount to format.
	 * @return string Formatted price string with currency.
	 */
	public function format_price( float $amount ): string;

	/**
	 * Normalize a decimal value.
	 *
	 * @param mixed $value  Value to normalize.
	 * @param int   $decimals Number of decimal places.
	 * @return float Normalized decimal value.
	 */
	public function normalize_decimal( mixed $value, int $decimals = 2 ): float;

	/**
	 * Get the number of decimal places for prices.
	 *
	 * @return int Number of decimals.
	 */
	public function get_price_decimals(): int;
}
