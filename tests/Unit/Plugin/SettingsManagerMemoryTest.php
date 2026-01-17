<?php
/**
 * SettingsManager memory configuration unit tests.
 */

namespace AgentWP\Tests\Unit\Plugin;

use AgentWP\Plugin\SettingsManager;
use AgentWP\Tests\Fakes\FakeOptions;
use AgentWP\Tests\TestCase;

class SettingsManagerMemoryTest extends TestCase {

	public function test_memory_limit_returns_default_when_not_set(): void {
		$options  = new FakeOptions();
		$settings = new SettingsManager( $options );

		$this->assertSame( SettingsManager::DEFAULT_MEMORY_LIMIT, $settings->getMemoryLimit() );
	}

	public function test_memory_limit_returns_stored_value(): void {
		$options = new FakeOptions(
			array(
				SettingsManager::OPTION_MEMORY_LIMIT => 10,
			)
		);
		$settings = new SettingsManager( $options );

		$this->assertSame( 10, $settings->getMemoryLimit() );
	}

	public function test_memory_limit_enforces_minimum_of_1(): void {
		$options = new FakeOptions(
			array(
				SettingsManager::OPTION_MEMORY_LIMIT => 0,
			)
		);
		$settings = new SettingsManager( $options );

		$this->assertSame( 1, $settings->getMemoryLimit() );
	}

	public function test_memory_limit_enforces_minimum_for_negative_values(): void {
		$options = new FakeOptions(
			array(
				SettingsManager::OPTION_MEMORY_LIMIT => -5,
			)
		);
		$settings = new SettingsManager( $options );

		$this->assertSame( 1, $settings->getMemoryLimit() );
	}

	public function test_set_memory_limit_stores_value(): void {
		$options  = new FakeOptions();
		$settings = new SettingsManager( $options );

		$result = $settings->setMemoryLimit( 20 );

		$this->assertTrue( $result );
		$this->assertSame( 20, $settings->getMemoryLimit() );
	}

	public function test_set_memory_limit_enforces_minimum(): void {
		$options  = new FakeOptions();
		$settings = new SettingsManager( $options );

		$settings->setMemoryLimit( 0 );

		$this->assertSame( 1, $options->get( SettingsManager::OPTION_MEMORY_LIMIT ) );
	}

	public function test_memory_ttl_returns_default_when_not_set(): void {
		$options  = new FakeOptions();
		$settings = new SettingsManager( $options );

		$this->assertSame( SettingsManager::DEFAULT_MEMORY_TTL, $settings->getMemoryTtl() );
	}

	public function test_memory_ttl_returns_stored_value(): void {
		$options = new FakeOptions(
			array(
				SettingsManager::OPTION_MEMORY_TTL => 3600,
			)
		);
		$settings = new SettingsManager( $options );

		$this->assertSame( 3600, $settings->getMemoryTtl() );
	}

	public function test_memory_ttl_enforces_minimum_of_60(): void {
		$options = new FakeOptions(
			array(
				SettingsManager::OPTION_MEMORY_TTL => 30,
			)
		);
		$settings = new SettingsManager( $options );

		$this->assertSame( 60, $settings->getMemoryTtl() );
	}

	public function test_memory_ttl_enforces_minimum_for_negative_values(): void {
		$options = new FakeOptions(
			array(
				SettingsManager::OPTION_MEMORY_TTL => -100,
			)
		);
		$settings = new SettingsManager( $options );

		$this->assertSame( 60, $settings->getMemoryTtl() );
	}

	public function test_set_memory_ttl_stores_value(): void {
		$options  = new FakeOptions();
		$settings = new SettingsManager( $options );

		$result = $settings->setMemoryTtl( 7200 );

		$this->assertTrue( $result );
		$this->assertSame( 7200, $settings->getMemoryTtl() );
	}

	public function test_set_memory_ttl_enforces_minimum(): void {
		$options  = new FakeOptions();
		$settings = new SettingsManager( $options );

		$settings->setMemoryTtl( 10 );

		$this->assertSame( 60, $options->get( SettingsManager::OPTION_MEMORY_TTL ) );
	}

	public function test_initialize_defaults_sets_memory_limit(): void {
		$options  = new FakeOptions();
		$settings = new SettingsManager( $options );

		$settings->initializeDefaults();

		$this->assertTrue( $options->has( SettingsManager::OPTION_MEMORY_LIMIT ) );
		$this->assertSame( SettingsManager::DEFAULT_MEMORY_LIMIT, $options->get( SettingsManager::OPTION_MEMORY_LIMIT ) );
	}

	public function test_initialize_defaults_sets_memory_ttl(): void {
		$options  = new FakeOptions();
		$settings = new SettingsManager( $options );

		$settings->initializeDefaults();

		$this->assertTrue( $options->has( SettingsManager::OPTION_MEMORY_TTL ) );
		$this->assertSame( SettingsManager::DEFAULT_MEMORY_TTL, $options->get( SettingsManager::OPTION_MEMORY_TTL ) );
	}

	public function test_initialize_defaults_does_not_overwrite_existing_memory_limit(): void {
		$options = new FakeOptions(
			array(
				SettingsManager::OPTION_MEMORY_LIMIT => 15,
			)
		);
		$settings = new SettingsManager( $options );

		$settings->initializeDefaults();

		$this->assertSame( 15, $options->get( SettingsManager::OPTION_MEMORY_LIMIT ) );
	}

	public function test_initialize_defaults_does_not_overwrite_existing_memory_ttl(): void {
		$options = new FakeOptions(
			array(
				SettingsManager::OPTION_MEMORY_TTL => 5000,
			)
		);
		$settings = new SettingsManager( $options );

		$settings->initializeDefaults();

		$this->assertSame( 5000, $options->get( SettingsManager::OPTION_MEMORY_TTL ) );
	}

	public function test_default_constants_have_safe_values(): void {
		// Verify default values are sane.
		$this->assertSame( 5, SettingsManager::DEFAULT_MEMORY_LIMIT );
		$this->assertSame( 1800, SettingsManager::DEFAULT_MEMORY_TTL );
	}
}
