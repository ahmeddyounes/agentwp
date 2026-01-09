<?php
/**
 * Scorer registry for intent classification.
 *
 * @package AgentWP\Intent\Classifier
 */

namespace AgentWP\Intent\Classifier;

use AgentWP\Intent\Intent;

/**
 * Manages and executes intent scorers.
 */
final class ScorerRegistry {

	/**
	 * Maximum input length to process (characters).
	 * Prevents DoS via extremely long input strings.
	 *
	 * @var int
	 */
	private const MAX_INPUT_LENGTH = 10000;

	/**
	 * Registered scorers.
	 *
	 * @var IntentScorerInterface[]
	 */
	private array $scorers = array();

	/**
	 * Register a scorer.
	 *
	 * @param IntentScorerInterface $scorer The scorer to register.
	 * @return void
	 */
	public function register( IntentScorerInterface $scorer ): void {
		$intent = $scorer->getIntent();
		$this->scorers[ $intent ] = $scorer;
	}

	/**
	 * Register multiple scorers.
	 *
	 * @param IntentScorerInterface[] $scorers Scorers to register.
	 * @return void
	 */
	public function registerMany( array $scorers ): void {
		foreach ( $scorers as $scorer ) {
			$this->register( $scorer );
		}
	}

	/**
	 * Classify input text by scoring all registered intents.
	 *
	 * @param string $input   User input.
	 * @param array  $context Enriched context.
	 * @return string The best matching intent or Intent::UNKNOWN.
	 */
	public function classify( string $input, array $context = array() ): string {
		// Check for explicit intent override.
		if ( isset( $context['intent'] ) ) {
			$override = Intent::normalize( $context['intent'] );

			if ( Intent::UNKNOWN !== $override ) {
				return $override;
			}
		}

		$text = strtolower( trim( $input ) );

		if ( '' === $text ) {
			return Intent::UNKNOWN;
		}

		// Limit input length to prevent DoS via regex matching on huge strings.
		// Use mb_* functions to avoid corrupting multi-byte UTF-8 characters.
		if ( mb_strlen( $text, 'UTF-8' ) > self::MAX_INPUT_LENGTH ) {
			$text = mb_substr( $text, 0, self::MAX_INPUT_LENGTH, 'UTF-8' );
		}

		$scores = $this->scoreAll( $text, $context );

		return $this->selectBestIntent( $scores );
	}

	/**
	 * Score all registered intents.
	 *
	 * @param string $text    Normalized text.
	 * @param array  $context Additional context.
	 * @return array<string, int> Intent => score mapping.
	 */
	public function scoreAll( string $text, array $context = array() ): array {
		$scores = array();

		foreach ( $this->scorers as $intent => $scorer ) {
			$scores[ $intent ] = $scorer->score( $text, $context );
		}

		return $scores;
	}

	/**
	 * Select the best intent from scores.
	 *
	 * Uses alphabetical ordering as tie-breaker for deterministic results.
	 *
	 * @param array<string, int> $scores Intent => score mapping.
	 * @return string Best intent or Intent::UNKNOWN.
	 */
	private function selectBestIntent( array $scores ): string {
		$bestIntent = Intent::UNKNOWN;
		$bestScore  = 0;

		foreach ( $scores as $intent => $score ) {
			// Higher score wins, or alphabetically first on tie.
			if ( $score > $bestScore || ( $score === $bestScore && $score > 0 && $intent < $bestIntent ) ) {
				$bestScore  = $score;
				$bestIntent = $intent;
			}
		}

		return $bestScore > 0 ? $bestIntent : Intent::UNKNOWN;
	}

	/**
	 * Check if a scorer is registered for an intent.
	 *
	 * @param string $intent The intent type.
	 * @return bool True if scorer exists.
	 */
	public function has( string $intent ): bool {
		return isset( $this->scorers[ $intent ] );
	}

	/**
	 * Get all registered scorers.
	 *
	 * @return IntentScorerInterface[] Registered scorers.
	 */
	public function all(): array {
		return $this->scorers;
	}

	/**
	 * Get the number of registered scorers.
	 *
	 * @return int Scorer count.
	 */
	public function count(): int {
		return count( $this->scorers );
	}

	/**
	 * Create a registry with default scorers.
	 *
	 * @return self Configured registry.
	 */
	public static function withDefaults(): self {
		$registry = new self();

		$registry->registerMany(
			array(
				new Scorers\RefundScorer(),
				new Scorers\StatusScorer(),
				new Scorers\StockScorer(),
				new Scorers\EmailScorer(),
				new Scorers\AnalyticsScorer(),
				new Scorers\CustomerScorer(),
				new Scorers\SearchScorer(),
			)
		);

		return $registry;
	}
}
