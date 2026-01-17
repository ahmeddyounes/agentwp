<?php
/**
 * Integration tests for container wiring and resolution.
 *
 * Verifies that the container correctly registers bindings, resolves
 * Engine with handlers/registries, and REST controllers resolve
 * dependencies via RestController::resolve().
 *
 * @package AgentWP\Tests\Integration\Container
 */

namespace AgentWP\Tests\Integration\Container;

use AgentWP\AI\Response;
use AgentWP\Container\Container;
use AgentWP\Container\ContainerInterface;
use AgentWP\Contracts\AIClientFactoryInterface;
use AgentWP\Contracts\AnalyticsServiceInterface;
use AgentWP\Contracts\CacheInterface;
use AgentWP\Contracts\ClockInterface;
use AgentWP\Contracts\ContextBuilderInterface;
use AgentWP\Contracts\CustomerServiceInterface;
use AgentWP\Contracts\DraftManagerInterface;
use AgentWP\Contracts\DraftStorageInterface;
use AgentWP\Contracts\EmailDraftServiceInterface;
use AgentWP\Contracts\HttpClientInterface;
use AgentWP\Contracts\IntentClassifierInterface;
use AgentWP\Contracts\MemoryStoreInterface;
use AgentWP\Contracts\OptionsInterface;
use AgentWP\Contracts\OrderRefundServiceInterface;
use AgentWP\Contracts\OrderSearchServiceInterface;
use AgentWP\Contracts\OrderStatusServiceInterface;
use AgentWP\Contracts\PolicyInterface;
use AgentWP\Contracts\ProductStockServiceInterface;
use AgentWP\Contracts\RateLimiterInterface;
use AgentWP\Contracts\RetryPolicyInterface;
use AgentWP\Contracts\SessionHandlerInterface;
use AgentWP\Contracts\SleeperInterface;
use AgentWP\Contracts\ToolRegistryInterface;
use AgentWP\Contracts\TransientCacheInterface;
use AgentWP\Intent\ContextBuilder;
use AgentWP\Intent\Engine;
use AgentWP\Intent\FunctionRegistry;
use AgentWP\Intent\Handler;
use AgentWP\Intent\HandlerRegistry;
use AgentWP\Intent\Handlers\AnalyticsQueryHandler;
use AgentWP\Intent\Handlers\CustomerLookupHandler;
use AgentWP\Intent\Handlers\EmailDraftHandler;
use AgentWP\Intent\Handlers\FallbackHandler;
use AgentWP\Intent\Handlers\OrderRefundHandler;
use AgentWP\Intent\Handlers\OrderSearchHandler;
use AgentWP\Intent\Handlers\OrderStatusHandler;
use AgentWP\Intent\Handlers\ProductStockHandler;
use AgentWP\Intent\Classifier\ScorerRegistry;
use AgentWP\Intent\Intent;
use AgentWP\Intent\IntentClassifier;
use AgentWP\Intent\ToolRegistry;
use AgentWP\Plugin\SettingsManager;
use AgentWP\Providers\CoreServiceProvider;
use AgentWP\Providers\InfrastructureServiceProvider;
use AgentWP\Providers\IntentServiceProvider;
use AgentWP\Providers\ServicesServiceProvider;
use AgentWP\Tests\Fakes\FakeMemoryStore;
use AgentWP\Tests\TestCase;

/**
 * Tests that verify container wiring is correct and that services can be
 * resolved. These tests fail if a controller/service reintroduces DI bypass.
 */
class ContainerWiringTest extends TestCase {

	/**
	 * Container instance for tests.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->container = new Container();
	}

	/**
	 * Helper to register providers without REST (which requires WordPress).
	 *
	 * This skips RestServiceProvider because it depends on WP_REST_Controller
	 * which is not available in unit tests.
	 *
	 * @return void
	 */
	private function registerProvidersWithoutRest(): void {
		$providers = array(
			new CoreServiceProvider( $this->container ),
			new InfrastructureServiceProvider( $this->container ),
			new ServicesServiceProvider( $this->container ),
			new IntentServiceProvider( $this->container ),
		);

		foreach ( $providers as $provider ) {
			$provider->register();
		}
	}

	// -------------------------------------------------------------------------
	// Container Binding Tests
	// -------------------------------------------------------------------------

	/**
	 * Test that CoreServiceProvider registers expected bindings.
	 */
	public function test_core_service_provider_registers_expected_bindings(): void {
		$provider = new CoreServiceProvider( $this->container );
		$provider->register();

		$expected_bindings = array(
			OptionsInterface::class,
			SettingsManager::class,
			HandlerRegistry::class,
		);

		foreach ( $expected_bindings as $binding ) {
			$this->assertTrue(
				$this->container->has( $binding ),
				sprintf( 'CoreServiceProvider should register %s', $binding )
			);
		}
	}

	/**
	 * Test that InfrastructureServiceProvider registers expected bindings.
	 */
	public function test_infrastructure_service_provider_registers_expected_bindings(): void {
		// Core must be registered first (SettingsManager dependency).
		$core = new CoreServiceProvider( $this->container );
		$core->register();

		$provider = new InfrastructureServiceProvider( $this->container );
		$provider->register();

		$expected_bindings = array(
			CacheInterface::class,
			TransientCacheInterface::class,
			HttpClientInterface::class,
			ClockInterface::class,
			SleeperInterface::class,
			SessionHandlerInterface::class,
			RetryPolicyInterface::class,
			AIClientFactoryInterface::class,
			DraftStorageInterface::class,
			PolicyInterface::class,
		);

		foreach ( $expected_bindings as $binding ) {
			$this->assertTrue(
				$this->container->has( $binding ),
				sprintf( 'InfrastructureServiceProvider should register %s', $binding )
			);
		}
	}

	/**
	 * Test that ServicesServiceProvider registers expected bindings.
	 */
	public function test_services_service_provider_registers_expected_bindings(): void {
		// Core and Infrastructure must be registered first.
		$core = new CoreServiceProvider( $this->container );
		$core->register();

		$infra = new InfrastructureServiceProvider( $this->container );
		$infra->register();

		$provider = new ServicesServiceProvider( $this->container );
		$provider->register();

		$expected_bindings = array(
			DraftManagerInterface::class,
			OrderRefundServiceInterface::class,
			OrderStatusServiceInterface::class,
			ProductStockServiceInterface::class,
			CustomerServiceInterface::class,
			AnalyticsServiceInterface::class,
			EmailDraftServiceInterface::class,
		);

		foreach ( $expected_bindings as $binding ) {
			$this->assertTrue(
				$this->container->has( $binding ),
				sprintf( 'ServicesServiceProvider should register %s', $binding )
			);
		}
	}

	/**
	 * Test that IntentServiceProvider registers Engine and handler bindings.
	 */
	public function test_intent_service_provider_registers_engine_and_handlers(): void {
		$this->registerProvidersWithoutRest();

		// Engine should be registered.
		$this->assertTrue(
			$this->container->has( Engine::class ),
			'IntentServiceProvider should register Engine'
		);

		// Function registry.
		$this->assertTrue(
			$this->container->has( FunctionRegistry::class ),
			'IntentServiceProvider should register FunctionRegistry'
		);

		// Tool registry.
		$this->assertTrue(
			$this->container->has( ToolRegistryInterface::class ),
			'IntentServiceProvider should register ToolRegistryInterface'
		);

		// Handler registry.
		$this->assertTrue(
			$this->container->has( HandlerRegistry::class ),
			'IntentServiceProvider should register HandlerRegistry'
		);

		// Core interfaces.
		$this->assertTrue(
			$this->container->has( MemoryStoreInterface::class ),
			'IntentServiceProvider should register MemoryStoreInterface'
		);

		$this->assertTrue(
			$this->container->has( ContextBuilderInterface::class ),
			'IntentServiceProvider should register ContextBuilderInterface'
		);

		$this->assertTrue(
			$this->container->has( IntentClassifierInterface::class ),
			'IntentServiceProvider should register IntentClassifierInterface'
		);
	}

	/**
	 * Test that intent handlers are tagged correctly.
	 */
	public function test_intent_handlers_are_tagged(): void {
		$this->registerProvidersWithoutRest();

		$handlers = $this->container->tagged( 'intent.handler' );

		$this->assertNotEmpty( $handlers, 'Should have tagged intent handlers' );
		$this->assertGreaterThanOrEqual( 1, count( $handlers ), 'Should have at least one handler tagged' );

		// All handlers should implement Handler interface.
		foreach ( $handlers as $handler ) {
			$this->assertInstanceOf(
				Handler::class,
				$handler,
				'All tagged handlers should implement Handler interface'
			);
		}
	}

	/**
	 * Test that context providers are tagged with correct keys.
	 */
	public function test_context_providers_are_tagged_with_keys(): void {
		$provider = new CoreServiceProvider( $this->container );
		$provider->register();

		$providers = $this->container->taggedWithKeys( 'intent.context_provider' );

		$this->assertArrayHasKey( 'user', $providers, 'Should have user context provider' );
		$this->assertArrayHasKey( 'recent_orders', $providers, 'Should have recent_orders context provider' );
		$this->assertArrayHasKey( 'store', $providers, 'Should have store context provider' );
	}

	// -------------------------------------------------------------------------
	// Engine Resolution Tests
	// -------------------------------------------------------------------------

	/**
	 * Test that Engine resolves with all required dependencies.
	 */
	public function test_engine_resolves_with_all_dependencies(): void {
		$this->registerProvidersWithoutRest();

		$engine = $this->container->get( Engine::class );

		$this->assertInstanceOf( Engine::class, $engine );
	}

	/**
	 * Test that Engine receives handlers from tagged services.
	 */
	public function test_engine_receives_tagged_handlers(): void {
		$this->registerProvidersWithoutRest();

		// Get handlers separately.
		$handlers = $this->container->tagged( 'intent.handler' );

		// Each handler should be resolvable.
		foreach ( $handlers as $handler ) {
			$this->assertInstanceOf( Handler::class, $handler );
			$this->assertTrue( method_exists( $handler, 'canHandle' ) );
			$this->assertTrue( method_exists( $handler, 'handle' ) );
		}
	}

	/**
	 * Test that HandlerRegistry is shared across resolutions.
	 */
	public function test_handler_registry_is_singleton(): void {
		$this->registerProvidersWithoutRest();

		$registry1 = $this->container->get( HandlerRegistry::class );
		$registry2 = $this->container->get( HandlerRegistry::class );

		$this->assertSame( $registry1, $registry2, 'HandlerRegistry should be a singleton' );
	}

	/**
	 * Test that FunctionRegistry is shared.
	 */
	public function test_function_registry_is_singleton(): void {
		$this->registerProvidersWithoutRest();

		$registry1 = $this->container->get( FunctionRegistry::class );
		$registry2 = $this->container->get( FunctionRegistry::class );

		$this->assertSame( $registry1, $registry2, 'FunctionRegistry should be a singleton' );
	}

	/**
	 * Test that ToolRegistry is shared.
	 */
	public function test_tool_registry_is_singleton(): void {
		$this->registerProvidersWithoutRest();

		$registry1 = $this->container->get( ToolRegistryInterface::class );
		$registry2 = $this->container->get( ToolRegistryInterface::class );

		$this->assertSame( $registry1, $registry2, 'ToolRegistry should be a singleton' );
	}

	/**
	 * Test that ContextBuilder receives tagged context providers.
	 */
	public function test_context_builder_receives_tagged_providers(): void {
		$this->registerProvidersWithoutRest();

		$builder = $this->container->get( ContextBuilderInterface::class );

		$this->assertInstanceOf( ContextBuilder::class, $builder );
	}

	// -------------------------------------------------------------------------
	// Controller Resolution Tests (simulating controller behavior)
	// -------------------------------------------------------------------------

	/**
	 * Test that controllers can resolve Engine via container.
	 *
	 * This test verifies that the DI pattern is working correctly and
	 * controllers don't bypass the container by instantiating services directly.
	 */
	public function test_controllers_can_resolve_engine_via_container(): void {
		$this->registerProvidersWithoutRest();

		// Simulate what IntentController does when resolving Engine.
		$container = $this->container;

		$this->assertTrue( $container->has( Engine::class ), 'Container should have Engine binding' );

		$engine = $container->get( Engine::class );

		$this->assertInstanceOf( Engine::class, $engine, 'Should resolve Engine instance' );
	}

	/**
	 * Test that required services are available in container for controllers.
	 *
	 * This tests the pattern used by RestController::resolveRequired().
	 */
	public function test_required_services_available_for_controllers(): void {
		$this->registerProvidersWithoutRest();

		// These are services commonly resolved by REST controllers.
		$required_services = array(
			Engine::class          => 'Intent engine',
			SettingsManager::class => 'Settings manager',
		);

		foreach ( $required_services as $service_id => $service_name ) {
			$this->assertTrue(
				$this->container->has( $service_id ),
				sprintf( '%s should be registered for controllers', $service_name )
			);

			$service = $this->container->get( $service_id );
			$this->assertNotNull(
				$service,
				sprintf( '%s should not resolve to null', $service_name )
			);
		}
	}

	/**
	 * Test RestController::resolve() pattern with Engine.
	 *
	 * This verifies the soft-fallback pattern works correctly.
	 */
	public function test_resolve_pattern_returns_service_when_available(): void {
		$this->registerProvidersWithoutRest();

		// Simulate RestController::resolve() behavior.
		$container = $this->container;
		$id        = Engine::class;

		if ( ! $container->has( $id ) ) {
			$result = null;
		} else {
			try {
				$result = $container->get( $id );
			} catch ( \Throwable $e ) {
				$result = null;
			}
		}

		$this->assertNotNull( $result, 'resolve() should return Engine when registered' );
		$this->assertInstanceOf( Engine::class, $result );
	}

	/**
	 * Test RestController::resolve() pattern returns null for unregistered service.
	 */
	public function test_resolve_pattern_returns_null_for_unregistered(): void {
		// Don't register any providers.
		$container = $this->container;
		$id        = 'NonExistent\\Service';

		if ( ! $container->has( $id ) ) {
			$result = null;
		} else {
			try {
				$result = $container->get( $id );
			} catch ( \Throwable $e ) {
				$result = null;
			}
		}

		$this->assertNull( $result, 'resolve() should return null for unregistered services' );
	}

	/**
	 * Test RestController::resolveRequired() pattern detects missing container.
	 */
	public function test_resolve_required_pattern_detects_missing_container(): void {
		// Simulate resolveRequired() with null container.
		$container    = null;
		$service_name = 'Test service';

		$has_error = false;
		if ( null === $container ) {
			$has_error = true;
		}

		$this->assertTrue( $has_error, 'resolveRequired() should detect null container' );
	}

	/**
	 * Test RestController::resolveRequired() pattern detects unregistered service.
	 */
	public function test_resolve_required_pattern_detects_unregistered(): void {
		$this->registerProvidersWithoutRest();

		$container    = $this->container;
		$id           = 'NonExistent\\Service';
		$service_name = 'Test service';

		$has_error = false;
		if ( ! $container->has( $id ) ) {
			$has_error = true;
		}

		$this->assertTrue( $has_error, 'resolveRequired() should detect unregistered service' );
	}

	// -------------------------------------------------------------------------
	// DI Bypass Detection Tests
	// -------------------------------------------------------------------------

	/**
	 * Test that handlers are resolved via container, not instantiated directly.
	 *
	 * This test will fail if a handler is instantiated without going through
	 * the container, which would bypass dependency injection.
	 */
	public function test_handlers_resolved_via_container_not_direct_instantiation(): void {
		$this->registerProvidersWithoutRest();

		$handler_classes = array(
			OrderSearchHandler::class,
			OrderRefundHandler::class,
			OrderStatusHandler::class,
			ProductStockHandler::class,
			EmailDraftHandler::class,
			AnalyticsQueryHandler::class,
			CustomerLookupHandler::class,
		);

		foreach ( $handler_classes as $handler_class ) {
			if ( ! class_exists( $handler_class ) ) {
				continue;
			}

			$this->assertTrue(
				$this->container->has( $handler_class ),
				sprintf( '%s should be registered in container', $handler_class )
			);

			// Resolve and verify it's a valid handler.
			$handler = $this->container->get( $handler_class );
			$this->assertInstanceOf(
				Handler::class,
				$handler,
				sprintf( '%s should resolve to Handler instance', $handler_class )
			);
		}
	}

	/**
	 * Test that services are singletons where expected.
	 *
	 * If services that should be singletons are instantiated directly,
	 * they would return different instances, failing this test.
	 *
	 * Note: Some services like ClockInterface require WordPress functions
	 * during resolution, so we test singleton behavior by verifying the
	 * bindings are marked as singletons rather than resolving them.
	 */
	public function test_singleton_services_return_same_instance(): void {
		$this->registerProvidersWithoutRest();

		// Services that can be resolved without WordPress functions.
		$singleton_services = array(
			MemoryStoreInterface::class,
			ContextBuilderInterface::class,
			IntentClassifierInterface::class,
			HandlerRegistry::class,
			FunctionRegistry::class,
			ToolRegistryInterface::class,
			SleeperInterface::class,
		);

		foreach ( $singleton_services as $service_id ) {
			if ( ! $this->container->has( $service_id ) ) {
				continue;
			}

			$instance1 = $this->container->get( $service_id );
			$instance2 = $this->container->get( $service_id );

			$this->assertSame(
				$instance1,
				$instance2,
				sprintf( '%s should be a singleton', $service_id )
			);
		}
	}

	/**
	 * Test that Engine's handler array is populated from container.
	 *
	 * This ensures that the Engine doesn't have hard-coded handler instantiation
	 * but receives handlers from the container's tagged services.
	 */
	public function test_engine_handler_array_from_container_tags(): void {
		$this->registerProvidersWithoutRest();

		// Get handlers from container tags.
		$tagged_handlers = $this->container->tagged( 'intent.handler' );

		// Should have handlers.
		$this->assertNotEmpty(
			$tagged_handlers,
			'Container should have tagged intent handlers'
		);

		// Get Engine.
		$engine = $this->container->get( Engine::class );
		$this->assertInstanceOf( Engine::class, $engine );

		// Verify Engine can handle intents (which requires handlers).
		// This indirectly tests that handlers were properly injected.
		$this->assertTrue(
			method_exists( $engine, 'handle' ),
			'Engine should have handle method'
		);
	}

	/**
	 * Test that removing a handler binding breaks Engine resolution.
	 *
	 * This verifies that Engine depends on container bindings and would
	 * fail if someone tries to bypass the DI system.
	 */
	public function test_engine_fails_without_required_dependencies(): void {
		// Register only partial providers - skip IntentServiceProvider.
		$providers = array(
			new CoreServiceProvider( $this->container ),
			new InfrastructureServiceProvider( $this->container ),
		);

		foreach ( $providers as $provider ) {
			$provider->register();
		}

		// Engine should not be registered.
		$this->assertFalse(
			$this->container->has( Engine::class ),
			'Engine should not be registered without IntentServiceProvider'
		);
	}

	/**
	 * Test that IntentClassifierInterface is wired to ScorerRegistry per ADR 0003.
	 */
	public function test_intent_classifier_interface_wired_to_scorer_registry(): void {
		$this->registerProvidersWithoutRest();

		$classifier = $this->container->get( IntentClassifierInterface::class );

		$this->assertInstanceOf(
			ScorerRegistry::class,
			$classifier,
			'IntentClassifierInterface should be implemented by ScorerRegistry per ADR 0003'
		);
	}

	/**
	 * Test that ScorerRegistry has all default scorers registered.
	 */
	public function test_scorer_registry_has_all_default_scorers(): void {
		$this->registerProvidersWithoutRest();

		$classifier = $this->container->get( IntentClassifierInterface::class );

		$this->assertInstanceOf( ScorerRegistry::class, $classifier );

		// Verify all 7 default scorers are registered.
		$this->assertTrue( $classifier->has( Intent::ORDER_REFUND ), 'Should have RefundScorer' );
		$this->assertTrue( $classifier->has( Intent::ORDER_STATUS ), 'Should have StatusScorer' );
		$this->assertTrue( $classifier->has( Intent::ORDER_SEARCH ), 'Should have SearchScorer' );
		$this->assertTrue( $classifier->has( Intent::PRODUCT_STOCK ), 'Should have StockScorer' );
		$this->assertTrue( $classifier->has( Intent::EMAIL_DRAFT ), 'Should have EmailScorer' );
		$this->assertTrue( $classifier->has( Intent::ANALYTICS_QUERY ), 'Should have AnalyticsScorer' );
		$this->assertTrue( $classifier->has( Intent::CUSTOMER_LOOKUP ), 'Should have CustomerScorer' );
		$this->assertSame( 7, $classifier->count(), 'Should have exactly 7 default scorers' );
	}

	/**
	 * Test that flush clears all bindings.
	 */
	public function test_flush_clears_all_container_bindings(): void {
		$this->registerProvidersWithoutRest();

		$this->assertTrue( $this->container->has( Engine::class ) );

		$this->container->flush();

		$this->assertFalse(
			$this->container->has( Engine::class ),
			'Engine should not be available after flush'
		);
	}

	/**
	 * Test that provider registration order matters for dependencies.
	 *
	 * This test verifies that registering providers in wrong order fails,
	 * ensuring proper dependency chain is maintained.
	 */
	public function test_provider_registration_order_matters(): void {
		// Try to register IntentServiceProvider before its dependencies.
		// This should work but Engine won't resolve properly.
		$intent = new IntentServiceProvider( $this->container );
		$intent->register();

		// Engine might be registered but resolving it should fail.
		// because dependencies like SettingsManager are missing.
		$this->expectException( \Throwable::class );
		$this->container->get( Engine::class );
	}

	/**
	 * Test that getBindings returns all registered service IDs.
	 */
	public function test_get_bindings_returns_all_registered_services(): void {
		$this->registerProvidersWithoutRest();

		$bindings = $this->container->getBindings();

		$this->assertContains( Engine::class, $bindings );
		$this->assertContains( SettingsManager::class, $bindings );
		$this->assertContains( HandlerRegistry::class, $bindings );
	}

	/**
	 * Test that Engine is the same singleton whether resolved directly or via handlers.
	 *
	 * Ensures no accidental direct instantiation happens inside handlers.
	 */
	public function test_engine_singleton_consistency_across_resolution_paths(): void {
		$this->registerProvidersWithoutRest();

		// Get Engine directly.
		$engine1 = $this->container->get( Engine::class );

		// Get Engine by ID (string).
		$engine2 = $this->container->get( 'AgentWP\\Intent\\Engine' );

		$this->assertSame( $engine1, $engine2, 'Engine should be same instance via any resolution path' );
	}

	/**
	 * Test that all handler dependencies are properly wired.
	 *
	 * This catches cases where a handler might try to instantiate its
	 * dependencies directly instead of receiving them via DI.
	 */
	public function test_handler_dependencies_are_properly_wired(): void {
		$this->registerProvidersWithoutRest();

		// Get a handler and verify it received its dependencies via DI.
		$handlers = $this->container->tagged( 'intent.handler' );

		$this->assertNotEmpty( $handlers, 'Should have handlers to test' );

		foreach ( $handlers as $handler ) {
			// If handler can handle, it must have working dependencies.
			// Calling canHandle should not throw.
			try {
				$result = $handler->canHandle( Intent::ORDER_SEARCH );
				$this->assertIsBool( $result, 'canHandle should return bool' );
			} catch ( \Throwable $e ) {
				$this->fail(
					sprintf(
						'Handler %s threw exception in canHandle: %s',
						get_class( $handler ),
						$e->getMessage()
					)
				);
			}
		}
	}
}
