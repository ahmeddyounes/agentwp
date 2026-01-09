<?php
/**
 * Stock intent scorer.
 *
 * @package AgentWP\Intent\Classifier\Scorers
 */

namespace AgentWP\Intent\Classifier\Scorers;

use AgentWP\Intent\Classifier\AbstractScorer;
use AgentWP\Intent\Intent;

/**
 * Scores input for product stock intent.
 */
final class StockScorer extends AbstractScorer {

	/**
	 * Phrases that indicate stock intent.
	 *
	 * @var string[]
	 */
	private const PHRASES = array(
		'stock',
		'inventory',
		'available',
		'availability',
		'out of stock',
		'restock',
	);

	/**
	 * Get the intent type.
	 *
	 * @return string
	 */
	public function getIntent(): string {
		return Intent::PRODUCT_STOCK;
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
