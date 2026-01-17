<?php
/**
 * ScorerRegistry unit tests.
 */

namespace AgentWP\Tests\Unit\Intent\Classifier;

use AgentWP\Contracts\IntentClassifierInterface;
use AgentWP\Intent\Classifier\IntentScorerInterface;
use AgentWP\Intent\Classifier\ScorerRegistry;
use AgentWP\Intent\Classifier\Scorers\RefundScorer;
use AgentWP\Intent\Classifier\Scorers\SearchScorer;
use AgentWP\Intent\Classifier\Scorers\StatusScorer;
use AgentWP\Intent\Intent;
use AgentWP\Tests\Fakes\FakeWPFunctions;
use AgentWP\Tests\TestCase;

class ScorerRegistryTest extends TestCase {

	public function test_implements_intent_classifier_interface(): void {
		$registry = new ScorerRegistry();

		$this->assertInstanceOf( IntentClassifierInterface::class, $registry );
	}

	public function test_register_adds_scorer(): void {
		$registry = new ScorerRegistry();
		$scorer   = new RefundScorer();

		$registry->register( $scorer );

		$this->assertTrue( $registry->has( Intent::ORDER_REFUND ) );
		$this->assertSame( 1, $registry->count() );
	}

	public function test_register_many_adds_multiple_scorers(): void {
		$registry = new ScorerRegistry();

		$registry->registerMany(
			array(
				new RefundScorer(),
				new StatusScorer(),
				new SearchScorer(),
			)
		);

		$this->assertTrue( $registry->has( Intent::ORDER_REFUND ) );
		$this->assertTrue( $registry->has( Intent::ORDER_STATUS ) );
		$this->assertTrue( $registry->has( Intent::ORDER_SEARCH ) );
		$this->assertSame( 3, $registry->count() );
	}

	public function test_classify_returns_highest_scoring_intent(): void {
		$registry = new ScorerRegistry();

		$registry->registerMany(
			array(
				new RefundScorer(),
				new StatusScorer(),
			)
		);

		$intent = $registry->classify( 'I need a refund for my order' );

		$this->assertSame( Intent::ORDER_REFUND, $intent );
	}

	public function test_classify_returns_unknown_for_empty_input(): void {
		$registry = new ScorerRegistry();
		$registry->register( new RefundScorer() );

		$this->assertSame( Intent::UNKNOWN, $registry->classify( '' ) );
		$this->assertSame( Intent::UNKNOWN, $registry->classify( '   ' ) );
	}

	public function test_classify_returns_unknown_when_no_scorers_match(): void {
		$registry = new ScorerRegistry();
		$registry->register( new RefundScorer() );

		$intent = $registry->classify( 'hello world' );

		$this->assertSame( Intent::UNKNOWN, $intent );
	}

	public function test_classify_uses_alphabetical_tiebreaker(): void {
		$registry = new ScorerRegistry();

		// Create two fixed scorers with the same score.
		$scorerA = $this->createFixedScorer( 'ALPHA_INTENT', 5 );
		$scorerB = $this->createFixedScorer( 'BETA_INTENT', 5 );

		$registry->registerMany( array( $scorerB, $scorerA ) );

		// ALPHA_INTENT < BETA_INTENT alphabetically.
		$this->assertSame( 'ALPHA_INTENT', $registry->classify( 'test' ) );
	}

	public function test_classify_respects_context_intent_override(): void {
		$registry = new ScorerRegistry();
		$registry->register( new RefundScorer() );

		$intent = $registry->classify(
			'I need a refund',
			array( 'intent' => 'ORDER_STATUS' )
		);

		$this->assertSame( Intent::ORDER_STATUS, $intent );
	}

	public function test_classify_ignores_invalid_context_intent_override(): void {
		$registry = new ScorerRegistry();
		$registry->register( new RefundScorer() );

		// Invalid intent in context should be ignored.
		$intent = $registry->classify(
			'I need a refund',
			array( 'intent' => 'INVALID_INTENT' )
		);

		$this->assertSame( Intent::ORDER_REFUND, $intent );
	}

	public function test_classify_truncates_long_input(): void {
		$registry = new ScorerRegistry();
		$registry->register( new RefundScorer() );

		// Create input longer than MAX_INPUT_LENGTH (10000).
		$long_input = str_repeat( 'a', 5000 ) . ' refund ' . str_repeat( 'b', 6000 );

		// 'refund' is at position 5001, after truncation at 10000 it should be within bounds.
		$intent = $registry->classify( $long_input );

		$this->assertSame( Intent::ORDER_REFUND, $intent );
	}

	public function test_classify_handles_input_at_boundary(): void {
		$registry = new ScorerRegistry();
		$registry->register( new RefundScorer() );

		// 'refund' placed right before the truncation boundary (10000 chars).
		// 9993 chars + 'refund' (6 chars) = 9999 chars, within limit.
		$input = str_repeat( 'a', 9993 ) . ' refund';

		$intent = $registry->classify( $input );

		$this->assertSame( Intent::ORDER_REFUND, $intent );
	}

	public function test_score_all_returns_weighted_scores(): void {
		$registry = new ScorerRegistry();

		$registry->registerMany(
			array(
				$this->createFixedScorer( Intent::ORDER_REFUND, 3, 1.0 ),
				$this->createFixedScorer( Intent::ORDER_STATUS, 1, 1.0 ),
				$this->createFixedScorer( Intent::ORDER_SEARCH, 0, 1.0 ),
			)
		);

		$scores = $registry->scoreAll( 'test input' );

		// Scores are now floats (raw * weight).
		$this->assertEquals( 3.0, $scores[ Intent::ORDER_REFUND ] );
		$this->assertEquals( 1.0, $scores[ Intent::ORDER_STATUS ] );
		$this->assertEquals( 0.0, $scores[ Intent::ORDER_SEARCH ] );
	}

	public function test_score_all_applies_weights(): void {
		$registry = new ScorerRegistry();

		// Same raw score but different weights.
		$registry->registerMany(
			array(
				$this->createFixedScorer( Intent::ORDER_REFUND, 2, 1.5 ),  // 2 * 1.5 = 3.0
				$this->createFixedScorer( Intent::ORDER_STATUS, 2, 0.5 ), // 2 * 0.5 = 1.0
			)
		);

		$scores = $registry->scoreAll( 'test input' );

		$this->assertEquals( 3.0, $scores[ Intent::ORDER_REFUND ] );
		$this->assertEquals( 1.0, $scores[ Intent::ORDER_STATUS ] );
	}

	public function test_score_all_raw_returns_unweighted_scores(): void {
		$registry = new ScorerRegistry();

		$registry->registerMany(
			array(
				$this->createFixedScorer( Intent::ORDER_REFUND, 3, 2.0 ),
				$this->createFixedScorer( Intent::ORDER_STATUS, 1, 0.5 ),
			)
		);

		$rawScores = $registry->scoreAllRaw( 'test input' );

		// Raw scores ignore weights.
		$this->assertSame( 3, $rawScores[ Intent::ORDER_REFUND ] );
		$this->assertSame( 1, $rawScores[ Intent::ORDER_STATUS ] );
	}

	public function test_classify_uses_weighted_scores(): void {
		$registry = new ScorerRegistry();

		// Lower raw score but higher weight wins.
		$registry->registerMany(
			array(
				$this->createFixedScorer( Intent::ORDER_REFUND, 2, 0.5 ), // 2 * 0.5 = 1.0
				$this->createFixedScorer( Intent::ORDER_STATUS, 1, 3.0 ), // 1 * 3.0 = 3.0
			)
		);

		$intent = $registry->classify( 'test input' );

		// ORDER_STATUS wins due to higher weighted score.
		$this->assertSame( Intent::ORDER_STATUS, $intent );
	}

	public function test_has_returns_false_for_unregistered_intent(): void {
		$registry = new ScorerRegistry();

		$this->assertFalse( $registry->has( Intent::ORDER_REFUND ) );
	}

	public function test_all_returns_registered_scorers(): void {
		$registry = new ScorerRegistry();
		$scorer   = new RefundScorer();

		$registry->register( $scorer );

		$all = $registry->all();

		$this->assertCount( 1, $all );
		$this->assertSame( $scorer, $all[ Intent::ORDER_REFUND ] );
	}

	public function test_with_defaults_registers_all_seven_scorers(): void {
		$registry = ScorerRegistry::withDefaults();

		$this->assertTrue( $registry->has( Intent::ORDER_REFUND ) );
		$this->assertTrue( $registry->has( Intent::ORDER_STATUS ) );
		$this->assertTrue( $registry->has( Intent::ORDER_SEARCH ) );
		$this->assertTrue( $registry->has( Intent::PRODUCT_STOCK ) );
		$this->assertTrue( $registry->has( Intent::EMAIL_DRAFT ) );
		$this->assertTrue( $registry->has( Intent::ANALYTICS_QUERY ) );
		$this->assertTrue( $registry->has( Intent::CUSTOMER_LOOKUP ) );
		$this->assertSame( 7, $registry->count() );
	}

	public function test_classify_fires_action_hook(): void {
		$wp = new FakeWPFunctions();

		$registry = new ScorerRegistry( $wp );
		$registry->register( new RefundScorer() );

		$registry->classify( 'I need a refund' );

		$this->assertTrue( $wp->wasActionFired( 'agentwp_intent_classified' ) );

		$lastAction = $wp->getLastAction();
		$this->assertSame( 'agentwp_intent_classified', $lastAction['hook'] );
		$this->assertSame( Intent::ORDER_REFUND, $lastAction['args'][0] );
		$this->assertIsArray( $lastAction['args'][1] ); // scores.
		$this->assertSame( 'I need a refund', $lastAction['args'][2] );
		$this->assertSame( array(), $lastAction['args'][3] );
	}

	public function test_classify_fires_action_hook_on_context_override(): void {
		$wp = new FakeWPFunctions();

		$registry = new ScorerRegistry( $wp );
		$registry->register( new RefundScorer() );

		$registry->classify( 'some input', array( 'intent' => 'ORDER_STATUS' ) );

		$this->assertTrue( $wp->wasActionFired( 'agentwp_intent_classified' ) );

		$lastAction = $wp->getLastAction();
		$this->assertSame( 'agentwp_intent_classified', $lastAction['hook'] );
		$this->assertSame( Intent::ORDER_STATUS, $lastAction['args'][0] );
		$this->assertSame( array(), $lastAction['args'][1] ); // Empty scores on override.
		$this->assertSame( 'some input', $lastAction['args'][2] );
		$this->assertSame( array( 'intent' => 'ORDER_STATUS' ), $lastAction['args'][3] );
	}

	public function test_classify_without_wp_does_not_fire_action(): void {
		// This should not throw an error.
		$registry = new ScorerRegistry();
		$registry->register( new RefundScorer() );

		$intent = $registry->classify( 'I need a refund' );

		$this->assertSame( Intent::ORDER_REFUND, $intent );
	}

	public function test_classify_is_case_insensitive(): void {
		$registry = new ScorerRegistry();
		$registry->register( new RefundScorer() );

		$this->assertSame( Intent::ORDER_REFUND, $registry->classify( 'REFUND THIS ORDER' ) );
		$this->assertSame( Intent::ORDER_REFUND, $registry->classify( 'Refund This Order' ) );
		$this->assertSame( Intent::ORDER_REFUND, $registry->classify( 'refund this order' ) );
	}

	public function test_register_replaces_existing_scorer_for_same_intent(): void {
		$registry = new ScorerRegistry();

		$scorerA = $this->createFixedScorer( Intent::ORDER_REFUND, 1, 1.0 );
		$scorerB = $this->createFixedScorer( Intent::ORDER_REFUND, 10, 1.0 );

		$registry->register( $scorerA );
		$registry->register( $scorerB );

		$this->assertSame( 1, $registry->count() );

		$scores = $registry->scoreAll( 'test' );
		$this->assertEquals( 10.0, $scores[ Intent::ORDER_REFUND ] );
	}

	public function test_deterministic_tiebreaker_with_ksort(): void {
		$registry = new ScorerRegistry();

		// Register in reverse alphabetical order to verify ksort works.
		$registry->registerMany(
			array(
				$this->createFixedScorer( 'ZEBRA_INTENT', 5, 1.0 ),
				$this->createFixedScorer( 'ALPHA_INTENT', 5, 1.0 ),
				$this->createFixedScorer( 'MIDDLE_INTENT', 5, 1.0 ),
			)
		);

		// ALPHA_INTENT should win due to alphabetical ordering.
		$this->assertSame( 'ALPHA_INTENT', $registry->classify( 'test' ) );
	}

	public function test_zero_weight_prevents_classification(): void {
		$registry = new ScorerRegistry();

		// High raw score but zero weight = no match.
		$registry->registerMany(
			array(
				$this->createFixedScorer( Intent::ORDER_REFUND, 10, 0.0 ), // 10 * 0 = 0
				$this->createFixedScorer( Intent::ORDER_STATUS, 1, 1.0 ),  // 1 * 1 = 1
			)
		);

		$intent = $registry->classify( 'test input' );

		// ORDER_STATUS wins because REFUND has zero weighted score.
		$this->assertSame( Intent::ORDER_STATUS, $intent );
	}

	/**
	 * Create a fixed scorer that always returns the same score with a configurable weight.
	 *
	 * @param string $intent The intent type.
	 * @param int    $score  The fixed score to return.
	 * @param float  $weight The weight multiplier (default 1.0).
	 * @return IntentScorerInterface
	 */
	private function createFixedScorer( string $intent, int $score, float $weight = 1.0 ): IntentScorerInterface {
		return new class( $intent, $score, $weight ) implements IntentScorerInterface {
			private string $intent;
			private int $score;
			private float $weight;

			public function __construct( string $intent, int $score, float $weight ) {
				$this->intent = $intent;
				$this->score  = $score;
				$this->weight = $weight;
			}

			public function getIntent(): string {
				return $this->intent;
			}

			public function score( string $text, array $context = array() ): int {
				return $this->score;
			}

			public function getWeight(): float {
				return $this->weight;
			}
		};
	}
}
