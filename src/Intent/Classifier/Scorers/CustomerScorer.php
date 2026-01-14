<?php
/**
 * Customer intent scorer.
 *
 * @package AgentWP\Intent\Classifier\Scorers
 */

namespace AgentWP\Intent\Classifier\Scorers;

use AgentWP\Intent\Classifier\AbstractScorer;
use AgentWP\Intent\Intent;

/**
 * Scores input for customer lookup intent.
 */
final class CustomerScorer extends AbstractScorer {

	/**
	 * Phrases that indicate customer intent.
	 *
	 * @var string[]
	 */
	private const PHRASES = array(
		'customer',
		'profile',
		'buyer',
		'account',
		'loyalty',
	);

	/**
	 * Get the intent type.
	 *
	 * @return string
	 */
	public function getIntent(): string {
		return Intent::CUSTOMER_LOOKUP;
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
