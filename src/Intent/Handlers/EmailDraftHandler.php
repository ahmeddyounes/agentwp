<?php
/**
 * Handle email draft intents.
 *
 * @package AgentWP
 */

namespace AgentWP\Intent\Handlers;

use AgentWP\Contracts\AIClientFactoryInterface;
use AgentWP\Contracts\ToolDispatcherInterface;
use AgentWP\Contracts\ToolRegistryInterface;
use AgentWP\Intent\Attributes\HandlesIntent;
use AgentWP\Intent\Intent;

/**
 * Handles email draft intents using the agentic loop.
 *
 * Uses the centrally-registered DraftEmailTool for execution.
 */
#[HandlesIntent( Intent::EMAIL_DRAFT )]
class EmailDraftHandler extends AbstractAgenticHandler {

	/**
	 * Initialize email draft intent handler.
	 *
	 * @param AIClientFactoryInterface $clientFactory  AI client factory.
	 * @param ToolRegistryInterface    $toolRegistry   Tool registry.
	 * @param ToolDispatcherInterface  $toolDispatcher Tool dispatcher with pre-registered tools.
	 */
	public function __construct(
		AIClientFactoryInterface $clientFactory,
		ToolRegistryInterface $toolRegistry,
		ToolDispatcherInterface $toolDispatcher
	) {
		parent::__construct( Intent::EMAIL_DRAFT, $clientFactory, $toolRegistry, $toolDispatcher );
	}

	/**
	 * Register tool executors with the dispatcher.
	 *
	 * No-op: Tools are pre-registered via the container.
	 *
	 * @param ToolDispatcherInterface $dispatcher The tool dispatcher.
	 * @return void
	 */
	protected function registerToolExecutors( ToolDispatcherInterface $dispatcher ): void {
		unset( $dispatcher );

		// Tools are pre-registered via IntentServiceProvider::registerToolDispatcher().
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
