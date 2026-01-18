<?php
/**
 * Handle order refund intents.
 *
 * @package AgentWP
 */

namespace AgentWP\Intent\Handlers;

use AgentWP\Contracts\AIClientFactoryInterface;
use AgentWP\Contracts\OrderRefundServiceInterface;
use AgentWP\Contracts\ToolDispatcherInterface;
use AgentWP\Contracts\ToolRegistryInterface;
use AgentWP\Intent\Attributes\HandlesIntent;
use AgentWP\Intent\Intent;

/**
 * Handles order refund intents using the agentic loop.
 */
#[HandlesIntent( Intent::ORDER_REFUND )]
class OrderRefundHandler extends AbstractAgenticHandler {

	/**
	 * @var OrderRefundServiceInterface
	 */
	private OrderRefundServiceInterface $service;

	/**
	 * Initialize order refund intent handler.
	 *
	 * @param OrderRefundServiceInterface  $service        Refund service.
	 * @param AIClientFactoryInterface     $clientFactory  AI client factory.
	 * @param ToolRegistryInterface        $toolRegistry   Tool registry.
	 * @param ToolDispatcherInterface|null $toolDispatcher Tool dispatcher (optional).
	 */
	public function __construct(
		OrderRefundServiceInterface $service,
		AIClientFactoryInterface $clientFactory,
		ToolRegistryInterface $toolRegistry,
		?ToolDispatcherInterface $toolDispatcher = null
	) {
		$this->service = $service;
		parent::__construct( Intent::ORDER_REFUND, $clientFactory, $toolRegistry, $toolDispatcher );
	}

	/**
	 * Register tool executors with the dispatcher.
	 *
	 * @param ToolDispatcherInterface $dispatcher The tool dispatcher.
	 * @return void
	 */
	protected function registerToolExecutors( ToolDispatcherInterface $dispatcher ): void {
		$dispatcher->registerMany(
			array(
				'prepare_refund' => function ( array $args ): array {
					$order_id      = isset( $args['order_id'] ) ? (int) $args['order_id'] : 0;
					$amount        = isset( $args['amount'] ) ? $args['amount'] : null;
					$reason        = isset( $args['reason'] ) ? $args['reason'] : '';
					$restock_items = isset( $args['restock_items'] ) ? (bool) $args['restock_items'] : true;

					return $this->service->prepare_refund( $order_id, $amount, $reason, $restock_items )->toLegacyArray();
				},
				'confirm_refund' => function ( array $args ): array {
					$draft_id = isset( $args['draft_id'] ) ? (string) $args['draft_id'] : '';
					return $this->service->confirm_refund( $draft_id )->toLegacyArray();
				},
			)
		);
	}

	/**
	 * Get the system prompt for refund handling.
	 *
	 * @return string
	 */
	protected function getSystemPrompt(): string {
		return 'You are an expert WooCommerce assistant. You can help process refunds. Always verify order details before confirming.';
	}

	/**
	 * Get the tool names for refund handling.
	 *
	 * @return array<string>
	 */
	protected function getToolNames(): array {
		return array( 'prepare_refund', 'confirm_refund' );
	}

	/**
	 * Get the default input for refund operations.
	 *
	 * @return string
	 */
	protected function getDefaultInput(): string {
		return 'Process a refund';
	}
}
