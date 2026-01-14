<?php
/**
 * Refund intent scorer.
 *
 * @package AgentWP\Intent\Classifier\Scorers
 */

namespace AgentWP\Intent\Classifier\Scorers;

use AgentWP\Intent\Classifier\AbstractScorer;
use AgentWP\Intent\Intent;

/**
 * Scores input for order refund intent.
 */
final class RefundScorer extends AbstractScorer {

	/**
	 * Phrases that indicate refund intent.
	 *
	 * @var string[]
	 */
	private const PHRASES = array(
		'refund',
		'return',
		'chargeback',
		'money back',
		'reimburse',
	);

	/**
	 * Get the intent type.
	 *
	 * @return string
	 */
	public function getIntent(): string {
		return Intent::ORDER_REFUND;
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
