<?php
/**
 * Namespaced function overrides for AgentWP\Security.
 */

namespace AgentWP\Security;

use AgentWP\Tests\Support\EncryptionFunctionOverrides;

function function_exists( $name ) {
	return EncryptionFunctionOverrides::function_exists( $name );
}

function openssl_cipher_iv_length( $cipher ) {
	return EncryptionFunctionOverrides::openssl_cipher_iv_length( $cipher );
}

function random_bytes( $length ) {
	return EncryptionFunctionOverrides::random_bytes( $length );
}

function openssl_encrypt( ...$args ) {
	return EncryptionFunctionOverrides::openssl_encrypt( ...$args );
}

function openssl_decrypt( ...$args ) {
	return EncryptionFunctionOverrides::openssl_decrypt( ...$args );
}
