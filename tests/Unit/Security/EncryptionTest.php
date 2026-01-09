<?php
/**
 * Unit tests for Encryption class.
 */

namespace AgentWP\Tests\Unit\Security;

use AgentWP\Security\Encryption;
use AgentWP\Tests\Support\EncryptionFunctionOverrides;
use AgentWP\Tests\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class EncryptionTest extends TestCase {
	public function setUp(): void {
		parent::setUp();
		EncryptionFunctionOverrides::reset();
	}

	public function tearDown(): void {
		EncryptionFunctionOverrides::reset();
		parent::tearDown();
	}

	private function define_key_material( $key = 'key', $salt = 'salt' ): void {
		if ( ! defined( 'LOGGED_IN_KEY' ) ) {
			define( 'LOGGED_IN_KEY', $key );
		}

		if ( ! defined( 'LOGGED_IN_SALT' ) ) {
			define( 'LOGGED_IN_SALT', $salt );
		}
	}

	private function invoke_private( Encryption $encryption, $method, array $args = array() ) {
		$reflection = new \ReflectionClass( $encryption );
		$target     = $reflection->getMethod( $method );
		$target->setAccessible( true );

		return $target->invokeArgs( $encryption, $args );
	}

	public function test_encrypt_returns_empty_for_empty_plaintext(): void {
		$encryption = new Encryption();

		$this->assertSame( '', $encryption->encrypt( '' ) );
	}

	public function test_encrypt_returns_empty_when_openssl_missing(): void {
		EncryptionFunctionOverrides::$function_exists = static function ( $name ) {
			if ( 'openssl_encrypt' === $name ) {
				return false;
			}

			return \function_exists( $name );
		};

		$encryption = new Encryption();

		$this->assertSame( '', $encryption->encrypt( 'secret' ) );
	}

	public function test_encrypt_returns_empty_when_no_key_material(): void {
		$encryption = new Encryption();

		$this->assertSame( '', $encryption->encrypt( 'secret' ) );
	}

	public function test_encrypt_returns_empty_when_iv_length_invalid(): void {
		$this->define_key_material();
		EncryptionFunctionOverrides::$openssl_cipher_iv_length = static function () {
			return 0;
		};

		$encryption = new Encryption();

		$this->assertSame( '', $encryption->encrypt( 'secret' ) );
		$this->assertSame( 0, $this->invoke_private( $encryption, 'get_iv_length' ) );
	}

	public function test_encrypt_returns_empty_when_random_bytes_fails(): void {
		$this->define_key_material();
		EncryptionFunctionOverrides::$random_bytes = static function () {
			throw new \Exception( 'fail' );
		};

		$encryption = new Encryption();

		$this->assertSame( '', $encryption->encrypt( 'secret' ) );
	}

	public function test_encrypt_returns_empty_when_openssl_encrypt_fails(): void {
		$this->define_key_material();
		EncryptionFunctionOverrides::$openssl_encrypt = static function () {
			return false;
		};

		$encryption = new Encryption();

		$this->assertSame( '', $encryption->encrypt( 'secret' ) );
	}

	public function test_encrypt_and_decrypt_roundtrip(): void {
		$this->define_key_material();
		$encryption = new Encryption();

		$ciphertext = $encryption->encrypt( 'secret' );

		$this->assertNotSame( '', $ciphertext );
		$this->assertSame( 'secret', $encryption->decrypt( $ciphertext ) );
	}

	public function test_decrypt_returns_empty_when_openssl_missing(): void {
		EncryptionFunctionOverrides::$function_exists = static function ( $name ) {
			if ( 'openssl_decrypt' === $name ) {
				return false;
			}

			return \function_exists( $name );
		};

		$encryption = new Encryption();

		$this->assertSame( '', $encryption->decrypt( 'payload' ) );
	}

	public function test_decrypt_returns_empty_for_invalid_payload(): void {
		$encryption = new Encryption();

		$this->assertSame( '', $encryption->decrypt( 'not-valid' ) );
	}

	public function test_decrypt_returns_empty_on_fingerprint_mismatch(): void {
		$this->define_key_material();
		$encryption = new Encryption();

		$ciphertext = $encryption->encrypt( 'secret' );
		$parts      = explode( Encryption::DELIMITER, $ciphertext, 3 );
		$parts[1]   = str_repeat( '0', 64 );
		$mutated    = implode( Encryption::DELIMITER, $parts );

		$this->assertSame( '', $encryption->decrypt( $mutated ) );
	}

	public function test_decrypt_legacy_payload(): void {
		$this->define_key_material();
		$encryption = new Encryption();

		$key   = hash( 'sha256', LOGGED_IN_KEY . LOGGED_IN_SALT, true );
		$nonce = \random_bytes( Encryption::LEGACY_NONCE_LENGTH );
		$tag   = '';
		$data  = \openssl_encrypt( 'legacy', Encryption::LEGACY_CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag );
		$payload = base64_encode( $nonce . $tag . $data );

		$this->assertSame( 'legacy', $encryption->decrypt( $payload ) );
		$this->assertTrue( $encryption->isEncrypted( $payload ) );
	}

	public function test_decrypt_returns_empty_for_legacy_failure(): void {
		$this->define_key_material();
		EncryptionFunctionOverrides::$openssl_decrypt = static function () {
			return false;
		};

		$encryption = new Encryption();
		$key        = hash( 'sha256', LOGGED_IN_KEY . LOGGED_IN_SALT, true );
		$nonce      = \random_bytes( Encryption::LEGACY_NONCE_LENGTH );
		$tag        = '';
		$data       = \openssl_encrypt( 'legacy', Encryption::LEGACY_CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag );
		$payload    = base64_encode( $nonce . $tag . $data );

		$this->assertSame( '', $encryption->decrypt( $payload ) );
	}

	public function test_parse_payload_variants(): void {
		$this->define_key_material();
		$encryption = new Encryption();

		$this->assertNull( $this->invoke_private( $encryption, 'parse_payload', array( 'nope' ) ) );
		$this->assertNull( $this->invoke_private( $encryption, 'parse_payload', array( 'awp1:' ) ) );
		$this->assertNull( $this->invoke_private( $encryption, 'parse_payload', array( 'awp1::payload' ) ) );
		$this->assertNull( $this->invoke_private( $encryption, 'parse_payload', array( 'awp1:fp:@@' ) ) );

		EncryptionFunctionOverrides::$openssl_cipher_iv_length = static function () {
			return 16;
		};
		$short_payload = 'awp1:fp:' . base64_encode( 'short' );
		$this->assertNull( $this->invoke_private( $encryption, 'parse_payload', array( $short_payload ) ) );

		EncryptionFunctionOverrides::$openssl_cipher_iv_length = static function () {
			return 0;
		};
		$payload = 'awp1:fp:' . base64_encode( str_repeat( 'a', 32 ) );
		$this->assertNull( $this->invoke_private( $encryption, 'parse_payload', array( $payload ) ) );
	}

	public function test_parse_legacy_payload_variants(): void {
		$encryption = new Encryption();

		$this->assertNull( $this->invoke_private( $encryption, 'parse_legacy_payload', array( '@@' ) ) );

		$short = base64_encode( 'too-short' );
		$this->assertNull( $this->invoke_private( $encryption, 'parse_legacy_payload', array( $short ) ) );

		$payload = base64_encode(
			str_repeat( 'n', Encryption::LEGACY_NONCE_LENGTH )
			. str_repeat( 't', Encryption::LEGACY_TAG_LENGTH )
			. 'c'
		);
		$parsed = $this->invoke_private( $encryption, 'parse_legacy_payload', array( $payload ) );

		$this->assertIsArray( $parsed );
		$this->assertArrayHasKey( 'nonce', $parsed );
		$this->assertArrayHasKey( 'tag', $parsed );
		$this->assertArrayHasKey( 'ciphertext', $parsed );
	}

	public function test_is_encrypted_and_rotate(): void {
		$this->define_key_material();
		$encryption = new Encryption();

		$this->assertFalse( $encryption->isEncrypted( '' ) );
		$this->assertSame( '', $encryption->rotate( '' ) );
		$this->assertFalse( $encryption->isEncrypted( 'not-valid' ) );

		$ciphertext = $encryption->encrypt( 'secret' );
		$this->assertTrue( $encryption->isEncrypted( $ciphertext ) );
		$this->assertSame( $ciphertext, $encryption->rotate( $ciphertext ) );
		$this->assertSame( '', $encryption->rotate( 'not-valid' ) );

		$key   = hash( 'sha256', LOGGED_IN_KEY . LOGGED_IN_SALT, true );
		$nonce = \random_bytes( Encryption::LEGACY_NONCE_LENGTH );
		$tag   = '';
		$data  = \openssl_encrypt( 'legacy', Encryption::LEGACY_CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag );
		$legacy_payload = base64_encode( $nonce . $tag . $data );

		$rotated = $encryption->rotate( $legacy_payload );
		$this->assertNotSame( '', $rotated );
		$this->assertStringStartsWith( Encryption::VERSION . Encryption::DELIMITER, $rotated );
	}

	public function test_get_key_material_candidates_with_rotations(): void {
		$this->define_key_material( 'current', 'salt' );
		EncryptionFunctionOverrides::$apply_filters = static function ( $hook, $value ) {
			if ( 'agentwp_encryption_rotation_materials' === $hook ) {
				return array( 'legacy', 'current' );
			}
			return $value;
		};

		$encryption = new Encryption();
		$materials  = $this->invoke_private( $encryption, 'get_key_material_candidates' );

		$this->assertContains( 'current' . 'salt', $materials );
		$this->assertContains( 'legacy', $materials );
	}

	public function test_is_current_encryption_checks_material(): void {
		$this->define_key_material();
		$encryption = new Encryption();
		$ciphertext = $encryption->encrypt( 'secret' );

		$this->assertTrue( $this->invoke_private( $encryption, 'is_current_encryption', array( $ciphertext ) ) );
		$this->assertFalse( $this->invoke_private( $encryption, 'is_current_encryption', array( 'bad' ) ) );
	}

	public function test_is_current_encryption_without_material(): void {
		$encryption = new Encryption();
		$iv_length  = $this->invoke_private( $encryption, 'get_iv_length' );
		$payload    = Encryption::VERSION
			. Encryption::DELIMITER
			. str_repeat( 'a', 64 )
			. Encryption::DELIMITER
			. base64_encode( str_repeat( 'b', max( 1, $iv_length + 1 ) ) );

		$this->assertFalse( $this->invoke_private( $encryption, 'is_current_encryption', array( $payload ) ) );
	}
}
