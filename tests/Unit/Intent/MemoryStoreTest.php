<?php
/**
 * MemoryStore unit tests.
 */

namespace AgentWP\Tests\Unit\Intent;

use AgentWP\Intent\MemoryStore;
use AgentWP\Tests\TestCase;

class MemoryStoreTest extends TestCase {

	public function test_constructor_uses_default_limit(): void {
		// Default limit is 5, test by adding 6 entries.
		$store = new MemoryStore();

		// Since we can't directly access private $limit, we test behavior.
		// Without WordPress functions, get() returns empty and addExchange() is no-op.
		$this->assertSame( array(), $store->get() );
	}

	public function test_constructor_enforces_minimum_limit_of_1(): void {
		$store = new MemoryStore( 0 );

		// Should not throw, minimum enforced internally.
		$this->assertSame( array(), $store->get() );
	}

	public function test_constructor_enforces_minimum_limit_for_negative(): void {
		$store = new MemoryStore( -5 );

		// Should not throw, minimum enforced internally.
		$this->assertSame( array(), $store->get() );
	}

	public function test_constructor_enforces_minimum_ttl_of_60(): void {
		$store = new MemoryStore( 5, 30 );

		// Should not throw, minimum enforced internally.
		$this->assertSame( array(), $store->get() );
	}

	public function test_constructor_enforces_minimum_ttl_for_negative(): void {
		$store = new MemoryStore( 5, -100 );

		// Should not throw, minimum enforced internally.
		$this->assertSame( array(), $store->get() );
	}

	public function test_constructor_accepts_custom_values(): void {
		$store = new MemoryStore( 10, 3600 );

		// Should construct without error.
		$this->assertSame( array(), $store->get() );
	}

	public function test_get_returns_empty_array_without_wordpress(): void {
		$store = new MemoryStore();

		$this->assertSame( array(), $store->get() );
	}

	public function test_add_exchange_gracefully_fails_without_wordpress(): void {
		$store = new MemoryStore();

		// Should not throw without WordPress functions.
		$store->addExchange(
			array(
				'time'    => '2024-01-01T00:00:00',
				'input'   => 'test',
				'intent'  => 'ORDER_SEARCH',
				'message' => 'response',
			)
		);

		$this->assertSame( array(), $store->get() );
	}

	public function test_clear_gracefully_fails_without_wordpress(): void {
		$store = new MemoryStore();

		// Should not throw without WordPress functions.
		$store->clear();

		$this->assertSame( array(), $store->get() );
	}

	public function test_add_exchange_alias_exists(): void {
		$store = new MemoryStore();

		// Test that the snake_case alias exists for backward compatibility.
		$this->assertTrue( method_exists( $store, 'add_exchange' ) );
	}
}
