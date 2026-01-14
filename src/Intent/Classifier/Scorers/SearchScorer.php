<?php
/**
 * Search intent scorer.
 *
 * @package AgentWP\Intent\Classifier\Scorers
 */

namespace AgentWP\Intent\Classifier\Scorers;

use AgentWP\Intent\Classifier\AbstractScorer;
use AgentWP\Intent\Intent;

/**
 * Scores input for order search intent.
 */
final class SearchScorer extends AbstractScorer {

	/**
	 * Phrases that indicate search intent.
	 *
	 * @var string[]
	 */
	private const PHRASES = array(
		'order',
		'search',
		'find',
		'lookup',
		'last order',
		'order id',
		'order #',
	);

	/**
	 * Get the intent type.
	 *
	 * @return string
	 */
	public function getIntent(): string {
		return Intent::ORDER_SEARCH;
	}

	/**
	 * Score the input text.
	 *
	 * @param string $text    Normalized input text.
	 * @param array  $context Additional context.
	 * @return int Score.
	 */
	public function score( string $text, array $context = array() ): int {
		unset( $context );
		return $this->matchScore( $text, self::PHRASES );
	}
}
