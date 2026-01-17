<?php
/**
 * Tests for OrderRefundService.
 *
 * @package AgentWP\Tests\Unit\Services
 */

namespace AgentWP\Tests\Unit\Services;

use AgentWP\DTO\ServiceResult;
use AgentWP\Services\OrderRefundService;
use AgentWP\Tests\Fakes\FakeDraftManager;
use AgentWP\Tests\Fakes\FakePolicy;
use AgentWP\Tests\Fakes\FakeWooCommerceRefundGateway;
use AgentWP\Tests\TestCase;

class OrderRefundServiceTest extends TestCase {

	private FakeDraftManager $draftManager;
	private FakePolicy $policy;
	private FakeWooCommerceRefundGateway $refundGateway;
	private OrderRefundService $service;

	public function setUp(): void {
		parent::setUp();

		$this->draftManager  = new FakeDraftManager();
		$this->policy        = new FakePolicy();
		$this->refundGateway = new FakeWooCommerceRefundGateway();

		$this->service = new OrderRefundService(
			$this->draftManager,
			$this->policy,
			$this->refundGateway
		);
	}

	public function tearDown(): void {
		$this->draftManager->clear();
		$this->refundGateway->clear();
		parent::tearDown();
	}

	// ==========================================
	// prepare_refund() tests
	// ==========================================

	public function test_prepare_refund_returns_permission_denied_when_not_allowed(): void {
		$this->policy->setCapability( 'RefundOrders', false );

		$result = $this->service->prepare_refund( 123, 50.00 );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ServiceResult::CODE_PERMISSION_DENIED, $result->code );
	}

	public function test_prepare_refund_returns_invalid_input_for_invalid_order_id(): void {
		$result = $this->service->prepare_refund( 0, 50.00 );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ServiceResult::CODE_INVALID_INPUT, $result->code );
		$this->assertStringContainsString( 'Invalid order ID', $result->message );
	}

	public function test_prepare_refund_returns_not_found_for_missing_order(): void {
		$result = $this->service->prepare_refund( 999, 50.00 );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ServiceResult::CODE_NOT_FOUND, $result->code );
		$this->assertStringContainsString( 'Order #999 not found', $result->message );
	}

	public function test_prepare_refund_returns_invalid_state_when_already_fully_refunded(): void {
		$this->refundGateway->addOrder( 123, 0.00, 'USD', 'John Doe' );

		$result = $this->service->prepare_refund( 123, 50.00 );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ServiceResult::CODE_INVALID_STATE, $result->code );
		$this->assertStringContainsString( 'already fully refunded', $result->message );
	}

	public function test_prepare_refund_returns_invalid_input_when_amount_exceeds_max(): void {
		$this->refundGateway->addOrder( 123, 50.00, 'USD', 'John Doe' );

		$result = $this->service->prepare_refund( 123, 100.00 );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ServiceResult::CODE_INVALID_INPUT, $result->code );
		$this->assertStringContainsString( 'Cannot refund', $result->message );
		$this->assertStringContainsString( 'Max refundable', $result->message );
	}

	public function test_prepare_refund_succeeds_with_explicit_amount(): void {
		$this->refundGateway->addOrder( 123, 100.00, 'USD', 'John Doe' );

		$result = $this->service->prepare_refund( 123, 50.00, 'Customer request', true );

		$this->assertTrue( $result->isSuccess() );
		$this->assertArrayHasKey( 'draft_id', $result->data );
		$this->assertStringStartsWith( 'draft_', $result->get( 'draft_id' ) );
		$this->assertSame( 'refund', $result->get( 'type' ) );

		$preview = $result->get( 'preview' );
		$this->assertSame( 123, $preview['order_id'] );
		$this->assertSame( 50.00, $preview['amount'] );
		$this->assertSame( 'USD', $preview['currency'] );
		$this->assertTrue( $preview['restock_items'] );
	}

	public function test_prepare_refund_uses_max_amount_when_not_specified(): void {
		$this->refundGateway->addOrder( 123, 75.50, 'EUR', 'Jane Smith' );

		$result = $this->service->prepare_refund( 123, null, 'Full refund' );

		$this->assertTrue( $result->isSuccess() );

		$preview = $result->get( 'preview' );
		$this->assertSame( 75.50, $preview['amount'] );
		$this->assertSame( 'EUR', $preview['currency'] );
	}

	public function test_prepare_refund_creates_draft_with_correct_payload(): void {
		$this->refundGateway->addOrder( 123, 100.00, 'USD', 'Test User' );

		$result = $this->service->prepare_refund( 123, 25.00, 'Partial refund', false );

		$this->assertTrue( $result->isSuccess() );
		$this->assertSame( 1, $this->draftManager->getDraftCount() );

		$draft = $this->draftManager->get( 'refund', $result->get( 'draft_id' ) );
		$this->assertNotNull( $draft );
		$this->assertSame( 123, $draft->payload['order_id'] );
		$this->assertSame( 25.00, $draft->payload['amount'] );
		$this->assertSame( 'Partial refund', $draft->payload['reason'] );
		$this->assertFalse( $draft->payload['restock_items'] );
	}

	public function test_prepare_refund_fails_when_draft_creation_fails(): void {
		$this->refundGateway->addOrder( 123, 100.00, 'USD', 'Test User' );
		$this->draftManager->failNextCreate();

		$result = $this->service->prepare_refund( 123, 50.00 );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ServiceResult::CODE_OPERATION_FAILED, $result->code );
	}

	// ==========================================
	// confirm_refund() tests
	// ==========================================

	public function test_confirm_refund_returns_permission_denied_when_not_allowed(): void {
		$this->policy->setCapability( 'RefundOrders', false );

		$result = $this->service->confirm_refund( 'draft_1' );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ServiceResult::CODE_PERMISSION_DENIED, $result->code );
	}

	public function test_confirm_refund_returns_draft_expired_for_invalid_draft(): void {
		$result = $this->service->confirm_refund( 'nonexistent_draft' );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ServiceResult::CODE_DRAFT_EXPIRED, $result->code );
	}

	public function test_confirm_refund_returns_draft_expired_when_draft_expired(): void {
		$this->refundGateway->addOrder( 123, 100.00, 'USD', 'Test User' );
		$this->draftManager->setTtl( 60 ); // 1 minute TTL

		$prepareResult = $this->service->prepare_refund( 123, 50.00 );
		$draft_id      = $prepareResult->get( 'draft_id' );

		// Advance time past expiration
		$this->draftManager->advanceTime( 120 );

		$result = $this->service->confirm_refund( $draft_id );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ServiceResult::CODE_DRAFT_EXPIRED, $result->code );
	}

	public function test_confirm_refund_succeeds_and_creates_refund(): void {
		$this->refundGateway->addOrder(
			123,
			100.00,
			'USD',
			'Test User',
			array( array( 'product_id' => 10 ), array( 'product_id' => 20 ) )
		);

		$prepareResult = $this->service->prepare_refund( 123, 50.00, 'Test refund', true );
		$draft_id      = $prepareResult->get( 'draft_id' );

		$result = $this->service->confirm_refund( $draft_id );

		$this->assertTrue( $result->isSuccess() );
		$this->assertTrue( $result->get( 'confirmed' ) );
		$this->assertSame( 123, $result->get( 'order_id' ) );
		$this->assertSame( 1, $result->get( 'refund_id' ) );
		$this->assertContains( 10, $result->get( 'restocked_items' ) );
		$this->assertContains( 20, $result->get( 'restocked_items' ) );
	}

	public function test_confirm_refund_consumes_draft(): void {
		$this->refundGateway->addOrder( 123, 100.00, 'USD', 'Test User' );

		$prepareResult = $this->service->prepare_refund( 123, 50.00 );
		$draft_id      = $prepareResult->get( 'draft_id' );

		$this->assertSame( 1, $this->draftManager->getDraftCount() );

		$this->service->confirm_refund( $draft_id );

		$this->assertSame( 0, $this->draftManager->getDraftCount() );
	}

	public function test_confirm_refund_cannot_be_used_twice(): void {
		$this->refundGateway->addOrder( 123, 100.00, 'USD', 'Test User' );

		$prepareResult = $this->service->prepare_refund( 123, 50.00 );
		$draft_id      = $prepareResult->get( 'draft_id' );

		$result1 = $this->service->confirm_refund( $draft_id );
		$result2 = $this->service->confirm_refund( $draft_id );

		$this->assertTrue( $result1->isSuccess() );
		$this->assertFalse( $result2->isSuccess() );
		$this->assertSame( ServiceResult::CODE_DRAFT_EXPIRED, $result2->code );
	}

	public function test_confirm_refund_returns_operation_failed_when_gateway_fails(): void {
		$this->refundGateway->addOrder( 123, 100.00, 'USD', 'Test User' );
		$this->refundGateway->failNextRefund( 'Payment gateway error' );

		$prepareResult = $this->service->prepare_refund( 123, 50.00 );
		$draft_id      = $prepareResult->get( 'draft_id' );

		$result = $this->service->confirm_refund( $draft_id );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ServiceResult::CODE_OPERATION_FAILED, $result->code );
		$this->assertStringContainsString( 'Payment gateway error', $result->message );
	}

	public function test_confirm_refund_updates_remaining_refund_amount(): void {
		$this->refundGateway->addOrder( 123, 100.00, 'USD', 'Test User' );

		$prepareResult = $this->service->prepare_refund( 123, 40.00 );
		$draft_id      = $prepareResult->get( 'draft_id' );

		$this->service->confirm_refund( $draft_id );

		$order = $this->refundGateway->get_order( 123 );
		$this->assertSame( 60.00, $order->get_remaining_refund_amount() );
	}
}
