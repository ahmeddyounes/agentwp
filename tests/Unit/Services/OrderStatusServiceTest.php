<?php
/**
 * Tests for OrderStatusService.
 *
 * @package AgentWP\Tests\Unit\Services
 */

namespace AgentWP\Tests\Unit\Services;

use AgentWP\DTO\ServiceResult;
use AgentWP\Services\OrderStatusService;
use AgentWP\Tests\Fakes\FakeDraftManager;
use AgentWP\Tests\Fakes\FakePolicy;
use AgentWP\Tests\Fakes\FakeWooCommerceOrderGateway;
use AgentWP\Tests\TestCase;

class OrderStatusServiceTest extends TestCase {

	private FakeDraftManager $draftManager;
	private FakePolicy $policy;
	private FakeWooCommerceOrderGateway $orderGateway;
	private OrderStatusService $service;

	public function setUp(): void {
		parent::setUp();

		$this->draftManager = new FakeDraftManager();
		$this->policy       = new FakePolicy();
		$this->orderGateway = new FakeWooCommerceOrderGateway();

		$this->service = new OrderStatusService(
			$this->draftManager,
			$this->policy,
			$this->orderGateway
		);
	}

	public function tearDown(): void {
		$this->draftManager->clear();
		$this->orderGateway->clear();
		parent::tearDown();
	}

	// ==========================================
	// prepare_update() tests
	// ==========================================

	public function test_prepare_update_returns_permission_denied_when_not_allowed(): void {
		$this->policy->setCapability( 'UpdateOrderStatus', false );

		$result = $this->service->prepare_update( 123, 'completed' );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ServiceResult::CODE_PERMISSION_DENIED, $result->code );
	}

	public function test_prepare_update_returns_not_found_for_missing_order(): void {
		$result = $this->service->prepare_update( 999, 'completed' );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ServiceResult::CODE_NOT_FOUND, $result->code );
		$this->assertStringContainsString( 'Order #999 not found', $result->message );
	}

	public function test_prepare_update_returns_invalid_input_for_empty_status(): void {
		$this->orderGateway->addOrder( 123, 'pending' );

		$result = $this->service->prepare_update( 123, '' );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ServiceResult::CODE_INVALID_INPUT, $result->code );
		$this->assertStringContainsString( 'Invalid status', $result->message );
	}

	public function test_prepare_update_returns_invalid_input_for_unknown_status(): void {
		$this->orderGateway->addOrder( 123, 'pending' );

		$result = $this->service->prepare_update( 123, 'nonexistent' );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ServiceResult::CODE_INVALID_INPUT, $result->code );
		$this->assertStringContainsString( 'Invalid status slug', $result->message );
	}

	public function test_prepare_update_returns_invalid_state_when_status_unchanged(): void {
		$this->orderGateway->addOrder( 123, 'processing' );

		$result = $this->service->prepare_update( 123, 'processing' );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ServiceResult::CODE_INVALID_STATE, $result->code );
		$this->assertStringContainsString( 'already has this status', $result->message );
	}

	public function test_prepare_update_succeeds_with_valid_status(): void {
		$this->orderGateway->addOrder( 123, 'pending' );

		$result = $this->service->prepare_update( 123, 'completed', 'Order shipped', true );

		$this->assertTrue( $result->isSuccess() );
		$this->assertArrayHasKey( 'draft_id', $result->data );
		$this->assertStringStartsWith( 'draft_', $result->get( 'draft_id' ) );
		$this->assertSame( 'status', $result->get( 'type' ) );

		$preview = $result->get( 'preview' );
		$this->assertSame( 123, $preview['order_id'] );
		$this->assertSame( 'pending', $preview['current_status'] );
		$this->assertSame( 'completed', $preview['new_status'] );
	}

	public function test_prepare_update_normalizes_wc_prefix_in_status(): void {
		$this->orderGateway->addOrder( 123, 'pending' );

		$result = $this->service->prepare_update( 123, 'wc-completed' );

		$this->assertTrue( $result->isSuccess() );

		$preview = $result->get( 'preview' );
		$this->assertSame( 'completed', $preview['new_status'] );
	}

	public function test_prepare_update_includes_irreversible_warning_for_cancelled(): void {
		$this->orderGateway->addOrder( 123, 'pending' );

		$result = $this->service->prepare_update( 123, 'cancelled' );

		$this->assertTrue( $result->isSuccess() );

		$preview = $result->get( 'preview' );
		$this->assertSame( 'Irreversible.', $preview['warning'] );
	}

	public function test_prepare_update_includes_irreversible_warning_for_refunded(): void {
		$this->orderGateway->addOrder( 123, 'pending' );

		$result = $this->service->prepare_update( 123, 'refunded' );

		$this->assertTrue( $result->isSuccess() );

		$preview = $result->get( 'preview' );
		$this->assertSame( 'Irreversible.', $preview['warning'] );
	}

	public function test_prepare_update_creates_draft_with_correct_payload(): void {
		$this->orderGateway->addOrder( 123, 'pending' );

		$result = $this->service->prepare_update( 123, 'processing', 'Started processing', false );

		$this->assertTrue( $result->isSuccess() );
		$this->assertSame( 1, $this->draftManager->getDraftCount() );

		$draft = $this->draftManager->get( 'status', $result->get( 'draft_id' ) );
		$this->assertNotNull( $draft );
		$this->assertSame( 123, $draft->payload['order_id'] );
		$this->assertSame( 'pending', $draft->payload['current_status'] );
		$this->assertSame( 'processing', $draft->payload['new_status'] );
		$this->assertSame( 'Started processing', $draft->payload['note'] );
		$this->assertFalse( $draft->payload['notify_customer'] );
	}

	// ==========================================
	// prepare_bulk_update() tests
	// ==========================================

	public function test_prepare_bulk_update_returns_permission_denied_when_not_allowed(): void {
		$this->policy->setCapability( 'UpdateOrderStatus', false );

		$result = $this->service->prepare_bulk_update( array( 1, 2, 3 ), 'completed' );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ServiceResult::CODE_PERMISSION_DENIED, $result->code );
	}

	public function test_prepare_bulk_update_returns_invalid_input_for_empty_order_ids(): void {
		$result = $this->service->prepare_bulk_update( array(), 'completed' );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ServiceResult::CODE_INVALID_INPUT, $result->code );
		$this->assertStringContainsString( 'No orders specified', $result->message );
	}

	public function test_prepare_bulk_update_returns_limit_exceeded_for_too_many_orders(): void {
		$order_ids = range( 1, 51 ); // Exceeds MAX_BULK of 50

		$result = $this->service->prepare_bulk_update( $order_ids, 'completed' );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ServiceResult::CODE_LIMIT_EXCEEDED, $result->code );
		$this->assertStringContainsString( 'Too many orders', $result->message );
	}

	public function test_prepare_bulk_update_returns_invalid_input_for_unknown_status(): void {
		$result = $this->service->prepare_bulk_update( array( 1, 2 ), 'nonexistent' );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ServiceResult::CODE_INVALID_INPUT, $result->code );
	}

	public function test_prepare_bulk_update_succeeds_with_valid_orders(): void {
		$this->orderGateway->addOrder( 1, 'pending' );
		$this->orderGateway->addOrder( 2, 'pending' );
		$this->orderGateway->addOrder( 3, 'processing' );

		$result = $this->service->prepare_bulk_update( array( 1, 2, 3 ), 'completed', true );

		$this->assertTrue( $result->isSuccess() );
		$this->assertArrayHasKey( 'draft_id', $result->data );
		$this->assertSame( 'status', $result->get( 'type' ) );

		$preview = $result->get( 'preview' );
		$this->assertSame( 3, $preview['count'] );
		$this->assertSame( 'completed', $preview['new_status'] );
		$this->assertTrue( $preview['notify_customer'] );
		$this->assertCount( 3, $preview['orders'] );
	}

	public function test_prepare_bulk_update_skips_missing_orders_in_preview(): void {
		$this->orderGateway->addOrder( 1, 'pending' );
		// Order 2 does not exist
		$this->orderGateway->addOrder( 3, 'pending' );

		$result = $this->service->prepare_bulk_update( array( 1, 2, 3 ), 'completed' );

		$this->assertTrue( $result->isSuccess() );

		$preview = $result->get( 'preview' );
		$this->assertSame( 2, $preview['count'] ); // Only 2 orders found
	}

	// ==========================================
	// confirm_update() tests
	// ==========================================

	public function test_confirm_update_returns_permission_denied_when_not_allowed(): void {
		$this->policy->setCapability( 'UpdateOrderStatus', false );

		$result = $this->service->confirm_update( 'draft_1' );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ServiceResult::CODE_PERMISSION_DENIED, $result->code );
	}

	public function test_confirm_update_returns_draft_expired_for_invalid_draft(): void {
		$result = $this->service->confirm_update( 'nonexistent_draft' );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ServiceResult::CODE_DRAFT_EXPIRED, $result->code );
	}

	public function test_confirm_update_succeeds_for_single_order(): void {
		$this->orderGateway->addOrder( 123, 'pending' );

		$prepareResult = $this->service->prepare_update( 123, 'completed', 'Done', false );
		$draft_id      = $prepareResult->get( 'draft_id' );

		$result = $this->service->confirm_update( $draft_id );

		$this->assertTrue( $result->isSuccess() );
		$this->assertSame( 123, $result->get( 'order_id' ) );
		$this->assertSame( 'completed', $result->get( 'new_status' ) );

		// Verify order was actually updated
		$this->assertSame( 'completed', $this->orderGateway->getOrderStatus( 123 ) );
	}

	public function test_confirm_update_succeeds_for_bulk_orders(): void {
		$this->orderGateway->addOrder( 1, 'pending' );
		$this->orderGateway->addOrder( 2, 'pending' );
		$this->orderGateway->addOrder( 3, 'pending' );

		$prepareResult = $this->service->prepare_bulk_update( array( 1, 2, 3 ), 'completed' );
		$draft_id      = $prepareResult->get( 'draft_id' );

		$result = $this->service->confirm_update( $draft_id );

		$this->assertTrue( $result->isSuccess() );
		$this->assertSame( 3, $result->get( 'updated_count' ) );
		$this->assertContains( 1, $result->get( 'updated_ids' ) );
		$this->assertContains( 2, $result->get( 'updated_ids' ) );
		$this->assertContains( 3, $result->get( 'updated_ids' ) );

		// Verify all orders were updated
		$this->assertSame( 'completed', $this->orderGateway->getOrderStatus( 1 ) );
		$this->assertSame( 'completed', $this->orderGateway->getOrderStatus( 2 ) );
		$this->assertSame( 'completed', $this->orderGateway->getOrderStatus( 3 ) );
	}

	public function test_confirm_update_consumes_draft(): void {
		$this->orderGateway->addOrder( 123, 'pending' );

		$prepareResult = $this->service->prepare_update( 123, 'completed' );
		$draft_id      = $prepareResult->get( 'draft_id' );

		$this->assertSame( 1, $this->draftManager->getDraftCount() );

		$this->service->confirm_update( $draft_id );

		$this->assertSame( 0, $this->draftManager->getDraftCount() );
	}

	public function test_confirm_update_cannot_be_used_twice(): void {
		$this->orderGateway->addOrder( 123, 'pending' );

		$prepareResult = $this->service->prepare_update( 123, 'completed' );
		$draft_id      = $prepareResult->get( 'draft_id' );

		$result1 = $this->service->confirm_update( $draft_id );
		$result2 = $this->service->confirm_update( $draft_id );

		$this->assertTrue( $result1->isSuccess() );
		$this->assertFalse( $result2->isSuccess() );
		$this->assertSame( ServiceResult::CODE_DRAFT_EXPIRED, $result2->code );
	}

	public function test_confirm_update_disables_emails_when_not_notifying(): void {
		$this->orderGateway->addOrder( 123, 'pending' );

		$prepareResult = $this->service->prepare_update( 123, 'completed', '', false );
		$draft_id      = $prepareResult->get( 'draft_id' );

		$this->service->confirm_update( $draft_id );

		$history = $this->orderGateway->getStatusUpdateHistory();
		$this->assertCount( 1, $history );
		$this->assertFalse( $history[0]['emails_enabled'] );
	}

	public function test_confirm_update_returns_not_found_when_order_deleted_after_prepare(): void {
		$this->orderGateway->addOrder( 123, 'pending' );

		$prepareResult = $this->service->prepare_update( 123, 'completed' );
		$draft_id      = $prepareResult->get( 'draft_id' );

		// Simulate order deletion
		$this->orderGateway->clear();

		$result = $this->service->confirm_update( $draft_id );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ServiceResult::CODE_NOT_FOUND, $result->code );
	}

	public function test_confirm_bulk_update_skips_missing_orders(): void {
		$this->orderGateway->addOrder( 1, 'pending' );
		$this->orderGateway->addOrder( 2, 'pending' );
		// Order 3 does not exist

		$prepareResult = $this->service->prepare_bulk_update( array( 1, 2, 3 ), 'completed' );
		$draft_id      = $prepareResult->get( 'draft_id' );

		$result = $this->service->confirm_update( $draft_id );

		$this->assertTrue( $result->isSuccess() );
		$this->assertSame( 2, $result->get( 'updated_count' ) );
		$this->assertContains( 1, $result->get( 'updated_ids' ) );
		$this->assertContains( 2, $result->get( 'updated_ids' ) );
		$this->assertNotContains( 3, $result->get( 'updated_ids' ) );
	}
}
