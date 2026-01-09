<?php
/**
 * Analytics intent scorer.
 *
 * @package AgentWP\Intent\Classifier\Scorers
 */

namespace AgentWP\Intent\Classifier\Scorers;

use AgentWP\Intent\Classifier\AbstractScorer;
use AgentWP\Intent\Intent;

/**
 * Scores input for analytics query intent.
 */
final class AnalyticsScorer extends AbstractScorer {

	/**
	 * Phrases that indicate analytics intent.
	 *
	 * @var string[]
	 */
	private const PHRASES = array(
		'analytics',
		'report',
		'sales',
		'revenue',
		'performance',
		'conversion',
		'aov',
		'average order',
	);

	/**
	 * Get the intent type.
	 *
	 * @return string
	 */
	public function getIntent(): string {
		return Intent::ANALYTICS_QUERY;
	}

	/**
	 * Score the input text.
	 *
	 * @param string $text    Normalized input text.
	 * @param array  $context Additional context.
	 * @return int Score.
	 */
	public function score( string $text, array $context = array() ): int {
		return $this->matchScore( $text, self::PHRASES );
	}
}
