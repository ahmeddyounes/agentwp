<?php
/**
 * Intent classifier interface.
 *
 * @package AgentWP\Contracts
 */

namespace AgentWP\Contracts;

/**
 * Contract for intent classification services.
 */
interface IntentClassifierInterface {

	/**
	 * Classify user input into an intent type.
	 *
	 * @param string $input   The user input to classify.
	 * @param array  $context Optional context for classification.
	 * @return string The classified intent type.
	 */
	public function classify( string $input, array $context = array() ): string;
}
