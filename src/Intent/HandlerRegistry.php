<?php
/**
 * Intent handler registry for O(1) handler resolution.
 *
 * @package AgentWP
 */

namespace AgentWP\Intent;

/**
 * Registry for intent handlers with O(1) lookup performance.
 *
 * Replaces the O(n) linear search in Engine::resolve_handler()
 * with a hashmap-based approach.
 */
class HandlerRegistry {
	/**
	 * @var Handler[] Map of intent to handler instance.
	 */
	private array $handlers = [];

	/**
	 * @var Handler[] All registered handlers.
	 */
	private array $handlerInstances = [];

	/**
	 * Register a handler for one or more intents.
	 *
	 * @param string|string[] $intent Intent or array of intents.
	 * @param Handler         $handler Handler instance.
	 * @return void
	 */
	public function register( string|array $intent, Handler $handler ): void {
		$intents = is_array( $intent ) ? $intent : [ $intent ];

		foreach ( $intents as $intentValue ) {
			// Skip empty intent strings to prevent invalid registrations.
			if ( empty( $intentValue ) ) {
				continue;
			}

			// Log warning when overwriting existing intent registration.
			if ( isset( $this->handlers[ $intentValue ] ) && $this->handlers[ $intentValue ] !== $handler ) {
				$existing_class = get_class( $this->handlers[ $intentValue ] );
				$new_class = get_class( $handler );
				error_log( sprintf(
					'Handler Registry: Intent "%s" already registered to %s, overwriting with %s',
					$intentValue,
					$existing_class,
					$new_class
				) );
			}

			$this->handlers[ $intentValue ] = $handler;
		}

		// Store unique handler instances.
		$handlerHash = spl_object_hash( $handler );
		if ( ! isset( $this->handlerInstances[ $handlerHash ] ) ) {
			$this->handlerInstances[ $handlerHash ] = $handler;
		}
	}

	/**
	 * Get a handler for a specific intent.
	 *
	 * @param string $intent Intent identifier.
	 * @return Handler|null Handler instance or null if not found.
	 */
	public function get( string $intent ): ?Handler {
		return $this->handlers[ $intent ] ?? null;
	}

	/**
	 * Check if a handler is registered for an intent.
	 *
	 * @param string $intent Intent identifier.
	 * @return bool True if handler exists.
	 */
	public function has( string $intent ): bool {
		return isset( $this->handlers[ $intent ] );
	}

	/**
	 * Get all registered handlers.
	 *
	 * @return Handler[] Array of all handler instances.
	 */
	public function all(): array {
		return array_values( $this->handlerInstances );
	}

	/**
	 * Get all registered intents.
	 *
	 * @return string[] Array of intent identifiers.
	 */
	public function intents(): array {
		return array_keys( $this->handlers );
	}

	/**
	 * Get the handler for an intent, or return a fallback if not found.
	 *
	 * @param string  $intent Intent identifier.
	 * @param Handler $fallback Fallback handler.
	 * @return Handler Handler instance or fallback.
	 */
	public function getOrFallback( string $intent, Handler $fallback ): Handler {
		return $this->get( $intent ) ?? $fallback;
	}

	/**
	 * Clear all registered handlers.
	 *
	 * @return void
	 */
	public function clear(): void {
		$this->handlers = [];
		$this->handlerInstances = [];
	}

	/**
	 * Get the number of registered intents.
	 *
	 * @return int Number of registered intents.
	 */
	public function count(): int {
		return count( $this->handlers );
	}
}
