<?php
/**
 * Handle customer lookup intents.
 *
 * @package AgentWP
 */

namespace AgentWP\Intent\Handlers;

use AgentWP\AI\Functions\GetCustomerProfile;
use AgentWP\Contracts\AIClientFactoryInterface;
use AgentWP\Contracts\CustomerServiceInterface;
use AgentWP\Intent\Intent;

/**
 * Handles customer lookup intents using the agentic loop.
 */
class CustomerLookupHandler extends AbstractAgenticHandler {

	/**
	 * @var CustomerServiceInterface
	 */
	private CustomerServiceInterface $service;

	/**
	 * Initialize customer lookup intent handler.
	 *
	 * @param CustomerServiceInterface $service       Customer service.
	 * @param AIClientFactoryInterface $clientFactory AI client factory.
	 */
	public function __construct(
		CustomerServiceInterface $service,
		AIClientFactoryInterface $clientFactory
	) {
		parent::__construct( Intent::CUSTOMER_LOOKUP, $clientFactory );
		$this->service = $service;
	}

	/**
	 * Get the system prompt for customer lookup.
	 *
	 * @return string
	 */
	protected function getSystemPrompt(): string {
		return 'You are a customer success manager. Look up customer profiles and summarize key metrics (LTV, last order, health).';
	}

	/**
	 * Get the tools available for customer lookup.
	 *
	 * @return array
	 */
	protected function getTools(): array {
		return array( new GetCustomerProfile() );
	}

	/**
	 * Get the default input for customer lookup.
	 *
	 * @return string
	 */
	protected function getDefaultInput(): string {
		return 'Look up customer';
	}

	/**
	 * Execute a named tool with arguments.
	 *
	 * @param string $name      Tool name.
	 * @param array  $arguments Tool arguments.
	 * @return mixed Tool execution result.
	 */
	public function execute_tool( string $name, array $arguments ) {
		if ( 'get_customer_profile' === $name ) {
			return $this->service->handle( $arguments );
		}

		return array( 'error' => "Unknown tool: {$name}" );
	}
}
