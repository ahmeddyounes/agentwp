<?php
/**
 * Abstract base scorer.
 *
 * @package AgentWP\Intent\Classifier
 */

namespace AgentWP\Intent\Classifier;

/**
 * Base class for intent scorers with common matching utilities.
 */
abstract class AbstractScorer implements IntentScorerInterface {

	/**
	 * Score based on phrase matching.
	 *
	 * @param string $text    The input text.
	 * @param array  $phrases Phrases to match.
	 * @return int Number of matching phrases.
	 */
	protected function matchScore( string $text, array $phrases ): int {
		$score = 0;

		foreach ( $phrases as $phrase ) {
			if ( $this->containsPhrase( $text, $phrase ) ) {
				$score++;
			}
		}

		return $score;
	}

	/**
	 * Check if text contains a phrase with word boundary matching.
	 *
	 * @param string $text   The input text.
	 * @param string $phrase The phrase to find.
	 * @return bool True if phrase is found.
	 */
		protected function containsPhrase( string $text, string $phrase ): bool {
			$phrase = trim( $phrase );

			if ( '' === $phrase ) {
				return false;
			}

		// Normalize multiple consecutive spaces to single space before matching.
		$normalized = preg_replace( '/\s+/', ' ', $phrase );
		$phrase     = is_string( $normalized ) ? $normalized : $phrase;

		// Use word boundary regex to avoid partial matches.
		// For example, "refund" should not match "refunding" or "nonrefundable".
			// Convert escaped spaces to flexible whitespace for multi-word phrases.
			$escaped = preg_quote( $phrase, '/' );
			$pattern = '/\b' . str_replace( '\\ ', '\\s+', $escaped ) . '\b/i';

			$result = preg_match( $pattern, $text );

			// Handle regex error (e.g., malformed pattern).
			if ( false === $result ) {
				return false;
			}

			return (bool) $result;
		}

	/**
	 * Check if text contains any of the given phrases.
	 *
	 * @param string $text    The input text.
	 * @param array  $phrases Phrases to check.
	 * @return bool True if any phrase is found.
	 */
	protected function containsAny( string $text, array $phrases ): bool {
		foreach ( $phrases as $phrase ) {
			if ( $this->containsPhrase( $text, $phrase ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if text contains all of the given phrases.
	 *
	 * @param string $text    The input text.
	 * @param array  $phrases Phrases to check.
	 * @return bool True if all phrases are found. Returns false for empty array.
	 */
	protected function containsAll( string $text, array $phrases ): bool {
		// Empty array should return false (nothing to match).
		if ( empty( $phrases ) ) {
			return false;
		}

		foreach ( $phrases as $phrase ) {
			if ( ! $this->containsPhrase( $text, $phrase ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check if text matches a regex pattern.
	 *
	 * @param string $text    The input text.
	 * @param string $pattern The regex pattern.
	 * @return bool True if pattern matches.
	 */
		protected function matchesPattern( string $text, string $pattern ): bool {
			$result = preg_match( $pattern, $text );

			// Handle regex error (e.g., malformed pattern).
			if ( false === $result ) {
				return false;
			}

			return (bool) $result;
		}
	}
