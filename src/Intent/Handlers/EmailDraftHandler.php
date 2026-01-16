<?php
/**
 * Handle email draft intents.
 *
 * @package AgentWP
 */

namespace AgentWP\Intent\Handlers;

use AgentWP\AI\Functions\DraftEmail;
use AgentWP\Contracts\AIClientFactoryInterface;
use AgentWP\Contracts\EmailDraftServiceInterface;
use AgentWP\Intent\Intent;

/**
 * Handles email draft intents using the agentic loop.
 */
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
	 */
	public function __construct(
		EmailDraftServiceInterface $service,
		AIClientFactoryInterface $clientFactory
	) {
		parent::__construct( Intent::EMAIL_DRAFT, $clientFactory );
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
	 * Get the tools available for email drafting.
	 *
	 * @return array
	 */
	protected function getTools(): array {
		return array( new DraftEmail() );
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
	 * @return mixed Tool execution result.
	 */
	public function execute_tool( string $name, array $arguments ) {
		if ( 'draft_email' === $name ) {
			$order_id = isset( $arguments['order_id'] ) ? (int) $arguments['order_id'] : 0;
			return $this->service->get_order_context( $order_id );
		}

		return array( 'error' => "Unknown tool: {$name}" );
	}
}
