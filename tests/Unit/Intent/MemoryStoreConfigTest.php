<?php
/**
 * Memory store configuration unit tests.
 */

namespace AgentWP\Tests\Unit\Intent;

use AgentWP\Container\Container;
use AgentWP\Contracts\MemoryStoreInterface;
use AgentWP\Contracts\OptionsInterface;
use AgentWP\Infrastructure\WPFunctions;
use AgentWP\Intent\MemoryStore;
use AgentWP\Plugin\SettingsManager;
use AgentWP\Providers\IntentServiceProvider;
use AgentWP\Tests\Fakes\FakeOptions;
use AgentWP\Tests\Fakes\FakeWPFunctions;
use AgentWP\Tests\TestCase;

class MemoryStoreConfigTest extends TestCase {

	public function test_memory_store_uses_settings_manager_values(): void {
		$options = new FakeOptions(
			array(
				SettingsManager::OPTION_MEMORY_LIMIT => 10,
				SettingsManager::OPTION_MEMORY_TTL   => 3600,
			)
		);

		$container = new Container();
		$container->singleton( OptionsInterface::class, fn() => $options );
		$container->singleton( SettingsManager::class, fn( $c ) => new SettingsManager( $c->get( OptionsInterface::class ) ) );
		$container->singleton( WPFunctions::class, fn() => new FakeWPFunctions() );

		$provider = new IntentServiceProvider( $container );
		$provider->register();

		$this->assertTrue( $container->has( MemoryStoreInterface::class ) );
		$memoryStore = $container->get( MemoryStoreInterface::class );

		$this->assertInstanceOf( MemoryStore::class, $memoryStore );
	}

	public function test_memory_store_uses_defaults_without_settings_manager(): void {
		$container = new Container();
		$container->singleton( WPFunctions::class, fn() => new FakeWPFunctions() );

		$provider = new IntentServiceProvider( $container );
		$provider->register();

		$this->assertTrue( $container->has( MemoryStoreInterface::class ) );
		$memoryStore = $container->get( MemoryStoreInterface::class );

		$this->assertInstanceOf( MemoryStore::class, $memoryStore );
	}

	public function test_memory_store_applies_limit_filter(): void {
		$wp = new FakeWPFunctions();
		$wp->setFilterReturn( 'agentwp_memory_limit', 25 );

		$options   = new FakeOptions();
		$container = new Container();
		$container->singleton( OptionsInterface::class, fn() => $options );
		$container->singleton( SettingsManager::class, fn( $c ) => new SettingsManager( $c->get( OptionsInterface::class ) ) );
		$container->singleton( WPFunctions::class, fn() => $wp );

		$provider = new IntentServiceProvider( $container );
		$provider->register();

		$container->get( MemoryStoreInterface::class );

		$this->assertTrue( $wp->wasFilterApplied( 'agentwp_memory_limit' ) );
	}

	public function test_memory_store_applies_ttl_filter(): void {
		$wp = new FakeWPFunctions();
		$wp->setFilterReturn( 'agentwp_memory_ttl', 7200 );

		$options   = new FakeOptions();
		$container = new Container();
		$container->singleton( OptionsInterface::class, fn() => $options );
		$container->singleton( SettingsManager::class, fn( $c ) => new SettingsManager( $c->get( OptionsInterface::class ) ) );
		$container->singleton( WPFunctions::class, fn() => $wp );

		$provider = new IntentServiceProvider( $container );
		$provider->register();

		$container->get( MemoryStoreInterface::class );

		$this->assertTrue( $wp->wasFilterApplied( 'agentwp_memory_ttl' ) );
	}

	public function test_memory_store_passes_settings_to_filter(): void {
		$wp = new FakeWPFunctions();

		$options = new FakeOptions(
			array(
				SettingsManager::OPTION_MEMORY_LIMIT => 15,
				SettingsManager::OPTION_MEMORY_TTL   => 2400,
			)
		);

		$container = new Container();
		$container->singleton( OptionsInterface::class, fn() => $options );
		$container->singleton( SettingsManager::class, fn( $c ) => new SettingsManager( $c->get( OptionsInterface::class ) ) );
		$container->singleton( WPFunctions::class, fn() => $wp );

		$provider = new IntentServiceProvider( $container );
		$provider->register();

		$container->get( MemoryStoreInterface::class );

		// Check that filters were applied with settings values.
		$limitFilter = null;
		$ttlFilter   = null;

		foreach ( $wp->filters as $filter ) {
			if ( 'agentwp_memory_limit' === $filter['hook'] ) {
				$limitFilter = $filter;
			}
			if ( 'agentwp_memory_ttl' === $filter['hook'] ) {
				$ttlFilter = $filter;
			}
		}

		$this->assertNotNull( $limitFilter, 'agentwp_memory_limit filter should be applied' );
		$this->assertNotNull( $ttlFilter, 'agentwp_memory_ttl filter should be applied' );
		$this->assertSame( 15, $limitFilter['value'] );
		$this->assertSame( 2400, $ttlFilter['value'] );
	}

	public function test_memory_store_is_singleton(): void {
		$options   = new FakeOptions();
		$container = new Container();
		$container->singleton( OptionsInterface::class, fn() => $options );
		$container->singleton( SettingsManager::class, fn( $c ) => new SettingsManager( $c->get( OptionsInterface::class ) ) );
		$container->singleton( WPFunctions::class, fn() => new FakeWPFunctions() );

		$provider = new IntentServiceProvider( $container );
		$provider->register();

		$first  = $container->get( MemoryStoreInterface::class );
		$second = $container->get( MemoryStoreInterface::class );

		$this->assertSame( $first, $second );
	}
}
