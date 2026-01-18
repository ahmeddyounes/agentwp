<?php
/**
 * Handle email draft intents.
 *
 * @package AgentWP
 */

namespace AgentWP\Intent\Handlers;

use AgentWP\Contracts\AIClientFactoryInterface;
use AgentWP\Contracts\EmailDraftServiceInterface;
use AgentWP\Contracts\ToolDispatcherInterface;
use AgentWP\Contracts\ToolRegistryInterface;
use AgentWP\Intent\Attributes\HandlesIntent;
use AgentWP\Intent\Intent;

/**
 * Handles email draft intents using the agentic loop.
 */
#[HandlesIntent( Intent::EMAIL_DRAFT )]
class EmailDraftHandler extends AbstractAgenticHandler {

	/**
	 * @var EmailDraftServiceInterface
	 */
	private EmailDraftServiceInterface $service;

	/**
	 * Initialize email draft intent handler.
	 *
	 * @param EmailDraftServiceInterface   $service        Email draft service.
	 * @param AIClientFactoryInterface     $clientFactory  AI client factory.
	 * @param ToolRegistryInterface        $toolRegistry   Tool registry.
	 * @param ToolDispatcherInterface|null $toolDispatcher Tool dispatcher (optional).
	 */
	public function __construct(
		EmailDraftServiceInterface $service,
		AIClientFactoryInterface $clientFactory,
		ToolRegistryInterface $toolRegistry,
		?ToolDispatcherInterface $toolDispatcher = null
	) {
		$this->service = $service;
		parent::__construct( Intent::EMAIL_DRAFT, $clientFactory, $toolRegistry, $toolDispatcher );
	}

	/**
	 * Register tool executors with the dispatcher.
	 *
	 * @param ToolDispatcherInterface $dispatcher The tool dispatcher.
	 * @return void
	 */
	protected function registerToolExecutors( ToolDispatcherInterface $dispatcher ): void {
		$dispatcher->register(
			'draft_email',
			function ( array $args ): array {
				$order_id = isset( $args['order_id'] ) ? (int) $args['order_id'] : 0;
				return $this->service->get_order_context( $order_id )->toLegacyArray();
			}
		);
	}

	/**
	 * Get the system prompt for email drafting.
	 *
	 * @return string
	 */
	protected function getSystemPrompt(): string {
		return 'You are an expert customer support agent. Use the draft_email tool to get order context, then write the email content for the user to review. Do not send it.';
	}

	/**
	 * Get the tool names for email drafting.
	 *
	 * @return array<string>
	 */
	protected function getToolNames(): array {
		return array( 'draft_email' );
	}

	/**
	 * Get the default input for email drafting.
	 *
	 * @return string
	 */
	protected function getDefaultInput(): string {
		return 'Draft an email';
	}
}
