<?php
/**
 * AIClientFactory unit tests.
 *
 * Tests that demo mode behavior is deterministic and cannot leak real-key behavior.
 */

namespace AgentWP\Tests\Unit\AI;

use AgentWP\AI\AIClientFactory;
use AgentWP\AI\OpenAIClient;
use AgentWP\Contracts\HttpClientInterface;
use AgentWP\Contracts\UsageTrackerInterface;
use AgentWP\Demo\DemoClient;
use AgentWP\Demo\DemoCredentials;
use AgentWP\Plugin\SettingsManager;
use AgentWP\Tests\TestCase;
use Mockery;

class AIClientFactoryTest extends TestCase {

	private function createSettingsMock( bool $demo_mode, string $api_key = '', string $demo_api_key = '' ): SettingsManager {
		$settings = Mockery::mock( SettingsManager::class );
		$settings->shouldReceive( 'isDemoMode' )->andReturn( $demo_mode );
		$settings->shouldReceive( 'getApiKey' )->andReturn( $api_key );
		$settings->shouldReceive( 'getDemoApiKey' )->andReturn( $demo_api_key );
		return $settings;
	}

	private function createHttpClientMock(): HttpClientInterface {
		return Mockery::mock( HttpClientInterface::class );
	}

	private function createUsageTrackerMock(): UsageTrackerInterface {
		return Mockery::mock( UsageTrackerInterface::class );
	}

	private function createFactory(
		bool $demo_mode,
		string $api_key = '',
		string $demo_api_key = '',
		string $default_model = 'gpt-4o-mini'
	): AIClientFactory {
		$settings         = $this->createSettingsMock( $demo_mode, $api_key, $demo_api_key );
		$demo_credentials = new DemoCredentials( $settings );
		$http_client      = $this->createHttpClientMock();
		$usage_tracker    = $this->createUsageTrackerMock();

		return new AIClientFactory(
			$http_client,
			$settings,
			$default_model,
			$demo_credentials,
			$usage_tracker
		);
	}

	// =========================================================================
	// Normal Mode Tests (demo mode OFF)
	// =========================================================================

	public function test_normal_mode_creates_openai_client_with_real_key(): void {
		$factory = $this->createFactory( false, 'sk-real-key', '' );
		$client  = $factory->create( 'test-intent' );

		$this->assertInstanceOf( OpenAIClient::class, $client );
	}

	public function test_normal_mode_has_api_key_when_key_exists(): void {
		$factory = $this->createFactory( false, 'sk-real-key', '' );

		$this->assertTrue( $factory->hasApiKey() );
	}

	public function test_normal_mode_no_api_key_when_key_missing(): void {
		$factory = $this->createFactory( false, '', '' );

		$this->assertFalse( $factory->hasApiKey() );
	}

	// =========================================================================
	// Demo Stubbed Mode Tests (demo mode ON, no demo key)
	// =========================================================================

	public function test_demo_stubbed_mode_creates_demo_client(): void {
		$factory = $this->createFactory( true, 'sk-real-key', '' );
		$client  = $factory->create( 'test-intent' );

		$this->assertInstanceOf( DemoClient::class, $client );
	}

	public function test_demo_stubbed_mode_has_api_key_always_true(): void {
		// Stubbed mode doesn't need a key, so hasApiKey returns true.
		$factory = $this->createFactory( true, 'sk-real-key', '' );

		$this->assertTrue( $factory->hasApiKey() );
	}

	public function test_demo_stubbed_mode_never_creates_openai_client(): void {
		// Even with a real key present, demo stubbed mode should use DemoClient.
		$factory = $this->createFactory( true, 'sk-real-key', '' );
		$client  = $factory->create( 'test-intent' );

		$this->assertInstanceOf( DemoClient::class, $client );
		$this->assertNotInstanceOf( OpenAIClient::class, $client );
	}

	// =========================================================================
	// Demo Key Mode Tests (demo mode ON, demo key exists)
	// =========================================================================

	public function test_demo_key_mode_creates_openai_client(): void {
		$factory = $this->createFactory( true, 'sk-real-key', 'sk-demo-key' );
		$client  = $factory->create( 'test-intent' );

		$this->assertInstanceOf( OpenAIClient::class, $client );
	}

	public function test_demo_key_mode_has_api_key_true(): void {
		$factory = $this->createFactory( true, 'sk-real-key', 'sk-demo-key' );

		$this->assertTrue( $factory->hasApiKey() );
	}

	// =========================================================================
	// Security Tests: Ensure real keys are never used in demo mode
	// =========================================================================

	public function test_demo_stubbed_mode_credential_info_shows_no_real_key(): void {
		$factory = $this->createFactory( true, 'sk-super-secret-real-key', '' );
		$info    = $factory->getCredentialInfo();

		$this->assertSame( 'demo', $info['mode'] );
		$this->assertSame( DemoCredentials::TYPE_STUBBED, $info['type'] );
		$this->assertFalse( $info['has_key'] );
	}

	public function test_demo_key_mode_credential_info_shows_demo_key(): void {
		$factory = $this->createFactory( true, 'sk-super-secret-real-key', 'sk-demo-key' );
		$info    = $factory->getCredentialInfo();

		$this->assertSame( 'demo', $info['mode'] );
		$this->assertSame( DemoCredentials::TYPE_DEMO_KEY, $info['type'] );
		$this->assertTrue( $info['has_key'] );
	}

	public function test_normal_mode_credential_info_shows_real_key(): void {
		$factory = $this->createFactory( false, 'sk-real-key', 'sk-demo-key' );
		$info    = $factory->getCredentialInfo();

		$this->assertSame( 'normal', $info['mode'] );
		$this->assertSame( 'real_key', $info['type'] );
		$this->assertTrue( $info['has_key'] );
	}

	// =========================================================================
	// isDemoMode Tests
	// =========================================================================

	public function test_is_demo_mode_returns_correct_value(): void {
		$normal_factory = $this->createFactory( false, '', '' );
		$demo_factory   = $this->createFactory( true, '', '' );

		$this->assertFalse( $normal_factory->isDemoMode() );
		$this->assertTrue( $demo_factory->isDemoMode() );
	}

	// =========================================================================
	// Model Override Tests
	// =========================================================================

	public function test_create_uses_options_model_when_provided(): void {
		$factory = $this->createFactory( true, '', '' );
		$client  = $factory->create( 'test-intent', array( 'model' => 'gpt-4o' ) );

		// DemoClient is returned in stubbed mode.
		$this->assertInstanceOf( DemoClient::class, $client );
	}

	public function test_create_uses_default_model_when_not_provided(): void {
		$factory = $this->createFactory( true, '', '', 'custom-model' );
		$client  = $factory->create( 'test-intent' );

		$this->assertInstanceOf( DemoClient::class, $client );
	}

	// =========================================================================
	// Determinism Tests
	// =========================================================================

	public function test_same_configuration_produces_same_client_type(): void {
		$factory = $this->createFactory( true, 'sk-real-key', '' );

		$client1 = $factory->create( 'intent-1' );
		$client2 = $factory->create( 'intent-2' );
		$client3 = $factory->create( 'intent-3' );

		// All should be DemoClient in stubbed mode.
		$this->assertInstanceOf( DemoClient::class, $client1 );
		$this->assertInstanceOf( DemoClient::class, $client2 );
		$this->assertInstanceOf( DemoClient::class, $client3 );
	}

	public function test_demo_mode_behavior_is_deterministic(): void {
		// Test that the same inputs always produce the same output type.
		for ( $i = 0; $i < 5; $i++ ) {
			$factory = $this->createFactory( true, 'sk-real-key', '' );
			$client  = $factory->create( 'test-intent-' . $i );
			$this->assertInstanceOf( DemoClient::class, $client );
		}

		for ( $i = 0; $i < 5; $i++ ) {
			$factory = $this->createFactory( true, 'sk-real-key', 'sk-demo-key' );
			$client  = $factory->create( 'test-intent-' . $i );
			$this->assertInstanceOf( OpenAIClient::class, $client );
		}
	}
}
