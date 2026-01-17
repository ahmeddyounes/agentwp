<?php
/**
 * DemoAwareKeyValidator unit tests.
 *
 * Tests that demo mode behavior is deterministic and cannot leak real-key behavior.
 */

namespace AgentWP\Tests\Unit\Demo;

use AgentWP\Contracts\OpenAIKeyValidatorInterface;
use AgentWP\Demo\DemoAwareKeyValidator;
use AgentWP\Demo\DemoCredentials;
use AgentWP\Plugin\SettingsManager;
use AgentWP\Tests\TestCase;
use Mockery;
use WP_Error;

class DemoAwareKeyValidatorTest extends TestCase {

	private function createSettingsMock( bool $demo_mode, string $api_key = '', string $demo_api_key = '' ): SettingsManager {
		$settings = Mockery::mock( SettingsManager::class );
		$settings->shouldReceive( 'isDemoMode' )->andReturn( $demo_mode );
		$settings->shouldReceive( 'getApiKey' )->andReturn( $api_key );
		$settings->shouldReceive( 'getDemoApiKey' )->andReturn( $demo_api_key );
		return $settings;
	}

	private function createRealValidatorMock(): OpenAIKeyValidatorInterface {
		return Mockery::mock( OpenAIKeyValidatorInterface::class );
	}

	// =========================================================================
	// Normal Mode Tests (demo mode OFF)
	// =========================================================================

	public function test_normal_mode_delegates_to_real_validator_with_valid_key(): void {
		$settings         = $this->createSettingsMock( false, 'sk-real-key', '' );
		$demo_credentials = new DemoCredentials( $settings );
		$real_validator   = $this->createRealValidatorMock();

		$real_validator
			->shouldReceive( 'validate' )
			->with( 'sk-user-provided-key' )
			->once()
			->andReturn( true );

		$validator = new DemoAwareKeyValidator( $demo_credentials, $real_validator );
		$result    = $validator->validate( 'sk-user-provided-key' );

		$this->assertTrue( $result );
	}

	public function test_normal_mode_delegates_to_real_validator_with_invalid_key(): void {
		$settings         = $this->createSettingsMock( false, 'sk-real-key', '' );
		$demo_credentials = new DemoCredentials( $settings );
		$real_validator   = $this->createRealValidatorMock();
		$error            = new WP_Error( 'invalid_key', 'Key is invalid' );

		$real_validator
			->shouldReceive( 'validate' )
			->with( 'sk-bad-key' )
			->once()
			->andReturn( $error );

		$validator = new DemoAwareKeyValidator( $demo_credentials, $real_validator );
		$result    = $validator->validate( 'sk-bad-key' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_key', $result->get_error_code() );
	}

	// =========================================================================
	// Demo Stubbed Mode Tests (demo mode ON, no demo key)
	// =========================================================================

	public function test_demo_stubbed_mode_returns_true_without_calling_real_validator(): void {
		$settings         = $this->createSettingsMock( true, 'sk-real-key', '' );
		$demo_credentials = new DemoCredentials( $settings );
		$real_validator   = $this->createRealValidatorMock();

		// Real validator should NEVER be called in stubbed mode.
		$real_validator->shouldNotReceive( 'validate' );

		$validator = new DemoAwareKeyValidator( $demo_credentials, $real_validator );
		$result    = $validator->validate( 'sk-any-key' );

		$this->assertTrue( $result );
	}

	public function test_demo_stubbed_mode_does_not_leak_real_key(): void {
		$settings         = $this->createSettingsMock( true, 'sk-real-key', '' );
		$demo_credentials = new DemoCredentials( $settings );
		$real_validator   = $this->createRealValidatorMock();

		// Even if someone passes the real key, it should not be validated.
		$real_validator->shouldNotReceive( 'validate' );

		$validator = new DemoAwareKeyValidator( $demo_credentials, $real_validator );
		$result    = $validator->validate( 'sk-real-key' );

		$this->assertTrue( $result );
	}

	// =========================================================================
	// Demo Key Mode Tests (demo mode ON, demo key exists)
	// =========================================================================

	public function test_demo_key_mode_validates_demo_key_not_provided_key(): void {
		$settings         = $this->createSettingsMock( true, 'sk-real-key', 'sk-demo-key' );
		$demo_credentials = new DemoCredentials( $settings );
		$real_validator   = $this->createRealValidatorMock();

		// Should validate the demo key, NOT the user-provided key.
		$real_validator
			->shouldReceive( 'validate' )
			->with( 'sk-demo-key' )
			->once()
			->andReturn( true );

		$validator = new DemoAwareKeyValidator( $demo_credentials, $real_validator );
		$result    = $validator->validate( 'sk-user-provided-key' );

		$this->assertTrue( $result );
	}

	public function test_demo_key_mode_never_validates_real_key(): void {
		$settings         = $this->createSettingsMock( true, 'sk-real-key', 'sk-demo-key' );
		$demo_credentials = new DemoCredentials( $settings );
		$real_validator   = $this->createRealValidatorMock();

		// Even if someone provides the real key, we should validate the demo key.
		$real_validator
			->shouldReceive( 'validate' )
			->with( 'sk-demo-key' )
			->once()
			->andReturn( true );

		$validator = new DemoAwareKeyValidator( $demo_credentials, $real_validator );
		$result    = $validator->validate( 'sk-real-key' );

		$this->assertTrue( $result );
	}

	public function test_demo_key_mode_returns_error_if_demo_key_invalid(): void {
		$settings         = $this->createSettingsMock( true, 'sk-real-key', 'sk-invalid-demo-key' );
		$demo_credentials = new DemoCredentials( $settings );
		$real_validator   = $this->createRealValidatorMock();
		$error            = new WP_Error( 'invalid_key', 'Demo key is invalid' );

		$real_validator
			->shouldReceive( 'validate' )
			->with( 'sk-invalid-demo-key' )
			->once()
			->andReturn( $error );

		$validator = new DemoAwareKeyValidator( $demo_credentials, $real_validator );
		$result    = $validator->validate( 'sk-any-key' );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	// =========================================================================
	// Security Tests: Ensure real keys are never leaked in demo mode
	// =========================================================================

	public function test_real_key_is_never_passed_to_validator_in_demo_stubbed_mode(): void {
		$settings         = $this->createSettingsMock( true, 'sk-super-secret-real-key', '' );
		$demo_credentials = new DemoCredentials( $settings );
		$real_validator   = $this->createRealValidatorMock();

		// The real validator should never be called with any key.
		$real_validator->shouldNotReceive( 'validate' );

		$validator = new DemoAwareKeyValidator( $demo_credentials, $real_validator );
		$validator->validate( 'sk-any-provided-key' );
		$validator->validate( 'sk-super-secret-real-key' );
		$validator->validate( '' );

		// Test passes if no validate() calls were made.
		$this->assertTrue( true );
	}

	public function test_real_key_is_never_passed_to_validator_in_demo_key_mode(): void {
		$settings         = $this->createSettingsMock( true, 'sk-super-secret-real-key', 'sk-demo-key' );
		$demo_credentials = new DemoCredentials( $settings );
		$real_validator   = $this->createRealValidatorMock();

		// The real validator should only ever receive the demo key.
		$real_validator
			->shouldReceive( 'validate' )
			->with( 'sk-demo-key' )
			->times( 3 )
			->andReturn( true );

		$validator = new DemoAwareKeyValidator( $demo_credentials, $real_validator );

		// None of these should cause the real key to be validated.
		$validator->validate( 'sk-any-provided-key' );
		$validator->validate( 'sk-super-secret-real-key' );
		$validator->validate( '' );

		$this->assertTrue( true );
	}

	// =========================================================================
	// Helper Method Tests
	// =========================================================================

	public function test_is_demo_mode_returns_correct_value(): void {
		$normal_settings = $this->createSettingsMock( false, '', '' );
		$demo_settings   = $this->createSettingsMock( true, '', '' );

		$normal_validator = new DemoAwareKeyValidator(
			new DemoCredentials( $normal_settings ),
			$this->createRealValidatorMock()
		);

		$demo_validator = new DemoAwareKeyValidator(
			new DemoCredentials( $demo_settings ),
			$this->createRealValidatorMock()
		);

		$this->assertFalse( $normal_validator->isDemoMode() );
		$this->assertTrue( $demo_validator->isDemoMode() );
	}

	public function test_get_validation_info_for_normal_mode(): void {
		$settings         = $this->createSettingsMock( false, 'sk-real-key', '' );
		$demo_credentials = new DemoCredentials( $settings );
		$validator        = new DemoAwareKeyValidator( $demo_credentials, $this->createRealValidatorMock() );

		$info = $validator->getValidationInfo();

		$this->assertSame( 'normal', $info['mode'] );
		$this->assertSame( 'real_validation', $info['type'] );
		$this->assertTrue( $info['will_call_api'] );
	}

	public function test_get_validation_info_for_demo_stubbed_mode(): void {
		$settings         = $this->createSettingsMock( true, 'sk-real-key', '' );
		$demo_credentials = new DemoCredentials( $settings );
		$validator        = new DemoAwareKeyValidator( $demo_credentials, $this->createRealValidatorMock() );

		$info = $validator->getValidationInfo();

		$this->assertSame( 'demo', $info['mode'] );
		$this->assertSame( DemoCredentials::TYPE_STUBBED, $info['type'] );
		$this->assertFalse( $info['will_call_api'] );
	}

	public function test_get_validation_info_for_demo_key_mode(): void {
		$settings         = $this->createSettingsMock( true, 'sk-real-key', 'sk-demo-key' );
		$demo_credentials = new DemoCredentials( $settings );
		$validator        = new DemoAwareKeyValidator( $demo_credentials, $this->createRealValidatorMock() );

		$info = $validator->getValidationInfo();

		$this->assertSame( 'demo', $info['mode'] );
		$this->assertSame( DemoCredentials::TYPE_DEMO_KEY, $info['type'] );
		$this->assertTrue( $info['will_call_api'] );
	}
}
