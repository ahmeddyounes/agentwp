<?php
/**
 * WordPress functions wrapper.
 *
 * @package AgentWP\Infrastructure
 */

namespace AgentWP\Infrastructure;

use AgentWP\Contracts\HooksInterface;
use AgentWP\Contracts\WPUserFunctionsInterface;
use DateTimeZone;

/**
 * Wrapper for WordPress functions to enable testability.
 *
 * This class wraps WordPress core and WooCommerce functions,
 * allowing them to be mocked in unit tests. Use this wrapper
 * instead of calling WordPress functions directly in business logic.
 *
 * Usage:
 * ```php
 * class MyService {
 *     public function __construct(
 *         private WPFunctions $wp
 *     ) {}
 *
 *     public function getCurrentUser() {
 *         return $this->wp->getCurrentUser();
 *     }
 * }
 * ```
 *
 * In tests:
 * ```php
 * $wpMock = $this->createMock(WPFunctions::class);
 * $wpMock->method('getCurrentUser')->willReturn($fakeUser);
 * ```
 */
final class WPFunctions implements HooksInterface, WPUserFunctionsInterface {

	/**
	 * Get the current WordPress user.
	 *
	 * @return \WP_User|null Current user or null if not logged in.
	 */
	public function getCurrentUser(): ?\WP_User {
		if ( ! function_exists( 'wp_get_current_user' ) ) {
			return null;
		}

		$user = wp_get_current_user();

		return $user->ID > 0 ? $user : null;
	}

	/**
	 * Get the current user ID.
	 *
	 * @return int Current user ID, or 0 if not logged in.
	 */
	public function getCurrentUserId(): int {
		if ( ! function_exists( 'get_current_user_id' ) ) {
			return 0;
		}

		return (int) get_current_user_id();
	}

	/**
	 * Get orders from WooCommerce.
	 *
	 * @param array $args Query arguments.
	 * @return array Array of order objects or IDs.
	 */
	public function getOrders( array $args = [] ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return [];
		}

		$orders = wc_get_orders( $args );

		return is_array( $orders ) ? $orders : [];
	}

	/**
	 * Get a single order by ID.
	 *
	 * @param int $orderId Order ID.
	 * @return \WC_Order|null Order object or null if not found.
	 */
	public function getOrder( int $orderId ): ?\WC_Order {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		$order = wc_get_order( $orderId );

		return $order instanceof \WC_Order ? $order : null;
	}

	/**
	 * Get WordPress timezone.
	 *
	 * @return DateTimeZone WordPress timezone.
	 */
	public function getTimezone(): DateTimeZone {
		if ( function_exists( 'wp_timezone' ) ) {
			return wp_timezone();
		}

		$timezoneString = '';

		if ( function_exists( 'wp_timezone_string' ) ) {
			$timezoneString = wp_timezone_string();
		} elseif ( function_exists( 'get_option' ) ) {
			$timezoneString = (string) get_option( 'timezone_string' );

			if ( '' === $timezoneString ) {
				$offset = (float) get_option( 'gmt_offset', 0 );
				if ( 0.0 !== $offset ) {
					$hours = (int) $offset;
					$minutes = (int) ( abs( $offset - $hours ) * 60 );
					$sign = $offset >= 0 ? '+' : '-';
					$timezoneString = sprintf( '%s%02d:%02d', $sign, abs( $hours ), $minutes );
				}
			}
		}

		if ( '' === $timezoneString ) {
			$timezoneString = 'UTC';
		}

		try {
			return new DateTimeZone( $timezoneString );
		} catch ( \Exception $e ) {
			return new DateTimeZone( 'UTC' );
		}
	}

	/**
	 * Get store currency.
	 *
	 * @return string Currency code.
	 */
	public function getCurrency(): string {
		if ( function_exists( 'get_woocommerce_currency' ) ) {
			return get_woocommerce_currency();
		}

		if ( function_exists( 'get_option' ) ) {
			return (string) get_option( 'woocommerce_currency', 'USD' );
		}

		return 'USD';
	}

	/**
	 * Get a WordPress option.
	 *
	 * @param string $option  Option name.
	 * @param mixed  $default Default value.
	 * @return mixed Option value.
	 */
	public function getOption( string $option, $default = false ) {
		if ( ! function_exists( 'get_option' ) ) {
			return $default;
		}

		return get_option( $option, $default );
	}

	/**
	 * Update a WordPress option.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Option value.
	 * @param bool   $autoload Whether to autoload.
	 * @return bool True if updated.
	 */
	public function updateOption( string $option, $value, bool $autoload = false ): bool {
		if ( ! function_exists( 'update_option' ) ) {
			return false;
		}

		return update_option( $option, $value, $autoload );
	}

	/**
	 * Get a transient.
	 *
	 * @param string $transient Transient name.
	 * @return mixed Transient value or false if not set.
	 */
	public function getTransient( string $transient ) {
		if ( ! function_exists( 'get_transient' ) ) {
			return false;
		}

		return get_transient( $transient );
	}

	/**
	 * Set a transient.
	 *
	 * @param string $transient Transient name.
	 * @param mixed  $value     Transient value.
	 * @param int    $expiration Expiration in seconds.
	 * @return bool True if set.
	 */
	public function setTransient( string $transient, $value, int $expiration = 0 ): bool {
		if ( ! function_exists( 'set_transient' ) ) {
			return false;
		}

		return set_transient( $transient, $value, $expiration );
	}

	/**
	 * Delete a transient.
	 *
	 * @param string $transient Transient name.
	 * @return bool True if deleted.
	 */
	public function deleteTransient( string $transient ): bool {
		if ( ! function_exists( 'delete_transient' ) ) {
			return false;
		}

		return delete_transient( $transient );
	}

	/**
	 * Get object cache value.
	 *
	 * @param string $key   Cache key.
	 * @param string $group Cache group.
	 * @return mixed Cached value or false if not set.
	 */
	public function getCache( string $key, string $group = '' ) {
		if ( ! function_exists( 'wp_cache_get' ) ) {
			return false;
		}

		return wp_cache_get( $key, $group );
	}

	/**
	 * Set object cache value.
	 *
	 * @param string $key     Cache key.
	 * @param mixed  $value   Cache value.
	 * @param string $group   Cache group.
	 * @param int    $expire  Expiration in seconds.
	 * @return bool True if set.
	 */
	public function setCache( string $key, $value, string $group = '', int $expire = 0 ): bool {
		if ( ! function_exists( 'wp_cache_set' ) ) {
			return false;
		}

		return wp_cache_set( $key, $value, $group, $expire ); // phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- Expiry is controlled by the caller; 0 means no expiration.
	}

	/**
	 * Delete object cache value.
	 *
	 * @param string $key   Cache key.
	 * @param string $group Cache group.
	 * @return bool True if deleted.
	 */
	public function deleteCache( string $key, string $group = '' ): bool {
		if ( ! function_exists( 'wp_cache_delete' ) ) {
			return false;
		}

		return wp_cache_delete( $key, $group );
	}

	/**
	 * Apply WordPress filters.
	 *
	 * @param string $hook_name Filter name.
	 * @param mixed  $value     Value to filter.
	 * @param mixed  ...$args   Additional arguments passed to the filter.
	 * @return mixed Filtered value.
	 */
	public function applyFilters( string $hook_name, $value, ...$args ) {
		if ( ! function_exists( 'apply_filters' ) ) {
			return $value;
		}

		return apply_filters( $hook_name, $value, ...$args );
	}

	/**
	 * Do WordPress action.
	 *
	 * @param string $hook_name Action name.
	 * @param mixed  ...$args   Action arguments.
	 * @return void
	 */
	public function doAction( string $hook_name, ...$args ): void {
		if ( ! function_exists( 'do_action' ) ) {
			return;
		}

		do_action( $hook_name, ...$args );
	}

	/**
	 * Check if a user has a capability.
	 *
	 * @param string $capability Capability name.
	 * @param int|null $userId   User ID (null for current user).
	 * @return bool True if user has capability.
	 */
	public function currentUserCan( string $capability, ?int $userId = null ): bool {
		if ( ! function_exists( 'current_user_can' ) ) {
			return false;
		}

		return current_user_can( $capability, $userId );
	}

	/**
	 * Sanitize text field.
	 *
	 * @param string $text Text to sanitize.
	 * @return string Sanitized text.
	 */
	public function sanitizeTextField( string $text ): string {
		if ( ! function_exists( 'sanitize_text_field' ) ) {
			return $text;
		}

		return sanitize_text_field( $text );
	}

	/**
	 * Sanitize email.
	 *
	 * @param string $email Email to sanitize.
	 * @return string Sanitized email.
	 */
	public function sanitizeEmail( string $email ): string {
		if ( ! function_exists( 'sanitize_email' ) ) {
			return $email;
		}

		return sanitize_email( $email );
	}
}
