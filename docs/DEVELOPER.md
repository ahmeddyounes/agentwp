# AgentWP Developer Guide

This guide covers architecture, extension points, and local development workflow.

## Architecture overview
AgentWP is a WordPress plugin with a React admin UI and a PHP backend.

Key pieces:
- **REST API**: Controllers in `src/Rest/` (namespace `AgentWP\Rest`) expose `/wp-json/agentwp/v1/*`. This is the canonical location for all REST controllers.
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

### Documentation link checking

To verify that all relative links in documentation files are valid:

```bash
composer run docs:check-links
```

This scans all `docs/**/*.md` files for relative markdown links and fails if any targets are missing or renamed. Run this after renaming or deleting documentation files to catch broken links.

### Local CI validation

To reproduce CI checks locally before pushing, run the validation script from the repository root:

```bash
./scripts/validate.sh
```

This single command runs all the checks that CI runs:

**PHP checks:**
- PHPCS (WordPress coding standards)
- PHPUnit tests
- PHPStan static analysis
- OpenAPI spec validation

**Node/React checks:**
- OpenAPI types generation check
- TypeScript type checking
- ESLint
- Prettier formatting check
- Vitest tests with coverage
- Production build

You can also run checks selectively:

```bash
./scripts/validate.sh --php    # Run only PHP checks
./scripts/validate.sh --node   # Run only Node/React checks
./scripts/validate.sh --help   # Show help
```

The script will install dependencies if needed and report a summary of passed/failed checks at the end.

### Building UI assets

To build the React UI for production (the exact assets WordPress will enqueue):

```bash
./scripts/build-assets.sh
```

This single command:
1. Installs npm dependencies (if needed)
2. Runs the Vite production build with TypeScript checking
3. Outputs hashed assets to `assets/build/`
4. Generates the manifest at `assets/build/.vite/manifest.json`

WordPress reads the manifest to resolve the correct hashed filenames for enqueuing.

### Development workflow

For local development with hot reloading:
```bash
cd react
npm install
npm run dev
```

Build manually (alternative to build-assets.sh):
```bash
cd react
npm run build
```

Start the WordPress dev environment:
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

> **Complete reference:** See [EXTENSIONS.md](EXTENSIONS.md) for the full list of supported actions, filters, and extension points with detailed signatures and examples.

AgentWP exposes filters to help you customize behavior. Key hooks are summarized below.

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

## Adding a new REST endpoint (core development)

This section covers how to add new REST endpoints to the AgentWP plugin itself. For third-party extensions, see [Extension guide: custom REST controller](#extension-guide-custom-rest-controller) below.

### Canonical location

All REST controllers must be placed in:

- **Directory**: `src/Rest/`
- **Namespace**: `AgentWP\Rest`
- **Base class**: Extend `AgentWP\API\RestController`

> **Note:** Some legacy controllers exist in `src/API/` (e.g., `HistoryController`, `ThemeController`). New controllers should NOT be placed there. The `src/API/` directory contains only the shared base class (`RestController`) and legacy controllers that will be migrated to `src/Rest/` in a future release.

### Step-by-step guide

1. **Create the controller class** in `src/Rest/`:

```php
<?php
namespace AgentWP\Rest;

use AgentWP\API\RestController;
use WP_REST_Server;

class MyFeatureController extends RestController {

    public function register_routes() {
        register_rest_route(
            self::REST_NAMESPACE,
            '/my-feature',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_feature' ),
                    'permission_callback' => array( $this, 'permissions_check' ),
                ),
            )
        );
    }

    public function get_feature( $request ) {
        // Resolve services from container
        $service = $this->resolve( MyServiceInterface::class );
        if ( ! $service ) {
            return $this->response_error( 'service_unavailable', 'Service unavailable.', 500 );
        }

        return $this->response_success( array( 'data' => $service->getData() ) );
    }
}
```

2. **Register the controller** in `src/Providers/RestServiceProvider.php`:

```php
// In the register() method, add to the controllers array:
$this->container->singleton(
    \AgentWP\Rest\MyFeatureController::class,
    fn( $c ) => new \AgentWP\Rest\MyFeatureController()
);
$this->container->tag( \AgentWP\Rest\MyFeatureController::class, 'rest.controller' );
```

3. **Add the OpenAPI annotation** to the controller method for spec sync:

```php
/**
 * Get feature data.
 *
 * @openapi GET /agentwp/v1/my-feature
 *
 * @param WP_REST_Request $request Request instance.
 * @return WP_REST_Response
 */
public function get_feature( $request ) { ... }
```

4. **Update `docs/openapi.json`** with the endpoint definition and run validation:

```bash
composer run openapi:validate
```

### Controller conventions

- Use `self::REST_NAMESPACE` (`agentwp/v1`) for route registration
- Use `permissions_check()` for permission callback (requires `manage_woocommerce`)
- Resolve dependencies via `$this->resolve()` or `$this->resolveRequired()`
- Return responses via `$this->response_success()` or `$this->response_error()`
- All controllers are rate-limited automatically (30 requests/60 seconds)

---

## Extension guide: custom REST controller

You can add custom REST API endpoints by registering a controller with the `rest.controller` container tag. This integrates your endpoints with AgentWP's infrastructure (rate limiting, response formatting, error handling).

### 1) Create a controller class

Extend `RestController` to inherit permission checks, validation, and response helpers:

```php
namespace MyPlugin\AgentWP;

use AgentWP\API\RestController;
use WP_REST_Server;

class ShipmentController extends RestController {

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/shipments',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_shipments' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/shipments/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_shipment' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);
	}

	public function get_shipments( $request ) {
		$service = $this->resolve( ShipmentServiceInterface::class );
		if ( ! $service ) {
			return $this->response_error( 'service_unavailable', 'Shipment service unavailable.', 500 );
		}

		return $this->response_success( array( 'shipments' => $service->getAll() ) );
	}

	public function get_shipment( $request ) {
		$id      = (int) $request->get_param( 'id' );
		$service = $this->resolve( ShipmentServiceInterface::class );

		if ( ! $service ) {
			return $this->response_error( 'service_unavailable', 'Shipment service unavailable.', 500 );
		}

		$shipment = $service->find( $id );
		if ( ! $shipment ) {
			return $this->response_error( 'not_found', 'Shipment not found.', 404 );
		}

		return $this->response_success( array( 'shipment' => $shipment ) );
	}
}
```

### 2) Register and tag the controller

Use the `agentwp_register_providers` action to register your controller with the `rest.controller` tag:

```php
add_action( 'agentwp_register_providers', function( $container ) {
	// Register your service
	$container->singleton(
		\MyPlugin\AgentWP\ShipmentServiceInterface::class,
		fn() => new \MyPlugin\AgentWP\ShipmentService()
	);

	// Register and tag the controller
	$container->bind(
		\MyPlugin\AgentWP\ShipmentController::class,
		\MyPlugin\AgentWP\ShipmentController::class
	);
	$container->tag( \MyPlugin\AgentWP\ShipmentController::class, 'rest.controller' );
}, 10, 1 );
```

### Available RestController helpers

| Method | Purpose |
|--------|---------|
| `$this->resolve($id)` | Resolve a service from the container (returns null if unavailable) |
| `$this->resolveRequired($id, $name)` | Resolve a required service (returns error response if unavailable) |
| `$this->permissions_check($request)` | Built-in permission check requiring `manage_woocommerce` |
| `$this->validate_request($request, $schema)` | Validate request payload against JSON schema |
| `$this->response_success($data, $status)` | Build standardized success response |
| `$this->response_error($code, $message, $status)` | Build standardized error response |

For a complete example with update endpoints and validation, see [EXTENSIONS.md](EXTENSIONS.md#registering-a-custom-rest-controller).

## UI architecture

The React-based UI in `react/src/` is the sole supported runtime for the AgentWP admin interface. The `AssetManager` loads the Vite build output from `assets/build/` by reading the manifest at `assets/build/.vite/manifest.json`.

### Building the UI

Build the React UI before deploying:

```bash
# One-command build (recommended)
./scripts/build-assets.sh

# Or manually
cd react
npm install
npm run build
```

After building, the `assets/build/` directory will contain the production assets.

### For contributors

All UI development happens in the `react/` directory. The Vite build output is the only UI shipped with the plugin.

## Extension migration notes

This section documents breaking changes that affect extensions and third-party integrations. For full release notes, see [CHANGELOG.md](CHANGELOG.md).

### Handler registration deprecation

**Affected:** Custom intent handlers using legacy registration patterns.

**Timeline:**
- v1.x (Current): Both patterns work; attribute-based is recommended
- v2.0 (Next major): Legacy patterns emit deprecation warnings
- v3.0 (Future): Legacy patterns removed

**Migration steps:**

1. **Add `#[HandlesIntent]` attribute** to your handler class:
   ```php
   use AgentWP\Intent\Attributes\HandlesIntent;

   #[HandlesIntent('my_custom_intent')]
   class MyHandler extends BaseHandler {
       // ...
   }
   ```

2. **Remove legacy `getIntent()` method** if present (no longer needed with attribute).

3. **Register via service provider** with container tag:
   ```php
   $this->container->tag(MyHandler::class, 'intent.handler');
   ```

See [ADR 0002](adr/0002-intent-handler-registration.md) for full details.

### ServiceResult return type

**Affected:** Code that consumes application service return values.

Application services now return `ServiceResult` instead of arrays or mixed types. Update consuming code:

```php
// Before
$result = $service->prepare_refund($order_id, $amount);
if (isset($result['error'])) {
    // Handle error
}

// After
$result = $service->prepare_refund($order_id, $amount);
if ($result->isFailure()) {
    // Use $result->code, $result->message, $result->httpStatus
    return new WP_REST_Response($result->toArray(), $result->httpStatus);
}
// Access data via $result->get('key') or $result->data
```

### Policy layer for permissions

**Affected:** Custom services that check user capabilities.

Services no longer call `current_user_can()` directly. Instead, inject `PolicyInterface`:

```php
use AgentWP\Contracts\PolicyInterface;

class MyService {
    public function __construct(private PolicyInterface $policy) {}

    public function doSomething(): ServiceResult {
        if (!$this->policy->canManageOrders()) {
            return ServiceResult::permissionDenied();
        }
        // ...
    }
}
```

### Gateway abstractions for WooCommerce

**Affected:** Custom services that call WooCommerce functions directly.

WooCommerce operations are now abstracted behind gateway interfaces for testability:

```php
// Before
$order = wc_get_order($order_id);
$refund = wc_create_refund($args);

// After
$order = $this->refundGateway->get_order($order_id);
$refund = $this->refundGateway->create_refund($args);
```

Available gateways:
- `WooCommerceRefundGatewayInterface`
- `WooCommerceOrderGatewayInterface`
- `WooCommerceStockGatewayInterface`
- `WooCommerceUserGatewayInterface`

### Draft lifecycle changes

**Affected:** Code that interacts with draft storage directly.

Use `DraftManagerInterface` instead of `DraftStorageInterface` for draft operations:

```php
// Before
$draft_id = $this->storage->generate_id('refund');
$this->storage->store('refund', $draft_id, $payload, 600);

// After
$result = $this->draftManager->create('refund', $payload, $preview);
$draft_id = $result->get('draft_id');
```

Draft payloads now include `preview` data and use the standardized `DraftPayload` DTO.

### Intent classification

**Affected:** Custom intent classifiers or direct `IntentClassifier` usage.

`ScorerRegistry` is now the canonical classifier. If you were using `IntentClassifier` directly:

```php
// Before
$classifier = new IntentClassifier();
$intent = $classifier->classify($input);

// After
$classifier = $container->get(IntentClassifierInterface::class);
$intent = $classifier->classify($input);
```

Add custom scorers via the `agentwp_intent_scorers` filter.

### Configuration constants

**Affected:** Code using hardcoded option names.

All option keys are now defined in `AgentWPConfig`. Use constants instead of strings:

```php
// Before
$value = get_option('agentwp_api_key');

// After (if needed outside SettingsManager)
$value = get_option(AgentWPConfig::OPTION_API_KEY);

// Preferred: Use SettingsManager
$value = $settingsManager->getApiKey();
```

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

### TypeScript type generation

The React frontend can generate TypeScript types from the OpenAPI spec to ensure type safety for API calls.

**Generating types:**

```bash
cd react
npm run generate:types
```

This generates `react/src/types/api.ts` from `docs/openapi.json` using [openapi-typescript](https://openapi-ts.dev/).

**Workflow:**

1. Update `docs/openapi.json` when adding/modifying endpoints
2. Run `npm run generate:types` in the `react/` directory
3. Import generated types in your React code:
   ```typescript
   import type { components, paths } from '@/types/api';

   type SettingsResponse = components['schemas']['SettingsResponse'];
   type IntentRequest = components['schemas']['IntentRequest'];
   ```

The generated types are deterministic and should be regenerated when the OpenAPI spec changes.

## Deprecation policy

AgentWP follows semantic versioning. The `@deprecated` annotation format ensures deprecation metadata is credible and actionable.

### Annotation format

All `@deprecated` PHPDoc annotations must include the version when the deprecation was introduced:

```php
/**
 * @deprecated X.Y.Z Reason and migration path.
 */
```

**Format:**
- `X.Y.Z` - The version when the deprecation was introduced (must match a released or current `AGENTWP_VERSION`)
- For planned removals, include: `Planned removal: X.Y.Z`

**Examples:**

```php
/**
 * @deprecated 0.1.0 Use SettingsManager constants directly.
 */
const OPTION_SETTINGS = SettingsManager::OPTION_SETTINGS;

/**
 * @deprecated 0.1.0 Use the #[HandlesIntent] attribute instead.
 *             Planned removal: 1.0.0. Migration: Add #[HandlesIntent(Intent::YOUR_INTENT)]
 *             to your handler class.
 */
public function getIntent(): string { ... }
```

### Version requirements

1. **Version must exist**: The deprecation version must be `≤ AGENTWP_VERSION`. Never reference future versions for when something *became* deprecated.
2. **Planned removal is optional**: If included, the planned removal version should follow semver (typically the next major version).
3. **Migration path required**: Every deprecation must explain what to use instead.

### Deprecation lifecycle

| Phase | Description |
|-------|-------------|
| Deprecated | Feature marked with `@deprecated`. Still functional. |
| Warning | (Optional) `_deprecated_function()` or similar emits runtime notice. |
| Removed | Feature removed in a future major version. |

### Current deprecations

No active deprecations. All previously deprecated items have been removed as of the latest release.

### Removed (previously deprecated)

The following deprecated items have been removed. If your code relies on them, migrate to the replacement:

| Item | Deprecated In | Removed In | Replacement |
|------|--------------|------------|-------------|
| `Plugin::OPTION_*` constants | 0.1.0 | 1.0.0 | `SettingsManager::OPTION_*` |
| `Plugin::get_default_settings()` | 0.1.0 | 1.0.0 | `SettingsManager::getDefaults()` |
| `Plugin::get_default_usage_stats()` | 0.1.0 | 1.0.0 | `SettingsManager::getDefaultUsageStats()` |
| `OpenAIClient::API_BASE` | 0.1.0 | 1.0.0 | `AgentWPConfig::OPENAI_API_BASE_URL` |
| `OpenAIClient::MAX_STREAM_*` | 0.1.0 | 1.0.0 | `AgentWPConfig::STREAM_MAX_*` |
| `OpenAIClient::MAX_TOOL_ARGUMENTS_LENGTH` | 0.1.0 | 1.0.0 | `AgentWPConfig::STREAM_MAX_TOOL_ARG_LENGTH` |
| `BaseHandler::getIntent()` | 0.1.0 | 1.0.0 | `#[HandlesIntent]` attribute |
| `Index::handle_order_save()` | 0.1.0 | 1.0.0 | `handle_order_created()` / `handle_order_updated()` |

## Related documentation

| Document | Purpose |
|----------|---------|
| [EXTENSIONS.md](EXTENSIONS.md) | **Complete reference** for all supported actions, filters, and extension points |
| [ARCHITECTURE.md](ARCHITECTURE.md) | Technical architecture with boot flow diagrams and service layer details |
| [ARCHITECTURE-IMPROVEMENT-PLAN.md](ARCHITECTURE-IMPROVEMENT-PLAN.md) | Completed architecture improvement roadmap |
| [CHANGELOG.md](CHANGELOG.md) | Version history and migration guide for breaking changes |
| [openapi.json](openapi.json) | REST API specification for TypeScript type generation |
| [search-index.md](search-index.md) | Search index implementation and troubleshooting |

### Architecture Decision Records

For architectural decisions and rationale, see the ADRs in `docs/adr/`:

| ADR | Topic |
|-----|-------|
| [0001](adr/0001-rest-controller-dependency-resolution.md) | REST Controller Dependency Resolution |
| [0002](adr/0002-intent-handler-registration.md) | Intent Handler Registration |
| [0003](adr/0003-intent-classification-strategy.md) | Intent Classification Strategy |
| [0004](adr/0004-openai-client-architecture.md) | OpenAI Client Architecture |
| [0005](adr/0005-rest-rate-limiting.md) | REST Rate Limiting |
| [0006](adr/0006-search-index-architecture.md) | Search Index Architecture |
