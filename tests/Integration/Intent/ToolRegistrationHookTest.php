<?php
/**
 * Integration tests for tool registration hook.
 */

namespace AgentWP\Tests\Integration\Intent;

use AgentWP\AI\Functions\FunctionSchema;
use AgentWP\Container\Container;
use AgentWP\Contracts\AnalyticsServiceInterface;
use AgentWP\Contracts\CustomerServiceInterface;
use AgentWP\Contracts\EmailDraftServiceInterface;
use AgentWP\Contracts\HooksInterface;
use AgentWP\Contracts\OrderRefundServiceInterface;
use AgentWP\Contracts\OrderSearchServiceInterface;
use AgentWP\Contracts\OrderStatusServiceInterface;
use AgentWP\Contracts\ProductStockServiceInterface;
use AgentWP\Contracts\ToolDispatcherInterface;
use AgentWP\Contracts\ToolRegistryInterface;
use AgentWP\DTO\ServiceResult;
use AgentWP\Providers\IntentServiceProvider;
use AgentWP\Tests\Fakes\FakeWPFunctions;
use AgentWP\Tests\TestCase;

class HookableWPFunctions extends FakeWPFunctions {
	/**
	 * @var array<string, array<callable>>
	 */
	private array $actionCallbacks = array();

	/**
	 * Register a callback for an action hook.
	 *
	 * @param string   $hook_name Hook name.
	 * @param callable $callback  Callback to execute.
	 * @return void
	 */
	public function addAction( string $hook_name, callable $callback ): void {
		if ( ! isset( $this->actionCallbacks[ $hook_name ] ) ) {
			$this->actionCallbacks[ $hook_name ] = array();
		}

		$this->actionCallbacks[ $hook_name ][] = $callback;
	}

	/**
	 * Do WordPress action and invoke registered callbacks.
	 *
	 * @param string $hook_name Action name.
	 * @param mixed  ...$args   Action arguments.
	 * @return void
	 */
	public function doAction( string $hook_name, ...$args ): void {
		parent::doAction( $hook_name, ...$args );

		if ( empty( $this->actionCallbacks[ $hook_name ] ) ) {
			return;
		}

		foreach ( $this->actionCallbacks[ $hook_name ] as $callback ) {
			$callback( ...$args );
		}
	}
}

class StubOrderSearchService implements OrderSearchServiceInterface {
	public function handle( array $args ): ServiceResult {
		return ServiceResult::success( 'ok', array( 'args' => $args ) );
	}
}

class StubOrderRefundService implements OrderRefundServiceInterface {
	public function prepare_refund( int $order_id, ?float $amount = null, string $reason = '', bool $restock_items = true ): ServiceResult {
		unset( $amount, $reason, $restock_items );
		return ServiceResult::success( 'ok', array( 'draft_id' => 'refund_draft', 'order_id' => $order_id ) );
	}

	public function confirm_refund( string $draft_id ): ServiceResult {
		return ServiceResult::success( 'ok', array( 'refund_id' => $draft_id ) );
	}
}

class StubOrderStatusService implements OrderStatusServiceInterface {
	public function prepare_update( int $order_id, string $new_status, string $note = '', bool $notify_customer = false ): ServiceResult {
		unset( $note, $notify_customer );
		return ServiceResult::success( 'ok', array( 'draft_id' => 'status_draft', 'order_id' => $order_id, 'status' => $new_status ) );
	}

	public function prepare_bulk_update( array $order_ids, string $new_status, bool $notify_customer = false ): ServiceResult {
		unset( $notify_customer );
		return ServiceResult::success( 'ok', array( 'draft_id' => 'bulk_draft', 'order_ids' => $order_ids, 'status' => $new_status ) );
	}

	public function confirm_update( string $draft_id ): ServiceResult {
		return ServiceResult::success( 'ok', array( 'draft_id' => $draft_id ) );
	}
}

class StubProductStockService implements ProductStockServiceInterface {
	public function search_products( string $query ): array {
		return array(
			array(
				'id'    => 1,
				'name'  => 'Test Product',
				'sku'   => 'TEST-SKU',
				'stock' => 5,
				'query' => $query,
			),
		);
	}

	public function prepare_update( int $product_id, int $quantity, string $operation = 'set' ): ServiceResult {
		return ServiceResult::success(
			'ok',
			array(
				'draft_id'   => 'stock_draft',
				'product_id' => $product_id,
				'quantity'   => $quantity,
				'operation'  => $operation,
			)
		);
	}

	public function confirm_update( string $draft_id ): ServiceResult {
		return ServiceResult::success( 'ok', array( 'draft_id' => $draft_id ) );
	}
}

class StubEmailDraftService implements EmailDraftServiceInterface {
	public function get_order_context( int $order_id ): ServiceResult {
		return ServiceResult::success( 'ok', array( 'order_id' => $order_id ) );
	}
}

class StubAnalyticsService implements AnalyticsServiceInterface {
	public function get_stats( string $period = '7d' ): ServiceResult {
		return ServiceResult::success( 'ok', array( 'period' => $period ) );
	}

	public function get_report( string $start, string $end ): ServiceResult {
		return ServiceResult::success( 'ok', array( 'start' => $start, 'end' => $end ) );
	}

	public function get_report_by_period( string $period, ?string $start_date = null, ?string $end_date = null ): ServiceResult {
		return ServiceResult::success(
			'ok',
			array(
				'period'     => $period,
				'start_date' => $start_date,
				'end_date'   => $end_date,
			)
		);
	}
}

class StubCustomerService implements CustomerServiceInterface {
	public function handle( array $args ): ServiceResult {
		return ServiceResult::success( 'ok', array( 'args' => $args ) );
	}
}

class ToolRegistrationHookTest extends TestCase {
	public function test_register_tools_action_registers_schema_and_executor(): void {
		$hooks     = new HookableWPFunctions();
		$container = new Container();

		$container->instance( HooksInterface::class, $hooks );
		$container->instance( OrderSearchServiceInterface::class, new StubOrderSearchService() );
		$container->instance( OrderRefundServiceInterface::class, new StubOrderRefundService() );
		$container->instance( OrderStatusServiceInterface::class, new StubOrderStatusService() );
		$container->instance( ProductStockServiceInterface::class, new StubProductStockService() );
		$container->instance( EmailDraftServiceInterface::class, new StubEmailDraftService() );
		$container->instance( AnalyticsServiceInterface::class, new StubAnalyticsService() );
		$container->instance( CustomerServiceInterface::class, new StubCustomerService() );

		$provider = new IntentServiceProvider( $container );
		$provider->register();

		$hooks->addAction(
			'agentwp_register_tools',
			function ( ToolRegistryInterface $registry, ToolDispatcherInterface $dispatcher ): void {
				$schema = new class() implements FunctionSchema {
					public function get_name(): string {
						return 'test_hook_tool';
					}

					public function get_description(): string {
						return 'Test hook tool.';
					}

					public function get_parameters(): array {
						return array(
							'type'       => 'object',
							'properties' => array(
								'value' => array(
									'type' => 'string',
								),
							),
						);
					}

					public function to_tool_definition(): array {
						return array(
							'type'     => 'function',
							'function' => array(
								'name'        => $this->get_name(),
								'description' => $this->get_description(),
								'parameters'  => $this->get_parameters(),
							),
						);
					}
				};

				$registry->register( $schema );
				$dispatcher->register(
					'test_hook_tool',
					fn( array $args ): array => array(
						'success' => true,
						'args'    => $args,
					)
				);
			}
		);

		$provider->boot();

		$registry   = $container->get( ToolRegistryInterface::class );
		$dispatcher = $container->get( ToolDispatcherInterface::class );

		$this->assertTrue( $registry->has( 'test_hook_tool' ) );
		$this->assertTrue( $dispatcher->has( 'test_hook_tool' ) );

		$result = $dispatcher->dispatch( 'test_hook_tool', array( 'value' => 'ok' ) );
		$this->assertSame(
			array(
				'success' => true,
				'args'    => array( 'value' => 'ok' ),
			),
			$result
		);
	}
}
