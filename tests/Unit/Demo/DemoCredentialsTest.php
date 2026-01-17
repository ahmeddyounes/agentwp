<?php
/**
 * DemoCredentials unit tests.
 */

namespace AgentWP\Tests\Unit\Demo;

use AgentWP\Demo\DemoCredentials;
use AgentWP\Plugin\SettingsManager;
use AgentWP\Tests\TestCase;
use Mockery;

class DemoCredentialsTest extends TestCase {
	private function createSettingsMock( bool $demo_mode, string $api_key = '', string $demo_api_key = '' ): SettingsManager {
		$settings = Mockery::mock( SettingsManager::class );
		$settings->shouldReceive( 'isDemoMode' )->andReturn( $demo_mode );
		$settings->shouldReceive( 'getApiKey' )->andReturn( $api_key );
		$settings->shouldReceive( 'getDemoApiKey' )->andReturn( $demo_api_key );
		return $settings;
	}

	public function test_demo_mode_disabled_returns_real_key(): void {
		$settings    = $this->createSettingsMock( false, 'sk-real-key', 'sk-demo-key' );
		$credentials = new DemoCredentials( $settings );

		$this->assertFalse( $credentials->isDemoModeEnabled() );
		$this->assertSame( 'sk-real-key', $credentials->getEffectiveApiKey() );
		$this->assertFalse( $credentials->shouldUseStubbed() );
		$this->assertFalse( $credentials->shouldUseDemoKey() );
	}

	public function test_demo_mode_enabled_with_demo_key_returns_demo_key(): void {
		$settings    = $this->createSettingsMock( true, 'sk-real-key', 'sk-demo-key' );
		$credentials = new DemoCredentials( $settings );

		$this->assertTrue( $credentials->isDemoModeEnabled() );
		$this->assertSame( 'sk-demo-key', $credentials->getEffectiveApiKey() );
		$this->assertSame( DemoCredentials::TYPE_DEMO_KEY, $credentials->getCredentialType() );
		$this->assertFalse( $credentials->shouldUseStubbed() );
		$this->assertTrue( $credentials->shouldUseDemoKey() );
	}

	public function test_demo_mode_enabled_without_demo_key_returns_empty_and_stubbed(): void {
		$settings    = $this->createSettingsMock( true, 'sk-real-key', '' );
		$credentials = new DemoCredentials( $settings );

		$this->assertTrue( $credentials->isDemoModeEnabled() );
		$this->assertSame( '', $credentials->getEffectiveApiKey() );
		$this->assertSame( DemoCredentials::TYPE_STUBBED, $credentials->getCredentialType() );
		$this->assertTrue( $credentials->shouldUseStubbed() );
		$this->assertFalse( $credentials->shouldUseDemoKey() );
	}

	public function test_demo_mode_never_returns_real_key(): void {
		// Even if a real key exists, demo mode should NEVER return it.
		$settings    = $this->createSettingsMock( true, 'sk-real-key', '' );
		$credentials = new DemoCredentials( $settings );

		// This is the critical security test: demo mode should never leak the real key.
		$effective_key = $credentials->getEffectiveApiKey();
		$this->assertNotSame( 'sk-real-key', $effective_key );
		$this->assertSame( '', $effective_key );
	}

	public function test_validate_returns_correct_info_for_normal_mode(): void {
		$settings    = $this->createSettingsMock( false, 'sk-real-key', '' );
		$credentials = new DemoCredentials( $settings );

		$validation = $credentials->validate();
		$this->assertTrue( $validation['valid'] );
		$this->assertSame( 'normal', $validation['type'] );
	}

	public function test_validate_returns_correct_info_for_demo_with_key(): void {
		$settings    = $this->createSettingsMock( true, '', 'sk-demo-key' );
		$credentials = new DemoCredentials( $settings );

		$validation = $credentials->validate();
		$this->assertTrue( $validation['valid'] );
		$this->assertSame( DemoCredentials::TYPE_DEMO_KEY, $validation['type'] );
	}

	public function test_validate_returns_correct_info_for_stubbed(): void {
		$settings    = $this->createSettingsMock( true, '', '' );
		$credentials = new DemoCredentials( $settings );

		$validation = $credentials->validate();
		$this->assertTrue( $validation['valid'] );
		$this->assertSame( DemoCredentials::TYPE_STUBBED, $validation['type'] );
	}
}
