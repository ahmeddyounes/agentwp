<?php
/**
 * Handle order status intents.
 *
 * @package AgentWP\Intent\Handlers
 */

namespace AgentWP\Intent\Handlers;

use AgentWP\Contracts\AIClientFactoryInterface;
use AgentWP\Contracts\OrderStatusServiceInterface;
use AgentWP\Contracts\ToolDispatcherInterface;
use AgentWP\Contracts\ToolRegistryInterface;
use AgentWP\Intent\Attributes\HandlesIntent;
use AgentWP\Intent\Intent;

/**
 * Handles order status intents using the agentic loop.
 */
#[HandlesIntent( Intent::ORDER_STATUS )]
class OrderStatusHandler extends AbstractAgenticHandler {

	/**
	 * @var OrderStatusServiceInterface
	 */
	private OrderStatusServiceInterface $service;

	/**
	 * Initialize order status intent handler.
	 *
	 * @param OrderStatusServiceInterface      $service        Status service.
	 * @param AIClientFactoryInterface         $clientFactory  AI client factory.
	 * @param ToolRegistryInterface            $toolRegistry   Tool registry.
	 * @param ToolDispatcherInterface|null     $toolDispatcher Tool dispatcher (optional).
	 */
	public function __construct(
		OrderStatusServiceInterface $service,
		AIClientFactoryInterface $clientFactory,
		ToolRegistryInterface $toolRegistry,
		?ToolDispatcherInterface $toolDispatcher = null
	) {
		$this->service = $service;
		parent::__construct( Intent::ORDER_STATUS, $clientFactory, $toolRegistry, $toolDispatcher );
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
				'prepare_status_update'      => function ( array $args ): array {
					$order_id = isset( $args['order_id'] ) ? (int) $args['order_id'] : 0;
					$status   = isset( $args['new_status'] ) ? (string) $args['new_status'] : '';
					$note     = isset( $args['note'] ) ? (string) $args['note'] : '';
					$notify   = isset( $args['notify_customer'] ) ? (bool) $args['notify_customer'] : false;
					return $this->service->prepare_update( $order_id, $status, $note, $notify )->toLegacyArray();
				},
				'prepare_bulk_status_update' => function ( array $args ): array {
					$order_ids = isset( $args['order_ids'] ) ? array_map( 'intval', (array) $args['order_ids'] ) : array();
					$status    = isset( $args['new_status'] ) ? (string) $args['new_status'] : '';
					$notify    = isset( $args['notify_customer'] ) ? (bool) $args['notify_customer'] : false;
					return $this->service->prepare_bulk_update( $order_ids, $status, $notify )->toLegacyArray();
				},
				'confirm_status_update'      => function ( array $args ): array {
					$draft_id = isset( $args['draft_id'] ) ? (string) $args['draft_id'] : '';
					return $this->service->confirm_update( $draft_id )->toLegacyArray();
				},
			)
		);
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
	 * Get the tool names for status handling.
	 *
	 * @return array<string>
	 */
	protected function getToolNames(): array {
		return array( 'prepare_status_update', 'prepare_bulk_status_update', 'confirm_status_update' );
	}

	/**
	 * Get the default input for status operations.
	 *
	 * @return string
	 */
	protected function getDefaultInput(): string {
		return 'Check order status';
	}
}
