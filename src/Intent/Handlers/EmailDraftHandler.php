<?php
/**
 * Handle email draft intents.
 *
 * @package AgentWP
 */

namespace AgentWP\Intent\Handlers;

use AgentWP\Contracts\AIClientFactoryInterface;
use AgentWP\Contracts\EmailDraftServiceInterface;
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
	 * @param EmailDraftServiceInterface $service       Email draft service.
	 * @param AIClientFactoryInterface   $clientFactory AI client factory.
	 * @param ToolRegistryInterface      $toolRegistry  Tool registry.
	 */
	public function __construct(
		EmailDraftServiceInterface $service,
		AIClientFactoryInterface $clientFactory,
		ToolRegistryInterface $toolRegistry
	) {
		parent::__construct( Intent::EMAIL_DRAFT, $clientFactory, $toolRegistry );
		$this->service = $service;
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

	/**
	 * Execute a named tool with arguments.
	 *
	 * @param string $name      Tool name.
	 * @param array  $arguments Tool arguments.
	 * @return array Tool execution result.
	 */
	public function execute_tool( string $name, array $arguments ) {
		if ( 'draft_email' === $name ) {
			$order_id = isset( $arguments['order_id'] ) ? (int) $arguments['order_id'] : 0;
			return $this->service->get_order_context( $order_id )->toLegacyArray();
		}

		return array( 'error' => "Unknown tool: {$name}" );
	}
}
