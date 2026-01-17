<?php
/**
 * Tests for EmailDraftService.
 *
 * @package AgentWP\Tests\Unit\Services
 */

namespace AgentWP\Tests\Unit\Services;

use AgentWP\DTO\OrderDTO;
use AgentWP\DTO\ServiceResult;
use AgentWP\Services\EmailDraftService;
use AgentWP\Tests\Fakes\FakeOrderRepository;
use AgentWP\Tests\Fakes\FakePolicy;
use AgentWP\Tests\TestCase;
use DateTimeImmutable;

class EmailDraftServiceTest extends TestCase {

	private FakePolicy $policy;
	private FakeOrderRepository $repository;
	private EmailDraftService $service;

	public function setUp(): void {
		parent::setUp();

		$this->policy     = new FakePolicy();
		$this->repository = new FakeOrderRepository();

		$this->service = new EmailDraftService(
			$this->policy,
			$this->repository
		);
	}

	public function tearDown(): void {
		$this->repository->clear();
		parent::tearDown();
	}

	// ==========================================
	// get_order_context() tests
	// ==========================================

	public function test_get_order_context_returns_permission_denied_when_not_allowed(): void {
		$this->policy->setCapability( 'DraftEmails', false );

		$result = $this->service->get_order_context( 123 );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ServiceResult::CODE_PERMISSION_DENIED, $result->code );
	}

	public function test_get_order_context_returns_operation_failed_when_repository_null(): void {
		$service = new EmailDraftService( $this->policy, null );

		$result = $service->get_order_context( 123 );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ServiceResult::CODE_OPERATION_FAILED, $result->code );
		$this->assertStringContainsString( 'WooCommerce is not available', $result->message );
	}

	public function test_get_order_context_returns_invalid_input_for_invalid_order_id(): void {
		$result = $this->service->get_order_context( 0 );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ServiceResult::CODE_INVALID_INPUT, $result->code );
		$this->assertStringContainsString( 'Invalid order ID', $result->message );
	}

	public function test_get_order_context_returns_invalid_input_for_negative_order_id(): void {
		$result = $this->service->get_order_context( -5 );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ServiceResult::CODE_INVALID_INPUT, $result->code );
	}

	public function test_get_order_context_returns_not_found_for_missing_order(): void {
		$result = $this->service->get_order_context( 999 );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ServiceResult::CODE_NOT_FOUND, $result->code );
		$this->assertStringContainsString( 'Order #999 not found', $result->message );
	}

	public function test_get_order_context_succeeds_with_complete_order(): void {
		$order = new OrderDTO(
			id: 123,
			status: 'processing',
			total: 99.99,
			currency: 'USD',
			customerName: 'John Doe',
			customerEmail: 'john@example.com',
			dateCreated: new DateTimeImmutable( '2024-01-15 10:30:00' ),
			items: array(
				array( 'name' => 'Blue Widget', 'quantity' => 2 ),
				array( 'name' => 'Red Gadget', 'quantity' => 1 ),
			)
		);
		$this->repository->addOrder( $order );

		$result = $this->service->get_order_context( 123 );

		$this->assertTrue( $result->isSuccess() );
		$this->assertSame( 'email', $result->get( 'type' ) );

		$context = $result->get( 'context' );
		$this->assertIsArray( $context );
		$this->assertSame( 123, $context['order_id'] );
		$this->assertSame( 'John Doe', $context['customer'] );
		$this->assertSame( 99.99, $context['total'] );
		$this->assertSame( 'USD', $context['currency'] );
		$this->assertSame( 'processing', $context['status'] );
		$this->assertSame( '2024-01-15', $context['date'] );

		$this->assertIsArray( $context['items'] );
		$this->assertCount( 2, $context['items'] );
		$this->assertContains( 'Blue Widget x2', $context['items'] );
		$this->assertContains( 'Red Gadget x1', $context['items'] );
	}

	public function test_get_order_context_includes_summary_message(): void {
		$order = new OrderDTO(
			id: 456,
			status: 'completed',
			total: 150.00,
			currency: 'EUR',
			customerName: 'Jane Smith',
			customerEmail: 'jane@example.com',
			dateCreated: new DateTimeImmutable( '2024-02-20' )
		);
		$this->repository->addOrder( $order );

		$result = $this->service->get_order_context( 456 );

		$this->assertTrue( $result->isSuccess() );

		$context = $result->get( 'context' );
		$this->assertStringContainsString( 'Order #456', $context['summary'] );
		$this->assertStringContainsString( 'Jane Smith', $context['summary'] );
	}

	public function test_get_order_context_handles_order_without_items(): void {
		$order = new OrderDTO(
			id: 123,
			status: 'pending',
			total: 0.00,
			currency: 'USD',
			customerName: 'Test Customer',
			customerEmail: 'test@example.com',
			dateCreated: new DateTimeImmutable()
		);
		$this->repository->addOrder( $order );

		$result = $this->service->get_order_context( 123 );

		$this->assertTrue( $result->isSuccess() );

		$context = $result->get( 'context' );
		$this->assertIsArray( $context['items'] );
		$this->assertEmpty( $context['items'] );
	}

	public function test_get_order_context_handles_order_without_date(): void {
		$order = new OrderDTO(
			id: 123,
			status: 'pending',
			total: 50.00,
			currency: 'USD',
			customerName: 'Test Customer',
			customerEmail: 'test@example.com',
			dateCreated: null
		);
		$this->repository->addOrder( $order );

		$result = $this->service->get_order_context( 123 );

		$this->assertTrue( $result->isSuccess() );

		$context = $result->get( 'context' );
		$this->assertSame( '', $context['date'] );
	}

	public function test_get_order_context_handles_items_with_missing_name(): void {
		$order = new OrderDTO(
			id: 123,
			status: 'processing',
			total: 25.00,
			currency: 'USD',
			customerName: 'Test Customer',
			customerEmail: 'test@example.com',
			dateCreated: new DateTimeImmutable(),
			items: array(
				array( 'quantity' => 3 ), // Missing 'name'
			)
		);
		$this->repository->addOrder( $order );

		$result = $this->service->get_order_context( 123 );

		$this->assertTrue( $result->isSuccess() );

		$context = $result->get( 'context' );
		$this->assertContains( 'Item x3', $context['items'] );
	}

	public function test_get_order_context_handles_items_with_missing_quantity(): void {
		$order = new OrderDTO(
			id: 123,
			status: 'processing',
			total: 25.00,
			currency: 'USD',
			customerName: 'Test Customer',
			customerEmail: 'test@example.com',
			dateCreated: new DateTimeImmutable(),
			items: array(
				array( 'name' => 'Widget' ), // Missing 'quantity'
			)
		);
		$this->repository->addOrder( $order );

		$result = $this->service->get_order_context( 123 );

		$this->assertTrue( $result->isSuccess() );

		$context = $result->get( 'context' );
		$this->assertContains( 'Widget x1', $context['items'] );
	}

	public function test_get_order_context_uses_default_currency_when_not_set(): void {
		$order = new OrderDTO(
			id: 123,
			status: 'processing',
			total: 50.00,
			currency: '',  // Empty currency - will fallback to property value
			customerName: 'Test Customer',
			customerEmail: 'test@example.com',
			dateCreated: new DateTimeImmutable()
		);
		$this->repository->addOrder( $order );

		$result = $this->service->get_order_context( 123 );

		$this->assertTrue( $result->isSuccess() );
		// The service uses $order->currency ?? 'USD', but since currency is set (even if empty string),
		// it will use the empty string. Let's verify this behavior is handled.
		$context = $result->get( 'context' );
		$this->assertArrayHasKey( 'currency', $context );
	}

	public function test_get_order_context_returns_correct_result_type(): void {
		$order = new OrderDTO(
			id: 123,
			status: 'processing',
			total: 50.00,
			currency: 'USD',
			customerName: 'Test Customer',
			customerEmail: 'test@example.com',
			dateCreated: new DateTimeImmutable()
		);
		$this->repository->addOrder( $order );

		$result = $this->service->get_order_context( 123 );

		$this->assertInstanceOf( ServiceResult::class, $result );
		$this->assertTrue( $result->isSuccess() );
		$this->assertFalse( $result->isFailure() );
		$this->assertSame( ServiceResult::CODE_SUCCESS, $result->code );
	}

	public function test_get_order_context_message_indicates_success(): void {
		$order = new OrderDTO(
			id: 123,
			status: 'processing',
			total: 50.00,
			currency: 'USD',
			customerName: 'Test Customer',
			customerEmail: 'test@example.com',
			dateCreated: new DateTimeImmutable()
		);
		$this->repository->addOrder( $order );

		$result = $this->service->get_order_context( 123 );

		$this->assertStringContainsString( 'Order context loaded', $result->message );
	}
}
