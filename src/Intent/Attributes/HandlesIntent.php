<?php
/**
 * Intent handler attribute for auto-discovery.
 *
 * @package AgentWP
 */

namespace AgentWP\Intent\Attributes;

/**
 * Attribute to declare which intents a handler can process.
 *
 * Usage:
 * ```php
 * #[HandlesIntent(Intent::ORDER_SEARCH)]
 * class OrderSearchHandler extends AbstractAgenticHandler {
 *     // ...
 * }
 *
 * // Multiple intents:
 * #[HandlesIntent([Intent::ORDER_STATUS, Intent::ORDER_REFUND])]
 * class OrderManagementHandler extends AbstractAgenticHandler {
 *     // ...
 * }
 * ```
 *
 * This enables Open/Closed principle compliance - new handlers can be added
 * without modifying the Engine class.
 */
#[\Attribute]
class HandlesIntent {
	/**
	 * Intent or intents this handler can process.
	 *
	 * @var string|string[]
	 */
	public readonly string|array $intents;

	/**
	 * Create a new HandlesIntent attribute.
	 *
	 * @param string|string[] $intents Single intent or array of intents.
	 */
	public function __construct( string|array $intents ) {
		$this->intents = $intents;
	}

	/**
	 * Get the intents as an array.
	 *
	 * @return string[] Array of intent identifiers.
	 */
	public function getIntents(): array {
		return is_array( $this->intents ) ? $this->intents : [ $this->intents ];
	}

	/**
	 * Check if this attribute matches a specific intent.
	 *
	 * @param string $intent Intent identifier.
	 * @return bool True if this handler can process the intent.
	 */
	public function matches( string $intent ): bool {
		return in_array( $intent, $this->getIntents(), true );
	}
}
