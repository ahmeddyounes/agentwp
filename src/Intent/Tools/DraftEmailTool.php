<?php
/**
 * Executable tool for drafting customer emails.
 *
 * @package AgentWP\Intent\Tools
 */

namespace AgentWP\Intent\Tools;

use AgentWP\Contracts\EmailDraftServiceInterface;
use AgentWP\Contracts\ExecutableToolInterface;

/**
 * Gets order context for drafting customer emails.
 *
 * Retrieves relevant order data that can be used by the AI
 * to compose contextually appropriate emails.
 */
class DraftEmailTool implements ExecutableToolInterface {

	/**
	 * @var EmailDraftServiceInterface
	 */
	private EmailDraftServiceInterface $service;

	/**
	 * Initialize the tool.
	 *
	 * @param EmailDraftServiceInterface $service Email draft service.
	 */
	public function __construct( EmailDraftServiceInterface $service ) {
		$this->service = $service;
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'draft_email';
	}

	/**
	 * Get order context for email drafting.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Order context data for email composition.
	 */
	public function execute( array $arguments ): array {
		$order_id = isset( $arguments['order_id'] ) ? (int) $arguments['order_id'] : 0;
		$intent   = isset( $arguments['intent'] ) ? (string) $arguments['intent'] : '';
		$tone     = isset( $arguments['tone'] ) ? (string) $arguments['tone'] : 'professional';
		$custom   = isset( $arguments['custom_instructions'] ) ? (string) $arguments['custom_instructions'] : '';

		$result = $this->service->get_order_context( $order_id );

		if ( $result->isFailure() ) {
			return $result->toLegacyArray();
		}

		// Enrich the context with the requested email parameters.
		$data = $result->toLegacyArray();
		$data['email_intent']       = $intent;
		$data['email_tone']         = $tone;
		$data['custom_instructions'] = $custom;

		return $data;
	}
}
