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

	/**
	 * Wrapper for openssl_encrypt that preserves the by-reference tag argument.
	 *
	 * @param string      $data Plaintext data.
	 * @param string      $cipher_algo Cipher name.
	 * @param string      $passphrase Key/passphrase.
	 * @param int         $options Options.
	 * @param string      $iv Initialization vector / nonce.
	 * @param string|null $tag Authentication tag (by reference).
	 * @param string      $aad Additional authenticated data.
	 * @param int         $tag_length Tag length.
	 * @return string|false
	 */
	public static function openssl_encrypt(
		$data,
		$cipher_algo,
		$passphrase,
		$options = 0,
		$iv = '',
		&$tag = null,
		$aad = '',
		$tag_length = 16
	) {
		if ( null !== self::$openssl_encrypt ) {
			$callable = self::$openssl_encrypt;
			return $callable( $data, $cipher_algo, $passphrase, $options, $iv, $tag, $aad, $tag_length );
		}

		return \openssl_encrypt( $data, $cipher_algo, $passphrase, $options, $iv, $tag, $aad, $tag_length );
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
