<?php
/**
 * Context provider interface.
 *
 * @package AgentWP
 */

namespace AgentWP\Intent\ContextProviders;

/**
 * Interface for context providers that enrich request context.
 *
 * Implementations can provide user data, order data, store settings,
 * or any other contextual information needed for intent processing.
 */
interface ContextProviderInterface {
	/**
	 * Provide context data.
	 *
	 * @param array $context Request context.
	 * @param array $metadata Request metadata.
	 * @return array Context data to merge.
	 */
	public function provide( array $context, array $metadata ): array;
}
