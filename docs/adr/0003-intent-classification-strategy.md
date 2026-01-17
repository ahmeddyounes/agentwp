# ADR 0003: Intent Classification Strategy

**Date:** 2026-01-17
**Status:** Accepted

## Context

AgentWP's intent classification system determines how user input is routed to domain handlers (order search, refund processing, analytics, etc.). Currently, two implementations exist:

1. **`IntentClassifier`** (Legacy): A monolithic rule-based classifier in `src/Intent/IntentClassifier.php` with hardcoded scoring methods for each intent type. Uses simple `strpos()` matching.

2. **`Intent\Classifier\ScorerRegistry`** (Modern): A pluggable registry pattern in `src/Intent/Classifier/ScorerRegistry.php` that manages individual scorer instances implementing `IntentScorerInterface`. Uses word-boundary regex matching via `AbstractScorer`.

The coexistence creates:
- Uncertainty about which classifier to use or extend
- Inconsistent matching behavior (substring vs. word-boundary)
- No runtime extensibility for third-party intents
- Unused configuration values (`AgentWPConfig` weights/thresholds are defined but not integrated)

Additionally, ADR 0002 established `#[HandlesIntent]` attributes as the standard for handler registration, but the classifier layer lacks equivalent extensibility.

## Decision

### Primary Classification Implementation

The **`ScorerRegistry`** is the canonical intent classification mechanism.

```php
// Container registration (IntentServiceProvider)
$this->container->singleton(
    IntentClassifierInterface::class,
    fn() => ScorerRegistry::withDefaults()
);
```

### Why ScorerRegistry

| Criteria | ScorerRegistry | IntentClassifier |
|----------|----------------|------------------|
| Extensible | Yes (register custom scorers) | No (hardcoded methods) |
| Matching precision | Word-boundary regex | Substring matching |
| DoS protection | Yes (MAX_INPUT_LENGTH) | No |
| Deterministic ties | Yes (alphabetical) | No (iteration order) |
| Testable in isolation | Yes (per-scorer tests) | No (monolithic) |
| Context-aware | Yes (context param) | Partial |

### Extension Points

#### WordPress Filter: `agentwp_intent_scorers`

Third-party code registers custom scorers via filter:

```php
add_filter('agentwp_intent_scorers', function(array $scorers): array {
    $scorers[] = new MyCustomScorer();
    return $scorers;
});
```

**Contract:**
- Input: Array of `IntentScorerInterface` instances
- Output: Modified array of scorers
- Called: During `ScorerRegistry` initialization in the service provider
- Timing: After default scorers are instantiated, before registry is finalized

#### Implementation in IntentServiceProvider

```php
private function registerIntentClassifier(): void {
    $this->container->singleton(
        IntentClassifierInterface::class,
        function() {
            $registry = new ScorerRegistry();

            // Register default scorers
            $default_scorers = [
                new Scorers\RefundScorer(),
                new Scorers\StatusScorer(),
                new Scorers\StockScorer(),
                new Scorers\EmailScorer(),
                new Scorers\AnalyticsScorer(),
                new Scorers\CustomerScorer(),
                new Scorers\SearchScorer(),
            ];

            // Apply filter for third-party scorers
            $scorers = apply_filters('agentwp_intent_scorers', $default_scorers);

            $registry->registerMany($scorers);

            return $registry;
        }
    );
}
```

#### WordPress Action: `agentwp_intent_classified`

Fired after classification for logging, analytics, or override:

```php
do_action('agentwp_intent_classified', $intent, $scores, $input, $context);
```

**Parameters:**
- `$intent` (string): The classified intent constant
- `$scores` (array): All intent scores from `scoreAll()`
- `$input` (string): Original user input
- `$context` (array): Classification context

### Weights and Thresholds from AgentWPConfig

The existing configuration values should be integrated as follows:

#### Intent Weights

Weights multiply raw scores to bias certain intents:

```php
// In ScorerRegistry::classify() or via weighted scorer decorator
$weighted_score = $raw_score * AgentWPConfig::get('intent.weight.order_search');
```

**Implementation approach:** Create a `WeightedScorerDecorator` that wraps any scorer:

```php
final class WeightedScorerDecorator implements IntentScorerInterface {
    public function __construct(
        private IntentScorerInterface $inner,
        private float $weight
    ) {}

    public function getIntent(): string {
        return $this->inner->getIntent();
    }

    public function score(string $text, array $context = []): int {
        return (int) round($this->inner->score($text, $context) * $this->weight);
    }
}
```

Weights are applied via filter:

```php
add_filter('agentwp_config_intent_weight_order_search', fn() => 1.2); // Boost search
```

#### Confidence Thresholds

Thresholds determine classification confidence levels for UI/UX decisions:

```php
// After classification
$max_score = max($scores);
$total = array_sum($scores) ?: 1;
$confidence = $max_score / $total;

if ($confidence >= AgentWPConfig::CONFIDENCE_THRESHOLD_HIGH) {
    // Auto-route without confirmation
} elseif ($confidence >= AgentWPConfig::CONFIDENCE_THRESHOLD_MEDIUM) {
    // Route with "Did you mean..." suggestion
} else {
    // Show intent picker or ask clarifying question
}
```

**Note:** Threshold integration is deferred to a follow-up task. The current implementation returns the highest-scoring intent without confidence reporting. Exposing confidence requires extending the classifier interface:

```php
interface IntentClassifierInterface {
    public function classify(string $input, array $context = []): string;

    // Future: Add confidence-aware method
    // public function classifyWithConfidence(string $input, array $context = []): ClassificationResult;
}
```

### Backward Compatibility

#### `IntentClassifier` (Legacy)

**Support period:** Maintained through version 2.x
**Deprecation:** Version 2.0 (next major release)
**Removal:** Version 3.0

The legacy classifier remains available for explicit instantiation but is no longer the default:

```php
// Deprecated: Direct instantiation
$classifier = new IntentClassifier(); // Works but logs deprecation notice

// Recommended: Resolve from container
$classifier = $container->get(IntentClassifierInterface::class);
```

#### Migration Path

1. **v1.x (Current):** Both implementations available; `ScorerRegistry` becomes container default
2. **v2.0:** `IntentClassifier` emits `E_USER_DEPRECATED` when instantiated
3. **v3.0:** `IntentClassifier` removed

## Testing Strategy

### Unit Tests for Individual Scorers

Each scorer has dedicated tests in `tests/Unit/Intent/Classifier/Scorers/`:

```php
class RefundScorerTest extends TestCase {
    private RefundScorer $scorer;

    protected function setUp(): void {
        $this->scorer = new RefundScorer();
    }

    public function test_returns_correct_intent(): void {
        $this->assertSame(Intent::ORDER_REFUND, $this->scorer->getIntent());
    }

    /** @dataProvider refundPhraseProvider */
    public function test_scores_refund_phrases(string $input, int $expected): void {
        $this->assertSame($expected, $this->scorer->score($input));
    }

    public static function refundPhraseProvider(): array {
        return [
            'exact match' => ['refund this order', 1],
            'multiple phrases' => ['refund and money back', 2],
            'no match' => ['check order status', 0],
            'partial word no match' => ['refunding', 0], // Word boundary
        ];
    }
}
```

### Integration Tests for ScorerRegistry

```php
class ScorerRegistryIntegrationTest extends TestCase {
    public function test_withDefaults_registers_all_scorers(): void {
        $registry = ScorerRegistry::withDefaults();

        $this->assertTrue($registry->has(Intent::ORDER_REFUND));
        $this->assertTrue($registry->has(Intent::ORDER_STATUS));
        $this->assertTrue($registry->has(Intent::PRODUCT_STOCK));
        // ... all seven intents
        $this->assertSame(7, $registry->count());
    }

    public function test_classify_returns_highest_scorer(): void {
        $registry = ScorerRegistry::withDefaults();

        $intent = $registry->classify('I need a refund for my order');

        $this->assertSame(Intent::ORDER_REFUND, $intent);
    }

    public function test_classify_uses_alphabetical_tiebreaker(): void {
        $registry = new ScorerRegistry();
        $registry->registerMany([
            new FixedScorer(Intent::ORDER_STATUS, 5),
            new FixedScorer(Intent::ORDER_REFUND, 5),
        ]);

        // REFUND < STATUS alphabetically
        $this->assertSame(Intent::ORDER_REFUND, $registry->classify('test'));
    }
}
```

### Extension Point Tests

```php
class ScorerFilterIntegrationTest extends WP_UnitTestCase {
    public function test_filter_adds_custom_scorer(): void {
        add_filter('agentwp_intent_scorers', function($scorers) {
            $scorers[] = new FixedScorer('CUSTOM_INTENT', 100);
            return $scorers;
        });

        $registry = $this->container->get(IntentClassifierInterface::class);

        $this->assertTrue($registry->has('CUSTOM_INTENT'));
    }
}
```

### Property-Based Testing (Optional)

For robustness, consider property-based tests:

```php
public function test_classify_never_throws_on_arbitrary_input(): void {
    $registry = ScorerRegistry::withDefaults();

    // QuickCheck-style: random strings, unicode, special chars
    for ($i = 0; $i < 100; $i++) {
        $input = $this->randomString(rand(0, 15000));
        $intent = $registry->classify($input);

        $this->assertIsString($intent);
    }
}
```

## Consequences

### Positive

- Single extensibility pattern aligned with ADR 0002's handler approach
- Third-party plugins can add intents without modifying core
- Improved matching precision prevents false positives
- DoS protection built into registry
- Each scorer testable in isolation
- Configuration values in `AgentWPConfig` gain purpose

### Negative

- Slight overhead from regex vs. strpos (negligible for typical input)
- Third-party code using `IntentClassifier` directly must migrate
- Weight/threshold integration requires follow-up work

### Neutral

- Maintains current behavior for default intents
- Requires PHP 8.0+ (already a project requirement)

## Migration Checklist

### For Core Developers

- [ ] Update `IntentServiceProvider` to register `ScorerRegistry` as `IntentClassifierInterface`
- [ ] Add `agentwp_intent_scorers` filter to service provider
- [ ] Add `agentwp_intent_classified` action to `ScorerRegistry::classify()`
- [ ] Add `@deprecated` annotation to `IntentClassifier` class
- [ ] Implement `WeightedScorerDecorator` for weight support
- [ ] Create unit tests for each scorer in `tests/Unit/Intent/Classifier/Scorers/`
- [ ] Create integration tests for filter-based extension
- [ ] Update DEVELOPER.md with scorer registration guide

### For Third-Party Developers

1. **Implement `IntentScorerInterface`:**
   ```php
   use AgentWP\Intent\Classifier\IntentScorerInterface;
   use AgentWP\Intent\Classifier\AbstractScorer;

   class MyCustomScorer extends AbstractScorer {
       public function getIntent(): string {
           return 'MY_CUSTOM_INTENT';
       }

       public function score(string $text, array $context = []): int {
           return $this->matchScore($text, ['my phrase', 'another phrase']);
       }
   }
   ```

2. **Register via filter:**
   ```php
   add_filter('agentwp_intent_scorers', function(array $scorers): array {
       $scorers[] = new MyCustomScorer();
       return $scorers;
   });
   ```

3. **Register a handler** (per ADR 0002):
   ```php
   #[HandlesIntent('MY_CUSTOM_INTENT')]
   class MyCustomHandler extends AbstractAgenticHandler {
       // ...
   }
   ```

## References

- ADR 0002: Intent Handler Registration
- `src/Intent/Classifier/ScorerRegistry.php` - Primary implementation
- `src/Intent/Classifier/IntentScorerInterface.php` - Scorer contract
- `src/Intent/Classifier/AbstractScorer.php` - Base scorer utilities
- `src/Config/AgentWPConfig.php` - Weight and threshold definitions
- `src/Providers/IntentServiceProvider.php` - Container registration
