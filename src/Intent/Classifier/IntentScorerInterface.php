<?php
/**
 * Intent scorer interface.
 *
 * @package AgentWP\Intent\Classifier
 */

namespace AgentWP\Intent\Classifier;

/**
 * Contract for intent scoring strategies.
 */
interface IntentScorerInterface {

	/**
	 * Get the intent type this scorer handles.
	 *
	 * @return string Intent type constant from Intent class.
	 */
	public function getIntent(): string;

	/**
	 * Score the input text for this intent.
	 *
	 * @param string $text    Normalized (lowercased, trimmed) input text.
	 * @param array  $context Additional context data.
	 * @return int Score (0 = no match, higher = stronger match).
	 */
	public function score( string $text, array $context = array() ): int;

	/**
	 * Get the configuration weight for this scorer's intent.
	 *
	 * The weight is applied by the ScorerRegistry when calculating
	 * final weighted scores. Weights are configurable via AgentWPConfig
	 * and WordPress filters.
	 *
	 * @return float Weight multiplier (default 1.0).
	 */
	public function getWeight(): float;
}
