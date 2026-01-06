<?php
/**
 * Rule-based intent classifier.
 *
 * @package AgentWP
 */

namespace AgentWP\Intent;

class IntentClassifier {
	/**
	 * @param string $input User input.
	 * @param array  $context Enriched context.
	 * @return string
	 */
	public function classify( $input, array $context = array() ) {
		if ( isset( $context['intent'] ) ) {
			$override = Intent::normalize( $context['intent'] );
			if ( Intent::UNKNOWN !== $override ) {
				return $override;
			}
		}

		$text = strtolower( trim( (string) $input ) );
		if ( '' === $text ) {
			return Intent::UNKNOWN;
		}

		$scores = array(
			Intent::ORDER_REFUND    => $this->score_refund( $text ),
			Intent::ORDER_STATUS    => $this->score_status( $text ),
			Intent::PRODUCT_STOCK   => $this->score_stock( $text ),
			Intent::EMAIL_DRAFT     => $this->score_email( $text ),
			Intent::ANALYTICS_QUERY => $this->score_analytics( $text ),
			Intent::CUSTOMER_LOOKUP => $this->score_customer( $text ),
			Intent::ORDER_SEARCH    => $this->score_search( $text ),
		);

		$best_intent = Intent::UNKNOWN;
		$best_score  = 0;

		foreach ( $scores as $intent => $score ) {
			if ( $score > $best_score ) {
				$best_score  = $score;
				$best_intent = $intent;
			}
		}

		return $best_score > 0 ? $best_intent : Intent::UNKNOWN;
	}

	/**
	 * @param string $text Normalized text.
	 * @return int
	 */
	private function score_refund( $text ) {
		return $this->match_score(
			$text,
			array(
				'refund',
				'return',
				'chargeback',
				'money back',
				'reimburse',
			)
		);
	}

	/**
	 * @param string $text Normalized text.
	 * @return int
	 */
	private function score_status( $text ) {
		return $this->match_score(
			$text,
			array(
				'status',
				'tracking',
				'track',
				'shipment',
				'shipping',
				'delivery',
				'where is',
				'eta',
			)
		);
	}

	/**
	 * @param string $text Normalized text.
	 * @return int
	 */
	private function score_stock( $text ) {
		return $this->match_score(
			$text,
			array(
				'stock',
				'inventory',
				'available',
				'availability',
				'out of stock',
				'restock',
			)
		);
	}

	/**
	 * @param string $text Normalized text.
	 * @return int
	 */
	private function score_email( $text ) {
		$has_email  = $this->contains_phrase( $text, 'email' ) || $this->contains_phrase( $text, 'message' );
		$has_action = $this->contains_phrase( $text, 'compose' )
			|| $this->contains_phrase( $text, 'draft' )
			|| $this->contains_phrase( $text, 'write' )
			|| $this->contains_phrase( $text, 'reply' )
			|| $this->contains_phrase( $text, 'send' );

		if ( $has_email && $has_action ) {
			return 2;
		}

		if ( $has_action ) {
			return 1;
		}

		return 0;
	}

	/**
	 * @param string $text Normalized text.
	 * @return int
	 */
	private function score_analytics( $text ) {
		return $this->match_score(
			$text,
			array(
				'analytics',
				'report',
				'sales',
				'revenue',
				'performance',
				'conversion',
				'aov',
				'average order',
			)
		);
	}

	/**
	 * @param string $text Normalized text.
	 * @return int
	 */
	private function score_customer( $text ) {
		return $this->match_score(
			$text,
			array(
				'customer',
				'profile',
				'buyer',
				'account',
				'loyalty',
			)
		);
	}

	/**
	 * @param string $text Normalized text.
	 * @return int
	 */
	private function score_search( $text ) {
		return $this->match_score(
			$text,
			array(
				'order',
				'search',
				'find',
				'lookup',
				'last order',
				'order id',
				'order #',
			)
		);
	}

	/**
	 * @param string $text Normalized text.
	 * @param array  $phrases Phrases to match.
	 * @return int
	 */
	private function match_score( $text, array $phrases ) {
		$score = 0;
		foreach ( $phrases as $phrase ) {
			if ( $this->contains_phrase( $text, $phrase ) ) {
				$score++;
			}
		}

		return $score;
	}

	/**
	 * @param string $text Normalized text.
	 * @param string $phrase Phrase to match.
	 * @return bool
	 */
	private function contains_phrase( $text, $phrase ) {
		$phrase = trim( $phrase );
		if ( '' === $phrase ) {
			return false;
		}

		return false !== strpos( $text, $phrase );
	}
}
