<?php
/**
 * Tests for ProductStockService.
 *
 * @package AgentWP\Tests\Unit\Services
 */

namespace AgentWP\Tests\Unit\Services;

use AgentWP\DTO\ServiceResult;
use AgentWP\Services\ProductStockService;
use AgentWP\Tests\Fakes\FakeDraftManager;
use AgentWP\Tests\Fakes\FakePolicy;
use AgentWP\Tests\Fakes\FakeWooCommerceStockGateway;
use AgentWP\Tests\TestCase;

class ProductStockServiceTest extends TestCase {

	private FakeDraftManager $draftManager;
	private FakePolicy $policy;
	private FakeWooCommerceStockGateway $stockGateway;
	private ProductStockService $service;

	public function setUp(): void {
		parent::setUp();

		$this->draftManager = new FakeDraftManager();
		$this->policy       = new FakePolicy();
		$this->stockGateway = new FakeWooCommerceStockGateway();

		$this->service = new ProductStockService(
			$this->draftManager,
			$this->policy,
			$this->stockGateway
		);
	}

	public function tearDown(): void {
		$this->draftManager->clear();
		$this->stockGateway->clear();
		parent::tearDown();
	}

	// ==========================================
	// search_products() tests
	// Note: search_products requires WC_Product instances which we cannot
	// properly fake without WooCommerce runtime. We test that it returns
	// empty results when products don't match the WC_Product type check.
	// The gateway search functionality itself is tested in isolation.
	// ==========================================

	public function test_search_products_returns_empty_array_when_no_products(): void {
		$results = $this->service->search_products( 'test' );

		$this->assertIsArray( $results );
		$this->assertEmpty( $results );
	}

	public function test_search_products_returns_array(): void {
		// The fake products don't pass the WC_Product instanceof check,
		// so search_products returns empty. This tests the method returns
		// an array type and doesn't throw exceptions.
		$this->stockGateway->addProduct( 1, 'Blue Widget', 'BW-001', 10 );

		$results = $this->service->search_products( 'widget' );

		$this->assertIsArray( $results );
	}

	// ==========================================
	// prepare_update() tests
	// ==========================================

	public function test_prepare_update_returns_permission_denied_when_not_allowed(): void {
		$this->policy->setCapability( 'ManageStock', false );

		$result = $this->service->prepare_update( 123, 10 );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ServiceResult::CODE_PERMISSION_DENIED, $result->code );
	}

	public function test_prepare_update_returns_invalid_input_for_invalid_product_id(): void {
		$result = $this->service->prepare_update( 0, 10 );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ServiceResult::CODE_INVALID_INPUT, $result->code );
		$this->assertStringContainsString( 'Invalid product ID', $result->message );
	}

	public function test_prepare_update_returns_invalid_input_for_negative_quantity(): void {
		$result = $this->service->prepare_update( 1, -5 );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ServiceResult::CODE_INVALID_INPUT, $result->code );
		$this->assertStringContainsString( 'cannot be negative', $result->message );
	}

	public function test_prepare_update_returns_not_found_for_missing_product(): void {
		$result = $this->service->prepare_update( 999, 10 );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ServiceResult::CODE_NOT_FOUND, $result->code );
		$this->assertStringContainsString( 'Product #999 not found', $result->message );
	}

	public function test_prepare_update_succeeds_with_set_operation(): void {
		$this->stockGateway->addProduct( 1, 'Test Product', 'TP-001', 15 );

		$result = $this->service->prepare_update( 1, 25, 'set' );

		$this->assertTrue( $result->isSuccess() );
		$this->assertArrayHasKey( 'draft_id', $result->data );
		$this->assertStringStartsWith( 'draft_', $result->get( 'draft_id' ) );
		$this->assertSame( 'stock', $result->get( 'type' ) );

		$preview = $result->get( 'preview' );
		$this->assertSame( 1, $preview['product_id'] );
		$this->assertSame( 'Test Product', $preview['product_name'] );
		$this->assertSame( 'TP-001', $preview['product_sku'] );
		$this->assertSame( 15, $preview['original_stock'] );
		$this->assertSame( 25, $preview['new_stock'] );
	}

	public function test_prepare_update_succeeds_with_increase_operation(): void {
		$this->stockGateway->addProduct( 1, 'Test Product', 'TP-001', 10 );

		$result = $this->service->prepare_update( 1, 5, 'increase' );

		$this->assertTrue( $result->isSuccess() );

		$preview = $result->get( 'preview' );
		$this->assertSame( 10, $preview['original_stock'] );
		$this->assertSame( 15, $preview['new_stock'] ); // 10 + 5
	}

	public function test_prepare_update_succeeds_with_decrease_operation(): void {
		$this->stockGateway->addProduct( 1, 'Test Product', 'TP-001', 20 );

		$result = $this->service->prepare_update( 1, 8, 'decrease' );

		$this->assertTrue( $result->isSuccess() );

		$preview = $result->get( 'preview' );
		$this->assertSame( 20, $preview['original_stock'] );
		$this->assertSame( 12, $preview['new_stock'] ); // 20 - 8
	}

	public function test_prepare_update_decrease_does_not_go_below_zero(): void {
		$this->stockGateway->addProduct( 1, 'Test Product', 'TP-001', 5 );

		$result = $this->service->prepare_update( 1, 10, 'decrease' );

		$this->assertTrue( $result->isSuccess() );

		$preview = $result->get( 'preview' );
		$this->assertSame( 5, $preview['original_stock'] );
		$this->assertSame( 0, $preview['new_stock'] ); // max(0, 5 - 10)
	}

	public function test_prepare_update_creates_draft_with_correct_payload(): void {
		$this->stockGateway->addProduct( 1, 'Test Product', 'TP-001', 10 );

		$result = $this->service->prepare_update( 1, 50, 'set' );

		$this->assertTrue( $result->isSuccess() );
		$this->assertSame( 1, $this->draftManager->getDraftCount() );

		$draft = $this->draftManager->get( 'stock', $result->get( 'draft_id' ) );
		$this->assertNotNull( $draft );
		$this->assertSame( 1, $draft->payload['product_id'] );
		$this->assertSame( 50, $draft->payload['quantity'] );
		$this->assertSame( 10, $draft->payload['original'] );
	}

	public function test_prepare_update_fails_when_draft_creation_fails(): void {
		$this->stockGateway->addProduct( 1, 'Test Product', 'TP-001', 10 );
		$this->draftManager->failNextCreate();

		$result = $this->service->prepare_update( 1, 50 );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ServiceResult::CODE_OPERATION_FAILED, $result->code );
	}

	// ==========================================
	// confirm_update() tests
	// ==========================================

	public function test_confirm_update_returns_permission_denied_when_not_allowed(): void {
		$this->policy->setCapability( 'ManageStock', false );

		$result = $this->service->confirm_update( 'draft_1' );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ServiceResult::CODE_PERMISSION_DENIED, $result->code );
	}

	public function test_confirm_update_returns_draft_expired_for_invalid_draft(): void {
		$result = $this->service->confirm_update( 'nonexistent_draft' );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ServiceResult::CODE_DRAFT_EXPIRED, $result->code );
	}

	public function test_confirm_update_returns_draft_expired_when_draft_expired(): void {
		$this->stockGateway->addProduct( 1, 'Test Product', 'TP-001', 10 );
		$this->draftManager->setTtl( 60 ); // 1 minute TTL

		$prepareResult = $this->service->prepare_update( 1, 50 );
		$draft_id      = $prepareResult->get( 'draft_id' );

		// Advance time past expiration
		$this->draftManager->advanceTime( 120 );

		$result = $this->service->confirm_update( $draft_id );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ServiceResult::CODE_DRAFT_EXPIRED, $result->code );
	}

	public function test_confirm_update_succeeds_and_updates_stock(): void {
		$this->stockGateway->addProduct( 1, 'Test Product', 'TP-001', 10 );

		$prepareResult = $this->service->prepare_update( 1, 50, 'set' );
		$draft_id      = $prepareResult->get( 'draft_id' );

		$result = $this->service->confirm_update( $draft_id );

		$this->assertTrue( $result->isSuccess() );
		$this->assertSame( 1, $result->get( 'product_id' ) );
		$this->assertSame( 50, $result->get( 'new_stock' ) );

		// Verify stock was actually updated
		$this->assertSame( 50, $this->stockGateway->getProductStock( 1 ) );
	}

	public function test_confirm_update_consumes_draft(): void {
		$this->stockGateway->addProduct( 1, 'Test Product', 'TP-001', 10 );

		$prepareResult = $this->service->prepare_update( 1, 50 );
		$draft_id      = $prepareResult->get( 'draft_id' );

		$this->assertSame( 1, $this->draftManager->getDraftCount() );

		$this->service->confirm_update( $draft_id );

		$this->assertSame( 0, $this->draftManager->getDraftCount() );
	}

	public function test_confirm_update_cannot_be_used_twice(): void {
		$this->stockGateway->addProduct( 1, 'Test Product', 'TP-001', 10 );

		$prepareResult = $this->service->prepare_update( 1, 50 );
		$draft_id      = $prepareResult->get( 'draft_id' );

		$result1 = $this->service->confirm_update( $draft_id );
		$result2 = $this->service->confirm_update( $draft_id );

		$this->assertTrue( $result1->isSuccess() );
		$this->assertFalse( $result2->isSuccess() );
		$this->assertSame( ServiceResult::CODE_DRAFT_EXPIRED, $result2->code );
	}

	public function test_confirm_update_returns_not_found_when_product_deleted_after_prepare(): void {
		$this->stockGateway->addProduct( 1, 'Test Product', 'TP-001', 10 );

		$prepareResult = $this->service->prepare_update( 1, 50 );
		$draft_id      = $prepareResult->get( 'draft_id' );

		// Simulate product deletion
		$this->stockGateway->clear();

		$result = $this->service->confirm_update( $draft_id );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ServiceResult::CODE_NOT_FOUND, $result->code );
	}

	public function test_confirm_update_returns_operation_failed_when_gateway_fails(): void {
		$this->stockGateway->addProduct( 1, 'Test Product', 'TP-001', 10 );
		$this->stockGateway->setFailStockUpdate( true );

		$prepareResult = $this->service->prepare_update( 1, 50 );
		$draft_id      = $prepareResult->get( 'draft_id' );

		$result = $this->service->confirm_update( $draft_id );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ServiceResult::CODE_OPERATION_FAILED, $result->code );
	}

	public function test_stock_update_history_is_recorded(): void {
		$this->stockGateway->addProduct( 1, 'Test Product', 'TP-001', 10 );

		$prepareResult = $this->service->prepare_update( 1, 25, 'set' );
		$draft_id      = $prepareResult->get( 'draft_id' );

		$this->service->confirm_update( $draft_id );

		$history = $this->stockGateway->getStockUpdateHistory();
		$this->assertCount( 1, $history );
		$this->assertSame( 1, $history[0]['product_id'] );
		$this->assertSame( 10, $history[0]['old_stock'] );
		$this->assertSame( 25, $history[0]['new_stock'] );
	}
}
