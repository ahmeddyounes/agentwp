<?php
/**
 * Unit tests for TransientDraftStorage class.
 */

namespace AgentWP\Tests\Unit\Infrastructure;

use AgentWP\Contracts\CurrentUserContextInterface;
use AgentWP\Infrastructure\TransientDraftStorage;
use AgentWP\Tests\TestCase;
use WP_Mock;

class TransientDraftStorageTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	public function test_build_key_uses_injected_user_context(): void {
		$userContext = $this->createMock( CurrentUserContextInterface::class );
		$userContext
			->expects( $this->once() )
			->method( 'getUserId' )
			->willReturn( 42 );

		WP_Mock::userFunction( 'get_transient' )
			->once()
			->with( 'agentwp_test_draft_42_draft123' )
			->andReturn( false );

		$storage = new TransientDraftStorage( $userContext );
		$storage->get( 'test', 'draft123' );

		// Assertion is implicitly verified by the mock expectation on get_transient with the correct key
		$this->assertTrue( true );
	}

	public function test_build_key_falls_back_to_wp_global_without_context(): void {
		WP_Mock::userFunction( 'get_current_user_id' )
			->once()
			->andReturn( 99 );

		WP_Mock::userFunction( 'get_transient' )
			->once()
			->with( 'agentwp_test_draft_99_draft456' )
			->andReturn( false );

		$storage = new TransientDraftStorage( null );
		$storage->get( 'test', 'draft456' );

		// Assertion is implicitly verified by the mock expectation
		$this->assertTrue( true );
	}

	public function test_store_uses_injected_user_context(): void {
		$userContext = $this->createMock( CurrentUserContextInterface::class );
		$userContext
			->expects( $this->once() )
			->method( 'getUserId' )
			->willReturn( 7 );

		WP_Mock::userFunction( 'set_transient' )
			->once()
			->with( 'agentwp_stock_draft_7_draftABC', array( 'data' => 'value' ), 3600 )
			->andReturn( true );

		$storage = new TransientDraftStorage( $userContext );
		$result  = $storage->store( 'stock', 'draftABC', array( 'data' => 'value' ) );

		$this->assertTrue( $result );
	}

	public function test_claim_uses_injected_user_context(): void {
		$userContext = $this->createMock( CurrentUserContextInterface::class );
		$userContext
			->expects( $this->once() )
			->method( 'getUserId' )
			->willReturn( 15 );

		$draftData = array( 'order_id' => 123 );

		WP_Mock::userFunction( 'get_transient' )
			->once()
			->with( 'agentwp_refund_draft_15_claimMe' )
			->andReturn( $draftData );

		WP_Mock::userFunction( 'delete_transient' )
			->once()
			->with( 'agentwp_refund_draft_15_claimMe' )
			->andReturn( true );

		$storage = new TransientDraftStorage( $userContext );
		$result  = $storage->claim( 'refund', 'claimMe' );

		$this->assertSame( $draftData, $result );
	}

	public function test_delete_uses_injected_user_context(): void {
		$userContext = $this->createMock( CurrentUserContextInterface::class );
		$userContext
			->expects( $this->once() )
			->method( 'getUserId' )
			->willReturn( 22 );

		WP_Mock::userFunction( 'delete_transient' )
			->once()
			->with( 'agentwp_status_draft_22_toDelete' )
			->andReturn( true );

		$storage = new TransientDraftStorage( $userContext );
		$result  = $storage->delete( 'status', 'toDelete' );

		$this->assertTrue( $result );
	}

	public function test_generate_id_creates_unique_prefixed_id(): void {
		WP_Mock::userFunction( 'wp_generate_password' )
			->once()
			->with( 12, false )
			->andReturn( 'abc123xyz789' );

		$storage = new TransientDraftStorage( null );
		$result  = $storage->generate_id( 'test' );

		$this->assertSame( 'test_abc123xyz789', $result );
	}
}
