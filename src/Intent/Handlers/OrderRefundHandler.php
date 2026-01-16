<?php
/**
 * Handle order refund intents.
 *
 * @package AgentWP
 */

namespace AgentWP\Intent\Handlers;

use AgentWP\AI\Functions\PrepareRefund;
use AgentWP\AI\Functions\ConfirmRefund;
use AgentWP\Contracts\AIClientFactoryInterface;
use AgentWP\Contracts\OrderRefundServiceInterface;
use AgentWP\Intent\Intent;

/**
 * Handles order refund intents using the agentic loop.
 */
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
	 */
	public function __construct(
		OrderRefundServiceInterface $service,
		AIClientFactoryInterface $clientFactory
	) {
		parent::__construct( Intent::ORDER_REFUND, $clientFactory );
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
	 * Get the tools available for refund handling.
	 *
	 * @return array
	 */
	protected function getTools(): array {
		return array( new PrepareRefund(), new ConfirmRefund() );
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
	 * @return mixed Tool execution result.
	 */
	public function execute_tool( string $name, array $arguments ) {
		switch ( $name ) {
			case 'prepare_refund':
				$order_id      = isset( $arguments['order_id'] ) ? (int) $arguments['order_id'] : 0;
				$amount        = isset( $arguments['amount'] ) ? $arguments['amount'] : null;
				$reason        = isset( $arguments['reason'] ) ? $arguments['reason'] : '';
				$restock_items = isset( $arguments['restock_items'] ) ? (bool) $arguments['restock_items'] : true;

				return $this->service->prepare_refund( $order_id, $amount, $reason, $restock_items );

			case 'confirm_refund':
				$draft_id = isset( $arguments['draft_id'] ) ? (string) $arguments['draft_id'] : '';
				return $this->service->confirm_refund( $draft_id );

			default:
				return array( 'error' => "Unknown tool: {$name}" );
		}
	}
}
