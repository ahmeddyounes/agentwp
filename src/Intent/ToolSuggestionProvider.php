<?php
/**
 * Tool suggestion provider contract for intent handlers.
 *
 * @package AgentWP
 */

namespace AgentWP\Intent;

interface ToolSuggestionProvider {
	/**
	 * Get tool names to surface as suggestions for this handler's intents.
	 *
	 * @return array<string>
	 */
	public function getSuggestedTools(): array;
}
