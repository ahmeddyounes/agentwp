<?php
/**
 * Intent categories.
 *
 * @package AgentWP
 */

namespace AgentWP\Intent;

class Intent {
	const ORDER_SEARCH    = 'ORDER_SEARCH';
	const ORDER_REFUND    = 'ORDER_REFUND';
	const ORDER_STATUS    = 'ORDER_STATUS';
	const PRODUCT_STOCK   = 'PRODUCT_STOCK';
	const EMAIL_DRAFT     = 'EMAIL_DRAFT';
	const ANALYTICS_QUERY = 'ANALYTICS_QUERY';
	const CUSTOMER_LOOKUP = 'CUSTOMER_LOOKUP';
	const UNKNOWN         = 'UNKNOWN';

	/**
	 * @return array
	 */
	public static function all() {
		return array(
			self::ORDER_SEARCH,
			self::ORDER_REFUND,
			self::ORDER_STATUS,
			self::PRODUCT_STOCK,
			self::EMAIL_DRAFT,
			self::ANALYTICS_QUERY,
			self::CUSTOMER_LOOKUP,
		);
	}

	/**
	 * @return array
	 */
	public static function labels() {
		return array(
			self::ORDER_SEARCH    => 'Order search',
			self::ORDER_REFUND    => 'Order refund',
			self::ORDER_STATUS    => 'Order status',
			self::PRODUCT_STOCK   => 'Product stock',
			self::EMAIL_DRAFT     => 'Email draft',
			self::ANALYTICS_QUERY => 'Analytics query',
			self::CUSTOMER_LOOKUP => 'Customer lookup',
		);
	}

	/**
	 * @return array
	 */
	public static function suggestions() {
		return array(
			array(
				'intent'  => self::ORDER_SEARCH,
				'label'   => 'Search for an order',
				'example' => 'Find the last order from jane@example.com.',
			),
			array(
				'intent'  => self::ORDER_REFUND,
				'label'   => 'Prepare a refund',
				'example' => 'Refund order 1452 for $25.',
			),
			array(
				'intent'  => self::ORDER_STATUS,
				'label'   => 'Check or update order status',
				'example' => 'What is the status of order 1452?',
			),
			array(
				'intent'  => self::PRODUCT_STOCK,
				'label'   => 'Check product stock',
				'example' => 'How many units of the blue hoodie are in stock?',
			),
			array(
				'intent'  => self::EMAIL_DRAFT,
				'label'   => 'Draft a customer email',
				'example' => 'Draft an apology email for order 1452.',
			),
			array(
				'intent'  => self::ANALYTICS_QUERY,
				'label'   => 'Run an analytics report',
				'example' => 'Show sales performance for last week.',
			),
			array(
				'intent'  => self::CUSTOMER_LOOKUP,
				'label'   => 'Look up a customer profile',
				'example' => 'Pull the profile for mary@example.com.',
			),
		);
	}

	/**
	 * @param string $intent Intent value.
	 * @return string
	 */
	public static function normalize( $intent ) {
		$intent = is_string( $intent ) ? strtoupper( trim( $intent ) ) : '';
		if ( in_array( $intent, self::all(), true ) ) {
			return $intent;
		}

		return self::UNKNOWN;
	}
}
