<?php
/**
 * Handle order refund intents.
 *
 * @package AgentWP
 */

namespace AgentWP\Intent\Handlers;

use AgentWP\Contracts\AIClientFactoryInterface;
use AgentWP\Contracts\OrderRefundServiceInterface;
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
	 * @param OrderRefundServiceInterface $service       Refund service.
	 * @param AIClientFactoryInterface    $clientFactory AI client factory.
	 * @param ToolRegistryInterface       $toolRegistry  Tool registry.
	 */
	public function __construct(
		OrderRefundServiceInterface $service,
		AIClientFactoryInterface $clientFactory,
		ToolRegistryInterface $toolRegistry
	) {
		parent::__construct( Intent::ORDER_REFUND, $clientFactory, $toolRegistry );
		$this->service = $service;
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

	/**
	 * Execute a named tool with arguments.
	 *
	 * @param string $name      Tool name.
	 * @param array  $arguments Tool arguments.
	 * @return array Tool execution result.
	 */
	public function execute_tool( string $name, array $arguments ) {
		switch ( $name ) {
			case 'prepare_refund':
				$order_id      = isset( $arguments['order_id'] ) ? (int) $arguments['order_id'] : 0;
				$amount        = isset( $arguments['amount'] ) ? $arguments['amount'] : null;
				$reason        = isset( $arguments['reason'] ) ? $arguments['reason'] : '';
				$restock_items = isset( $arguments['restock_items'] ) ? (bool) $arguments['restock_items'] : true;

				return $this->service->prepare_refund( $order_id, $amount, $reason, $restock_items )->toLegacyArray();

			case 'confirm_refund':
				$draft_id = isset( $arguments['draft_id'] ) ? (string) $arguments['draft_id'] : '';
				return $this->service->confirm_refund( $draft_id )->toLegacyArray();

			default:
				return array( 'error' => "Unknown tool: {$name}" );
		}
	}
}
