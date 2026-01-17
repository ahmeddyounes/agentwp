<?php
/**
 * Container tag functionality tests.
 */

namespace AgentWP\Tests\Unit\Container;

use AgentWP\Container\Container;
use AgentWP\Tests\TestCase;

class ContainerTagsTest extends TestCase {

	public function test_tagged_with_keys_returns_empty_array_for_unknown_tag(): void {
		$container = new Container();

		$this->assertSame( array(), $container->taggedWithKeys( 'unknown.tag' ) );
	}

	public function test_tagged_with_keys_returns_services_keyed_by_context_key(): void {
		$container = new Container();

		$service1 = new \stdClass();
		$service1->name = 'service1';

		$service2 = new \stdClass();
		$service2->name = 'service2';

		$container->instance( 'ServiceOne', $service1 );
		$container->instance( 'ServiceTwo', $service2 );

		$container->tag( 'ServiceOne', 'my.tag', 'first' );
		$container->tag( 'ServiceTwo', 'my.tag', 'second' );

		$tagged = $container->taggedWithKeys( 'my.tag' );

		$this->assertCount( 2, $tagged );
		$this->assertArrayHasKey( 'first', $tagged );
		$this->assertArrayHasKey( 'second', $tagged );
		$this->assertSame( $service1, $tagged['first'] );
		$this->assertSame( $service2, $tagged['second'] );
	}

	public function test_tagged_with_keys_preserves_registration_order(): void {
		$container = new Container();

		$container->instance( 'ServiceA', new \stdClass() );
		$container->instance( 'ServiceB', new \stdClass() );
		$container->instance( 'ServiceC', new \stdClass() );

		// Register in specific order.
		$container->tag( 'ServiceA', 'ordered.tag', 'alpha' );
		$container->tag( 'ServiceB', 'ordered.tag', 'beta' );
		$container->tag( 'ServiceC', 'ordered.tag', 'gamma' );

		$tagged = $container->taggedWithKeys( 'ordered.tag' );
		$keys = array_keys( $tagged );

		$this->assertSame( array( 'alpha', 'beta', 'gamma' ), $keys );
	}

	public function test_tagged_with_keys_uses_service_id_when_no_context_key(): void {
		$container = new Container();

		$service = new \stdClass();
		$container->instance( 'MyService', $service );

		// Tag without context key.
		$container->tag( 'MyService', 'my.tag' );

		$tagged = $container->taggedWithKeys( 'my.tag' );

		$this->assertArrayHasKey( 'MyService', $tagged );
		$this->assertSame( $service, $tagged['MyService'] );
	}

	public function test_tagged_with_keys_mixed_with_and_without_context_keys(): void {
		$container = new Container();

		$service1 = new \stdClass();
		$service2 = new \stdClass();

		$container->instance( 'ServiceWithKey', $service1 );
		$container->instance( 'ServiceWithoutKey', $service2 );

		$container->tag( 'ServiceWithKey', 'mixed.tag', 'custom_key' );
		$container->tag( 'ServiceWithoutKey', 'mixed.tag' );

		$tagged = $container->taggedWithKeys( 'mixed.tag' );

		$this->assertArrayHasKey( 'custom_key', $tagged );
		$this->assertArrayHasKey( 'ServiceWithoutKey', $tagged );
	}

	public function test_flush_clears_tag_keys(): void {
		$container = new Container();

		$container->instance( 'Service', new \stdClass() );
		$container->tag( 'Service', 'my.tag', 'key' );

		$this->assertNotEmpty( $container->taggedWithKeys( 'my.tag' ) );

		$container->flush();

		$this->assertSame( array(), $container->taggedWithKeys( 'my.tag' ) );
	}

	public function test_tagged_still_works_for_backward_compatibility(): void {
		$container = new Container();

		$service1 = new \stdClass();
		$service2 = new \stdClass();

		$container->instance( 'Service1', $service1 );
		$container->instance( 'Service2', $service2 );

		$container->tag( 'Service1', 'compat.tag', 'key1' );
		$container->tag( 'Service2', 'compat.tag', 'key2' );

		// tagged() returns indexed array, not keyed.
		$tagged = $container->tagged( 'compat.tag' );

		$this->assertCount( 2, $tagged );
		$this->assertContains( $service1, $tagged );
		$this->assertContains( $service2, $tagged );
	}
}
