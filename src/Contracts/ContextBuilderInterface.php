<?php
/**
 * Context builder interface.
 *
 * @package AgentWP\Contracts
 */

namespace AgentWP\Contracts;

/**
 * Contract for context enrichment services.
 */
interface ContextBuilderInterface {

	/**
	 * Build enriched context for intent processing.
	 *
	 * @param array $context  Base context.
	 * @param array $metadata Request metadata.
	 * @return array Enriched context.
	 */
	public function build( array $context = array(), array $metadata = array() ): array;
}
