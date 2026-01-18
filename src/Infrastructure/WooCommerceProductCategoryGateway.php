<?php
/**
 * WooCommerce product category gateway implementation.
 *
 * @package AgentWP\Infrastructure
 */

namespace AgentWP\Infrastructure;

use AgentWP\Config\AgentWPConfig;
use AgentWP\Contracts\CacheInterface;
use AgentWP\Contracts\WooCommerceProductCategoryGatewayInterface;

/**
 * Wraps WooCommerce product category functions.
 */
final class WooCommerceProductCategoryGateway implements WooCommerceProductCategoryGatewayInterface {

	/**
	 * Cache key prefix.
	 */
	private const CACHE_PREFIX = AgentWPConfig::CACHE_PREFIX_PRODUCT_CAT; // used with CacheInterface (object cache group is already namespaced).

	/**
	 * Cache TTL in seconds.
	 */
	private const CACHE_TTL = 3600;

	/**
	 * Object cache.
	 *
	 * @var CacheInterface
	 */
	private CacheInterface $cache;

	/**
	 * Create a new WooCommerceProductCategoryGateway.
	 *
	 * @param CacheInterface $cache Object cache for category lookups.
	 */
	public function __construct( CacheInterface $cache ) {
		$this->cache = $cache;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_product_categories( int $product_id ): array {
		if ( $product_id <= 0 ) {
			return array();
		}

		$cacheKey = self::CACHE_PREFIX . $product_id;
		$cached   = $this->cache->get( $cacheKey );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$categories = array();

		if ( function_exists( 'wc_get_product_terms' ) ) {
			$terms = wc_get_product_terms( $product_id, 'product_cat', array( 'fields' => 'all' ) );

			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					if ( ! is_object( $term ) || ! isset( $term->term_id, $term->name ) ) {
						continue;
					}
					$categories[ (int) $term->term_id ] = sanitize_text_field( $term->name );
				}
			}
		}

		$this->cache->set( $cacheKey, $categories, self::CACHE_TTL );

		return $categories;
	}
}
