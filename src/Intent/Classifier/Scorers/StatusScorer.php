<?php
/**
 * Status intent scorer.
 *
 * @package AgentWP\Intent\Classifier\Scorers
 */

namespace AgentWP\Intent\Classifier\Scorers;

use AgentWP\Intent\Classifier\AbstractScorer;
use AgentWP\Intent\Intent;

/**
 * Scores input for order status intent.
 */
final class StatusScorer extends AbstractScorer {

	/**
	 * Phrases that indicate status intent.
	 *
	 * @var string[]
	 */
	private const PHRASES = array(
		'status',
		'tracking',
		'track',
		'shipment',
		'shipping',
		'delivery',
		'where is',
		'eta',
	);

	/**
	 * Get the intent type.
	 *
	 * @return string
	 */
	public function getIntent(): string {
		return Intent::ORDER_STATUS;
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
