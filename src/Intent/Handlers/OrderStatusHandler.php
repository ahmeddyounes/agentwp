<?php
/**
 * Handle order status intents.
 *
 * @package AgentWP\Intent\Handlers
 */

namespace AgentWP\Intent\Handlers;

use AgentWP\AI\Functions\PrepareStatusUpdate;
use AgentWP\AI\Functions\PrepareBulkStatusUpdate;
use AgentWP\AI\Functions\ConfirmStatusUpdate;
use AgentWP\Contracts\AIClientFactoryInterface;
use AgentWP\Contracts\OrderStatusServiceInterface;
use AgentWP\Intent\Intent;

/**
 * Handles order status intents using the agentic loop.
 */
class OrderStatusHandler extends AbstractAgenticHandler {

	/**
	 * @var OrderStatusServiceInterface
	 */
	private OrderStatusServiceInterface $service;

	/**
	 * Initialize order status intent handler.
	 *
	 * @param OrderStatusServiceInterface $service       Status service.
	 * @param AIClientFactoryInterface    $clientFactory AI client factory.
	 */
	public function __construct(
		OrderStatusServiceInterface $service,
		AIClientFactoryInterface $clientFactory
	) {
		parent::__construct( Intent::ORDER_STATUS, $clientFactory );
		$this->service = $service;
	}

	/**
	 * Get the system prompt for status handling.
	 *
	 * @return string
	 */
	protected function getSystemPrompt(): string {
		return 'You are an expert WooCommerce assistant. You can check and update order statuses (single or bulk). Always prepare updates first.';
	}

	/**
	 * Get the tools available for status handling.
	 *
	 * @return array
	 */
	protected function getTools(): array {
		return array( new PrepareStatusUpdate(), new PrepareBulkStatusUpdate(), new ConfirmStatusUpdate() );
	}

	/**
	 * Get the default input for status operations.
	 *
	 * @return string
	 */
	protected function getDefaultInput(): string {
		return 'Check order status';
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
			case 'prepare_status_update':
				$order_id = isset( $arguments['order_id'] ) ? (int) $arguments['order_id'] : 0;
				$status   = isset( $arguments['new_status'] ) ? (string) $arguments['new_status'] : '';
				$note     = isset( $arguments['note'] ) ? (string) $arguments['note'] : '';
				$notify   = isset( $arguments['notify_customer'] ) ? (bool) $arguments['notify_customer'] : false;
				return $this->service->prepare_update( $order_id, $status, $note, $notify );

			case 'prepare_bulk_status_update':
				$order_ids = isset( $arguments['order_ids'] ) ? array_map( 'intval', (array) $arguments['order_ids'] ) : array();
				$status    = isset( $arguments['new_status'] ) ? (string) $arguments['new_status'] : '';
				$notify    = isset( $arguments['notify_customer'] ) ? (bool) $arguments['notify_customer'] : false;
				return $this->service->prepare_bulk_update( $order_ids, $status, $notify );

			case 'confirm_status_update':
				$draft_id = isset( $arguments['draft_id'] ) ? (string) $arguments['draft_id'] : '';
				return $this->service->confirm_update( $draft_id );

			default:
				return array( 'error' => "Unknown tool: {$name}" );
		}
	}
}
