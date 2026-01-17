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

## Extension guide: custom intent handler
You can add new intents by implementing the `Handler` interface and wiring it into the engine via `agentwp_intent_handlers`.

### 1) Create a handler class
```php
namespace MyPlugin\AgentWP;

use AgentWP\AI\Response;
use AgentWP\Intent\Handlers\BaseHandler;
use AgentWP\Intent\Intent;

class ShipmentDelayHandler extends BaseHandler {
	public function __construct() {
		parent::__construct( 'shipment_delay' );
	}

	public function handle( array $context ): Response {
		$message = 'Here is a draft email about the delay.';
		return $this->build_response( $context, $message, array(
			'cards' => array(),
		) );
	}
}
```

### 2) Register the handler
```php
add_filter( 'agentwp_intent_handlers', function ( $handlers ) {
	$handlers[] = new \MyPlugin\AgentWP\ShipmentDelayHandler();
	return $handlers;
} );
```

### 3) Register functions for the intent (optional)
```php
add_action( 'agentwp_register_intent_functions', function ( $registry ) {
	$registry->register( 'draft_shipment_delay_email', new \MyPlugin\AgentWP\ShipmentDelayHandler() );
} );
```

### 4) Test the prompt
Call the `/intent` endpoint or use the Command Deck to test:
```
Draft a shipment delay email for order 1234
```

## OpenAPI spec
The OpenAPI spec lives in `docs/openapi.json`. It is generated from endpoint annotations in the REST controllers; update annotations first, then regenerate the spec.
