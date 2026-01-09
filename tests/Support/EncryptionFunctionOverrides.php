<?php
/**
 * Support helpers for Encryption function overrides.
 */

namespace AgentWP\Tests\Support;

final class EncryptionFunctionOverrides {
	public static $function_exists;
	public static $openssl_cipher_iv_length;
	public static $random_bytes;
	public static $openssl_encrypt;
	public static $openssl_decrypt;
	public static $apply_filters;

	public static function reset(): void {
		self::$function_exists         = null;
		self::$openssl_cipher_iv_length = null;
		self::$random_bytes            = null;
		self::$openssl_encrypt         = null;
		self::$openssl_decrypt         = null;
		self::$apply_filters           = null;
	}

	public static function function_exists( $name ): bool {
		if ( null !== self::$function_exists ) {
			return (bool) call_user_func( self::$function_exists, $name );
		}

		return \function_exists( $name );
	}

	public static function openssl_cipher_iv_length( $cipher ) {
		if ( null !== self::$openssl_cipher_iv_length ) {
			return call_user_func( self::$openssl_cipher_iv_length, $cipher );
		}

		return \openssl_cipher_iv_length( $cipher );
	}

	public static function random_bytes( $length ) {
		if ( null !== self::$random_bytes ) {
			return call_user_func( self::$random_bytes, $length );
		}

		return \random_bytes( $length );
	}

	public static function openssl_encrypt( ...$args ) {
		if ( null !== self::$openssl_encrypt ) {
			return call_user_func( self::$openssl_encrypt, ...$args );
		}

		return \openssl_encrypt( ...$args );
	}

	public static function openssl_decrypt( ...$args ) {
		if ( null !== self::$openssl_decrypt ) {
			return call_user_func( self::$openssl_decrypt, ...$args );
		}

		return \openssl_decrypt( ...$args );
	}

	public static function apply_filters( $hook, $value, ...$args ) {
		if ( null !== self::$apply_filters ) {
			return call_user_func( self::$apply_filters, $hook, $value, ...$args );
		}

		return \apply_filters( $hook, $value, ...$args );
	}
}
