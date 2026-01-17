<?php
/**
 * Handle customer lookup intents.
 *
 * @package AgentWP
 */

namespace AgentWP\Intent\Handlers;

use AgentWP\Contracts\AIClientFactoryInterface;
use AgentWP\Contracts\CustomerServiceInterface;
use AgentWP\Contracts\ToolRegistryInterface;
use AgentWP\Intent\Attributes\HandlesIntent;
use AgentWP\Intent\Intent;

/**
 * Handles customer lookup intents using the agentic loop.
 */
#[HandlesIntent( Intent::CUSTOMER_LOOKUP )]
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
	 * @param ToolRegistryInterface    $toolRegistry  Tool registry.
	 */
	public function __construct(
		CustomerServiceInterface $service,
		AIClientFactoryInterface $clientFactory,
		ToolRegistryInterface $toolRegistry
	) {
		parent::__construct( Intent::CUSTOMER_LOOKUP, $clientFactory, $toolRegistry );
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
	 * Get the tool names for customer lookup.
	 *
	 * @return array<string>
	 */
	protected function getToolNames(): array {
		return array( 'get_customer_profile' );
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
