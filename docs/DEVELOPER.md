# AgentWP Developer Guide

This guide covers architecture, extension points, and local development workflow.

## Architecture overview
AgentWP is a WordPress plugin with a React admin UI and a PHP backend.

Key pieces:
- **REST API**: Controllers in `src/Rest` and `src/API` expose `/wp-json/agentwp/v1/*`.
- **Intent engine**: `AgentWP\Intent\Engine` classifies prompts and routes them to handlers.
- **Handlers**: Classes in `src/Intent/Handlers` implement intent-specific responses.
- **WooCommerce integrations**: Services in `src/Services` execute refunds, status updates, stock changes, and email drafts.
- **React Command Deck**: Source in `react/`, built assets in `assets/`.

### Single-path bootstrap architecture
The plugin uses a consolidated bootstrap pattern. `src/Plugin.php` is the entry point but delegates all wiring to managers and service providers:

- **Managers** (`src/Plugin/*`): `AdminManager`, `AssetsManager`, `MenuManager`, `RestManager` handle WordPress integration.
- **Service Providers** (`src/Providers/*`): Register services, controllers, and handlers with the dependency injection container.
- **Configuration**: All option keys and defaults are defined in `AgentWPConfig` constants. Never hardcode option names elsewhere.

The flow:
1. `agentwp.php` boots `Plugin::instance()`
2. `Plugin` registers providers via the container
3. Providers wire managers, controllers, and services
4. Managers hook into WordPress lifecycle

### API key management
API keys are managed by `ApiKeyStorage` (`src/Security/ApiKeyStorage.php`):
- Encrypts/decrypts keys using `Encryption` service
- Stores encrypted keys and last-4 indicators as separate options
- Supports key rotation via filters

Never directly instantiate `Encryption` or manually manage key storage in controllers.

### Demo mode behavior
When demo mode is enabled (`AgentWPConfig::OPTION_DEMO_MODE`):
- **With demo API key**: Real API calls are made using the demo key
- **Without demo API key**: `DemoClient` returns stubbed responses for testing

This ensures demo mode never accidentally uses a production key.

Request flow:
1. REST call hits `IntentController::create_intent`.
2. `Engine` classifies the prompt and enriches context.
3. A handler returns a `Response` object.
4. REST response is normalized into the standard envelope.

### Service layer conventions

Application services in `src/Services/` follow strict conventions for testability and consistency:

#### ServiceResult pattern

All services return `ServiceResult` (`src/DTO/ServiceResult.php`) instead of throwing exceptions or returning mixed types:

```php
use AgentWP\DTO\ServiceResult;

class MyService implements MyServiceInterface {
    public function doSomething( int $id ): ServiceResult {
        if ( ! $this->policy->canDoSomething() ) {
            return ServiceResult::permissionDenied();
        }

        $entity = $this->gateway->find( $id );
        if ( ! $entity ) {
            return ServiceResult::notFound( 'Entity', $id );
        }

        // Perform operation...

        return ServiceResult::success( 'Operation completed.', array( 'entity_id' => $id ) );
    }
}
```

**Available factory methods:**
| Method | Use case | HTTP Status |
|--------|----------|-------------|
| `success($message, $data)` | Operation succeeded | 200 |
| `permissionDenied($message)` | User lacks permission | 403 |
| `notFound($resource, $id)` | Resource doesn't exist | 404 |
| `invalidInput($message, $errors)` | Validation failed | 400 |
| `invalidState($message)` | Operation not allowed in current state | 409 |
| `draftExpired($message)` | Draft no longer valid | 410 |
| `operationFailed($message, $context)` | Infrastructure/gateway error | 500 |

#### Policy layer

Services must not call `current_user_can()` directly. Instead, inject `PolicyInterface`:

```php
use AgentWP\Contracts\PolicyInterface;

class MyService {
    public function __construct(
        private PolicyInterface $policy,
        // ... other dependencies
    ) {}

    public function doSomething(): ServiceResult {
        if ( ! $this->policy->canManageOrders() ) {
            return ServiceResult::permissionDenied();
        }
        // ...
    }
}
```

#### Gateway abstractions

Services must not call WooCommerce or WordPress functions directly. Use gateway interfaces:

```php
// DO NOT do this:
$order = wc_get_order( $order_id );  // Direct WooCommerce call

// DO this:
$order = $this->orderGateway->get_order( $order_id );  // Via gateway interface
```

**Available gateways:**
| Interface | Purpose |
|-----------|---------|
| `WooCommerceRefundGatewayInterface` | Refund creation, order retrieval for refunds |
| `WooCommerceOrderGatewayInterface` | Order status updates |
| `WooCommerceStockGatewayInterface` | Product stock operations |
| `WooCommerceUserGatewayInterface` | Customer data access |

#### Draft-based operations

Services performing destructive actions must use `DraftManagerInterface` for the draft-confirm pattern:

```php
use AgentWP\Contracts\DraftManagerInterface;

class MyService {
    private const DRAFT_TYPE = 'my_operation';

    public function __construct(
        private DraftManagerInterface $draftManager,
        // ...
    ) {}

    public function prepare( int $id ): ServiceResult {
        $payload = array( 'entity_id' => $id );
        $preview = array( 'summary' => "Perform operation on #{$id}" );

        return $this->draftManager->create( self::DRAFT_TYPE, $payload, $preview );
    }

    public function confirm( string $draft_id ): ServiceResult {
        $claimResult = $this->draftManager->claim( self::DRAFT_TYPE, $draft_id );
        if ( $claimResult->isFailure() ) {
            return ServiceResult::draftExpired();
        }

        $payload = $claimResult->get( 'payload' );
        // Execute the actual operation...

        return ServiceResult::success( 'Operation completed.' );
    }
}
```

#### Testing services

Services are unit-testable with fakes/mocks because they depend on interfaces, not WordPress globals:

```php
class MyServiceTest extends TestCase {
    public function test_permission_denied_when_user_lacks_capability(): void {
        $policy = $this->createMock( PolicyInterface::class );
        $policy->method( 'canManageOrders' )->willReturn( false );

        $service = new MyService( $policy, /* ... */ );
        $result = $service->doSomething( 123 );

        $this->assertTrue( $result->isFailure() );
        $this->assertSame( 'permission_denied', $result->code );
    }
}
```

### Order search pipeline
Order search uses a modular pipeline architecture (`src/Services/OrderSearch/*`):

- `PipelineOrderSearchService` - Entry point implementing `OrderSearchServiceInterface`
- `ArgumentNormalizer` - Normalizes and validates search arguments
- `OrderSearchParser` - Parses natural language queries into structured parameters
- `DateRangeParser` - Extracts date ranges from user input
- `OrderQueryService` - Executes queries with caching
- `OrderFormatter` - Formats order results for display

The pipeline is wired via `ServicesServiceProvider`. When WooCommerce is not available, a stub implementation returns an error response.

## Local development
- UI source lives in `react/` and is bundled with Vite.
- WordPress integration tests use `@wordpress/env` and Playwright from the repository root.

Common commands:
```bash
cd react
npm install
npm run dev
```

```bash
cd react
npm run build
```

```bash
npm install
npm run wp-env:start
```

## PHP tooling

```bash
composer run phpunit
composer run phpcs
composer run phpstan
```

Note: PHPStan needs more memory than the default PHP CLI limit; `composer run phpstan` sets `memory_limit=1G`.

## Hook and filter reference
AgentWP exposes a few filters to help you customize behavior.

### `agentwp_refund_notify_customer`
Control whether refund emails are sent.

Signature:
```
bool $notify = apply_filters(
  'agentwp_refund_notify_customer',
  bool $notify,
  WC_Order_Refund $refund,
  WC_Order $order,
  array $payload
);
```

### `agentwp_status_notify_customer`
Control whether order status emails are sent.

Signature:
```
bool $notify = apply_filters(
  'agentwp_status_notify_customer',
  bool $notify,
  WC_Order $order,
  string $new_status
);
```

### `agentwp_customer_health_thresholds`
Override thresholds used in customer health scoring.

Signature:
```
array $thresholds = apply_filters(
  'agentwp_customer_health_thresholds',
  array $thresholds
);
```

### `agentwp_encryption_rotation_materials`
Provide additional key material for API key rotation.

Signature:
```
array $rotations = apply_filters(
  'agentwp_encryption_rotation_materials',
  array $rotations
);
```

### `agentwp_intent_handlers`
Extend the intent handler list (see extension guide below).

Signature:
```
array $handlers = apply_filters(
  'agentwp_intent_handlers',
  array $handlers,
  AgentWP\Intent\Engine $engine
);
```

### `agentwp_default_function_mapping`
Override the default intent-to-function mapping.

Signature:
```
array $mapping = apply_filters(
  'agentwp_default_function_mapping',
  array $mapping,
  AgentWP\Intent\Engine $engine
);
```

### `agentwp_register_intent_functions`
Register additional AI functions after defaults are registered.

Signature:
```
do_action(
  'agentwp_register_intent_functions',
  AgentWP\Intent\FunctionRegistry $registry,
  AgentWP\Intent\Engine $engine
);
```

### `agentwp_intent_scorers`
Register custom intent scorers for classification. See [Extension guide: custom intent scorer](#extension-guide-custom-intent-scorer) for full details.

Signature:
```
array $scorers = apply_filters(
  'agentwp_intent_scorers',
  array $scorers
);
```

Example:
```php
add_filter( 'agentwp_intent_scorers', function( array $scorers ): array {
	$scorers[] = new MyCustomScorer();
	return $scorers;
} );
```

### `agentwp_intent_classified`
Fired after intent classification completes. Useful for analytics, logging, or debugging.

Signature:
```
do_action(
  'agentwp_intent_classified',
  string $intent,           // The classified intent constant
  array $scores,            // All intent scores from scoreAll()
  string $input,            // Original user input
  array $context            // Classification context
);
```

## Extension guide: custom intent handler

AgentWP uses an attribute-based handler registration pattern with dependency injection via service providers. Handlers declare which intents they handle using the `#[HandlesIntent]` PHP attribute, and the service provider wires them into the container with the `intent.handler` tag.

### Architecture overview

The handler registration flow:
1. Handler class uses `#[HandlesIntent(Intent::MY_INTENT)]` attribute
2. Service provider registers handler as singleton and tags it with `intent.handler`
3. Engine receives all tagged handlers via `$container->tagged('intent.handler')`
4. At runtime, handlers are matched based on their declared intents

### 1) Create a handler class

Extend `AbstractAgenticHandler` for AI-powered handlers that use the agentic loop pattern:

```php
namespace MyPlugin\AgentWP;

use AgentWP\Contracts\AIClientFactoryInterface;
use AgentWP\Contracts\ToolRegistryInterface;
use AgentWP\Intent\Attributes\HandlesIntent;
use AgentWP\Intent\Handlers\AbstractAgenticHandler;

#[HandlesIntent( 'shipment_delay' )]
class ShipmentDelayHandler extends AbstractAgenticHandler {

	private ShipmentServiceInterface $service;

	public function __construct(
		ShipmentServiceInterface $service,
		AIClientFactoryInterface $clientFactory,
		ToolRegistryInterface $toolRegistry
	) {
		parent::__construct( 'shipment_delay', $clientFactory, $toolRegistry );
		$this->service = $service;
	}

	protected function getSystemPrompt(): string {
		return 'You are a shipment delay assistant. Help draft delay notification emails.';
	}

	protected function getToolNames(): array {
		return array( 'draft_delay_email' );
	}

	protected function getDefaultInput(): string {
		return 'Draft a shipment delay notification';
	}

	public function execute_tool( string $name, array $arguments ) {
		if ( 'draft_delay_email' === $name ) {
			return $this->service->draftDelayEmail( $arguments );
		}
		return array( 'error' => "Unknown tool: {$name}" );
	}
}
```

For simpler handlers without AI interaction, extend `BaseHandler` directly:

```php
namespace MyPlugin\AgentWP;

use AgentWP\AI\Response;
use AgentWP\Intent\Attributes\HandlesIntent;
use AgentWP\Intent\Handlers\BaseHandler;

#[HandlesIntent( 'simple_lookup' )]
class SimpleLookupHandler extends BaseHandler {

	public function __construct() {
		parent::__construct( 'simple_lookup' );
	}

	public function handle( array $context ): Response {
		$result = $this->performLookup( $context['input'] ?? '' );
		return $this->build_response( $context, $result );
	}

	private function performLookup( string $query ): string {
		// Lookup logic here
		return "Result for: {$query}";
	}
}
```

### 2) Register via service provider

Create a service provider to wire your handler with its dependencies:

```php
namespace MyPlugin\AgentWP;

use AgentWP\Container\ServiceProvider;
use AgentWP\Contracts\AIClientFactoryInterface;
use AgentWP\Contracts\ToolRegistryInterface;

class ShipmentServiceProvider extends ServiceProvider {

	public function register(): void {
		// Register handler as singleton with dependencies
		$this->container->singleton(
			ShipmentDelayHandler::class,
			fn( $c ) => new ShipmentDelayHandler(
				$c->get( ShipmentServiceInterface::class ),
				$c->get( AIClientFactoryInterface::class ),
				$c->get( ToolRegistryInterface::class )
			)
		);

		// Tag handler so Engine discovers it
		$this->container->tag( ShipmentDelayHandler::class, 'intent.handler' );
	}
}
```

Register your provider using the `agentwp_register_providers` action:

```php
add_action( 'agentwp_register_providers', function( $container ) {
	$provider = new \MyPlugin\AgentWP\ShipmentServiceProvider( $container );
	$provider->register();
} );
```

This action fires during AgentWP's initialization, before providers are booted.

### 3) Add a custom scorer (optional)

To enable intent classification for your custom intent, add a scorer via the `agentwp_intent_scorers` filter:

```php
namespace MyPlugin\AgentWP;

use AgentWP\Intent\Classifier\AbstractScorer;

class ShipmentDelayScorer extends AbstractScorer {

	private const PHRASES = array(
		'shipment delay',
		'shipping delayed',
		'late delivery',
		'package delayed',
	);

	public function getIntent(): string {
		return 'shipment_delay';
	}

	public function score( string $text, array $context = array() ): int {
		return $this->matchScore( $text, self::PHRASES );
	}
}

// Register the scorer
add_filter( 'agentwp_intent_scorers', function( array $scorers ): array {
	$scorers[] = new \MyPlugin\AgentWP\ShipmentDelayScorer();
	return $scorers;
} );
```

### 4) Test the prompt
Call the `/intent` endpoint or use the Command Deck to test:
```
Draft a shipment delay email for order 1234
```

## Extension guide: custom intent scorer

You can extend AgentWP's intent classification by adding custom scorers via the `agentwp_intent_scorers` filter. Scorers evaluate user input and return a confidence score for matching intents.

### Scorer interface

All scorers implement `IntentScorerInterface`:

```php
interface IntentScorerInterface {
	public function getIntent(): string;           // Intent constant
	public function score( string $text, array $context = array() ): int;  // 0 = no match
	public function getWeight(): float;            // Weight multiplier (default 1.0)
}
```

### Creating a custom scorer

Extend `AbstractScorer` to inherit utility methods and config-aware weighting:

```php
namespace MyPlugin\AgentWP;

use AgentWP\Intent\Classifier\AbstractScorer;

class InventoryScorer extends AbstractScorer {

	/**
	 * Phrases indicating inventory-related intent.
	 */
	private const PHRASES = array(
		'inventory',
		'stock level',
		'warehouse',
		'reorder',
		'low stock',
	);

	/**
	 * Strong indicators that boost the score.
	 */
	private const STRONG_PHRASES = array(
		'check inventory',
		'inventory report',
		'stock count',
	);

	public function getIntent(): string {
		return 'inventory_check';
	}

	public function score( string $text, array $context = array() ): int {
		$score = $this->matchScore( $text, self::PHRASES );

		// Boost score for strong indicators
		if ( $this->containsAny( $text, self::STRONG_PHRASES ) ) {
			$score += 2;
		}

		return $score;
	}
}
```

### Registering the scorer

Use the `agentwp_intent_scorers` filter to add your scorer:

```php
add_filter( 'agentwp_intent_scorers', function( array $scorers ): array {
	$scorers[] = new \MyPlugin\AgentWP\InventoryScorer();
	return $scorers;
} );
```

### Available utility methods

`AbstractScorer` provides these helper methods:

| Method | Description |
|--------|-------------|
| `matchScore($text, $phrases)` | Count matching phrases |
| `containsPhrase($text, $phrase)` | Check single phrase with word boundaries |
| `containsAny($text, $phrases)` | Check if any phrase matches |
| `containsAll($text, $phrases)` | Check if all phrases match |
| `matchesPattern($text, $pattern)` | Match against regex pattern |

### Scorer weights

Scorer weights are applied by the `ScorerRegistry` when calculating final scores. You can customize weights via config filters:

```php
add_filter( 'agentwp_config_intent_weight_order_search', fn() => 1.5 );
```

For custom intents, override `getWeight()` in your scorer or add your intent to `AbstractScorer::INTENT_WEIGHT_KEYS`.

## OpenAPI spec

The OpenAPI spec lives in `docs/openapi.json`. It documents the REST API and is kept in sync with controller annotations.

### Annotation format

Each REST endpoint method should have an `@openapi` annotation in its PHPDoc:

```php
/**
 * Handle intent requests.
 *
 * @openapi POST /agentwp/v1/intent
 *
 * @param WP_REST_Request<array<string, mixed>> $request Request instance.
 * @return \WP_REST_Response
 */
public function create_intent( $request ) { ... }
```

The annotation format is: `@openapi METHOD /path`

### Maintenance workflow

When adding or modifying REST endpoints:

1. **Add/update the `@openapi` annotation** in the controller method
2. **Update `docs/openapi.json`** with the endpoint definition, request/response schemas
3. **Run validation** to ensure sync:
   ```bash
   composer run openapi:validate
   ```

### Validation

The validation script compares `@openapi` annotations against `docs/openapi.json`:

```bash
composer run openapi:validate
```

This will:
- Check that `openapi.json` is valid JSON with required OpenAPI 3.x structure
- List endpoints annotated in code but missing from the spec
- List spec entries without corresponding code annotations
- Report synchronized endpoints

The validation runs automatically in CI and will fail the build if drift is detected.

### Adding a new endpoint

1. Create the controller method with `@openapi` annotation
2. Add the path and method to `docs/openapi.json` under `paths`
3. Define request/response schemas in `components/schemas` if needed
4. Run `composer run openapi:validate` to verify sync
