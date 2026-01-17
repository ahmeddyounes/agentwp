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

Request flow:
1. REST call hits `IntentController::create_intent`.
2. `Engine` classifies the prompt and enriches context.
3. A handler returns a `Response` object.
4. REST response is normalized into the standard envelope.

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
