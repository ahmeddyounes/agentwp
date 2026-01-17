<?php
/**
 * Scorer registry for intent classification.
 *
 * @package AgentWP\Intent\Classifier
 */

namespace AgentWP\Intent\Classifier;

use AgentWP\Config\AgentWPConfig;
use AgentWP\Contracts\IntentClassifierInterface;
use AgentWP\Infrastructure\WPFunctions;
use AgentWP\Intent\Intent;

/**
 * Manages and executes intent scorers.
 *
 * This registry is the canonical intent classification mechanism per ADR 0003.
 * It implements IntentClassifierInterface and supports extensibility via the
 * 'agentwp_intent_scorers' filter for third-party scorers.
 *
 * Scoring is config-aware:
 * - Weights from AgentWPConfig are applied to raw scores
 * - Minimum threshold filters out low-confidence matches
 * - Deterministic tie-breaking uses alphabetical ordering
 */
final class ScorerRegistry implements IntentClassifierInterface {

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
	 * WordPress functions wrapper.
	 *
	 * Accepts any object with doAction() method for testability.
	 *
	 * @var WPFunctions|object|null
	 */
	private $wp = null;

	/**
	 * Constructor.
	 *
	 * @param WPFunctions|object|null $wp WordPress functions wrapper for action/filter hooks.
	 *                                    Accepts any object with doAction() method for testability.
	 */
	public function __construct( $wp = null ) {
		$this->wp = $wp;
	}

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
	 * Fires the 'agentwp_intent_classified' action after classification.
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
				$this->fireClassifiedAction( $override, array(), $input, $context );
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
		$intent = $this->selectBestIntent( $scores );

		$this->fireClassifiedAction( $intent, $scores, $input, $context );

		return $intent;
	}

	/**
	 * Fire the 'agentwp_intent_classified' action.
	 *
	 * @param string $intent  The classified intent constant.
	 * @param array  $scores  All intent scores from scoreAll().
	 * @param string $input   Original user input.
	 * @param array  $context Classification context.
	 * @return void
	 */
	private function fireClassifiedAction( string $intent, array $scores, string $input, array $context ): void {
		if ( null === $this->wp ) {
			return;
		}

		$this->wp->doAction( 'agentwp_intent_classified', $intent, $scores, $input, $context );
	}

	/**
	 * Score all registered intents with config-aware weighting.
	 *
	 * Each raw score is multiplied by the scorer's weight from AgentWPConfig.
	 * This allows fine-tuning intent priorities via WordPress filters.
	 *
	 * @param string $text    Normalized text.
	 * @param array  $context Additional context.
	 * @return array<string, float> Intent => weighted score mapping.
	 */
	public function scoreAll( string $text, array $context = array() ): array {
		$scores = array();

		foreach ( $this->scorers as $intent => $scorer ) {
			$rawScore         = $scorer->score( $text, $context );
			$weight           = $scorer->getWeight();
			$scores[ $intent ] = $rawScore * $weight;
		}

		return $scores;
	}

	/**
	 * Get the raw (unweighted) scores for all registered intents.
	 *
	 * Useful for debugging or when weights should not be applied.
	 *
	 * @param string $text    Normalized text.
	 * @param array  $context Additional context.
	 * @return array<string, int> Intent => raw score mapping.
	 */
	public function scoreAllRaw( string $text, array $context = array() ): array {
		$scores = array();

		foreach ( $this->scorers as $intent => $scorer ) {
			$scores[ $intent ] = $scorer->score( $text, $context );
		}

		return $scores;
	}

	/**
	 * Select the best intent from weighted scores.
	 *
	 * Applies threshold filtering and uses alphabetical ordering as tie-breaker
	 * for deterministic results. The minimum threshold is configurable via
	 * the 'confidence.threshold.low' config key.
	 *
	 * @param array<string, float> $scores Intent => weighted score mapping.
	 * @return string Best intent or Intent::UNKNOWN.
	 */
	private function selectBestIntent( array $scores ): string {
		$bestIntent = Intent::UNKNOWN;
		$bestScore  = 0.0;

		// Get minimum threshold from config (default 0 for backward compatibility).
		// Using 0 as default means any positive score will be considered.
		$minThreshold = (float) AgentWPConfig::get( 'intent.minimum_threshold', 0.0 );

		// Sort keys alphabetically first for deterministic tie-breaking.
		// This ensures iteration order is consistent across PHP versions.
		ksort( $scores );

		foreach ( $scores as $intent => $score ) {
			// Skip scores below the minimum threshold.
			if ( $score < $minThreshold ) {
				continue;
			}

			// Higher score wins; on tie, first alphabetically wins (already sorted).
			if ( $score > $bestScore ) {
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
