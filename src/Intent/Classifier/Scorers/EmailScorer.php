<?php
/**
 * Email intent scorer.
 *
 * @package AgentWP\Intent\Classifier\Scorers
 */

namespace AgentWP\Intent\Classifier\Scorers;

use AgentWP\Intent\Classifier\AbstractScorer;
use AgentWP\Intent\Intent;

/**
 * Scores input for email draft intent.
 */
final class EmailScorer extends AbstractScorer {

	/**
	 * Phrases that indicate email subject.
	 *
	 * @var string[]
	 */
	private const EMAIL_PHRASES = array(
		'email',
		'message',
	);

	/**
	 * Phrases that indicate email action.
	 *
	 * @var string[]
	 */
	private const ACTION_PHRASES = array(
		'compose',
		'draft',
		'write',
		'reply',
		'send',
	);

	/**
	 * Get the intent type.
	 *
	 * @return string
	 */
	public function getIntent(): string {
		return Intent::EMAIL_DRAFT;
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
		$hasEmail  = $this->containsAny( $text, self::EMAIL_PHRASES );
		$hasAction = $this->containsAny( $text, self::ACTION_PHRASES );

		if ( $hasEmail && $hasAction ) {
			return 2;
		}

		if ( $hasAction ) {
			return 1;
		}

		return 0;
	}
}
