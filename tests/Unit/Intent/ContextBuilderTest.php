<?php
/**
 * ContextBuilder unit tests.
 */

namespace AgentWP\Tests\Unit\Intent;

use AgentWP\Intent\ContextBuilder;
use AgentWP\Intent\ContextProviders\ContextProviderInterface;
use AgentWP\Tests\TestCase;

class ContextBuilderTest extends TestCase {

	public function test_build_returns_request_and_metadata_with_no_providers(): void {
		$builder = new ContextBuilder();

		$result = $builder->build(
			array( 'query' => 'test' ),
			array( 'source' => 'api' )
		);

		$this->assertArrayHasKey( 'request', $result );
		$this->assertArrayHasKey( 'metadata', $result );
		$this->assertSame( array( 'query' => 'test' ), $result['request'] );
		$this->assertSame( array( 'source' => 'api' ), $result['metadata'] );
	}

	public function test_build_applies_providers_with_correct_keys(): void {
		$userProvider = $this->createMockProvider( array( 'id' => 1, 'name' => 'John' ) );
		$storeProvider = $this->createMockProvider( array( 'currency' => 'USD' ) );

		$builder = new ContextBuilder(
			array(
				'user' => $userProvider,
				'store' => $storeProvider,
			)
		);

		$result = $builder->build();

		$this->assertArrayHasKey( 'user', $result );
		$this->assertArrayHasKey( 'store', $result );
		$this->assertSame( array( 'id' => 1, 'name' => 'John' ), $result['user'] );
		$this->assertSame( array( 'currency' => 'USD' ), $result['store'] );
	}

	public function test_build_preserves_provider_order(): void {
		$providerA = $this->createMockProvider( array( 'a' => 1 ) );
		$providerB = $this->createMockProvider( array( 'b' => 2 ) );
		$providerC = $this->createMockProvider( array( 'c' => 3 ) );

		$builder = new ContextBuilder(
			array(
				'alpha' => $providerA,
				'beta' => $providerB,
				'gamma' => $providerC,
			)
		);

		$result = $builder->build();
		$keys = array_keys( $result );

		// First two are always 'request' and 'metadata'.
		$this->assertSame( 'request', $keys[0] );
		$this->assertSame( 'metadata', $keys[1] );
		// Then providers in order.
		$this->assertSame( 'alpha', $keys[2] );
		$this->assertSame( 'beta', $keys[3] );
		$this->assertSame( 'gamma', $keys[4] );
	}

	public function test_add_provider_adds_at_runtime(): void {
		$builder = new ContextBuilder();

		$provider = $this->createMockProvider( array( 'custom' => 'data' ) );
		$builder->add_provider( 'custom', $provider );

		$result = $builder->build();

		$this->assertArrayHasKey( 'custom', $result );
		$this->assertSame( array( 'custom' => 'data' ), $result['custom'] );
	}

	public function test_add_provider_throws_on_empty_key(): void {
		$builder = new ContextBuilder();
		$provider = $this->createMockProvider( array() );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Provider key cannot be empty' );

		$builder->add_provider( '', $provider );
	}

	public function test_providers_receive_context_and_metadata(): void {
		$receivedContext = null;
		$receivedMetadata = null;

		$provider = new class( $receivedContext, $receivedMetadata ) implements ContextProviderInterface {
			private $contextRef;
			private $metadataRef;

			public function __construct( &$contextRef, &$metadataRef ) {
				$this->contextRef = &$contextRef;
				$this->metadataRef = &$metadataRef;
			}

			public function provide( array $context, array $metadata ): array {
				$this->contextRef = $context;
				$this->metadataRef = $metadata;
				return array( 'received' => true );
			}
		};

		// Create provider that captures args.
		$capturedContext = null;
		$capturedMetadata = null;

		$captureProvider = new class() implements ContextProviderInterface {
			public $lastContext = null;
			public $lastMetadata = null;

			public function provide( array $context, array $metadata ): array {
				$this->lastContext = $context;
				$this->lastMetadata = $metadata;
				return array( 'captured' => true );
			}
		};

		$builder = new ContextBuilder( array( 'capture' => $captureProvider ) );

		$builder->build(
			array( 'query' => 'test query' ),
			array( 'orders_limit' => 10 )
		);

		$this->assertSame( array( 'query' => 'test query' ), $captureProvider->lastContext );
		$this->assertSame( array( 'orders_limit' => 10 ), $captureProvider->lastMetadata );
	}

	public function test_non_provider_instances_are_skipped(): void {
		$validProvider = $this->createMockProvider( array( 'valid' => true ) );

		// Mix in a non-provider object.
		$builder = new ContextBuilder(
			array(
				'valid' => $validProvider,
				'invalid' => new \stdClass(),
			)
		);

		$result = $builder->build();

		$this->assertArrayHasKey( 'valid', $result );
		$this->assertArrayNotHasKey( 'invalid', $result );
	}

	private function createMockProvider( array $returnData ): ContextProviderInterface {
		return new class( $returnData ) implements ContextProviderInterface {
			private array $data;

			public function __construct( array $data ) {
				$this->data = $data;
			}

			public function provide( array $context, array $metadata ): array {
				return $this->data;
			}
		};
	}
}
