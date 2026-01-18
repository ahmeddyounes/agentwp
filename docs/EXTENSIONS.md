# AgentWP Extension Points

This document is the **single source of truth** for all supported actions, filters, and extension points in AgentWP. Extension developers should reference this document when building integrations.

> **Maintenance note:** When adding new hooks to the codebase, document them here with their file location, parameters, and purpose.

## Table of Contents

- [Tools vs Functions Terminology](#tools-vs-functions-terminology)
- [Core Plugin Hooks](#core-plugin-hooks)
- [Intent System Hooks](#intent-system-hooks)
- [Intent Classification Hooks](#intent-classification-hooks)
- [Error Handling Hooks](#error-handling-hooks)
- [Configuration Hooks](#configuration-hooks)
- [Customer Service Hooks](#customer-service-hooks)
- [Encryption Hooks](#encryption-hooks)
- [Notification Hooks](#notification-hooks)
- [Extension Patterns](#extension-patterns)
  - [Custom Service Provider](#registering-a-custom-service-provider)
  - [Custom Intent Handler](#adding-a-custom-intent-handler)
  - [Custom Intent Scorer](#creating-a-custom-intent-scorer)
  - [Custom AI Functions](#registering-custom-ai-functions)
  - [Custom REST Controller](#registering-a-custom-rest-controller)
  - [Configuration Overrides](#overriding-configuration)
  - [Custom Error Logging](#custom-error-logging)

---

## Tools vs Functions Terminology

AgentWP uses OpenAI “tools” (function calling) internally, but some hooks still refer to “functions” for historical reasons:

- **Tool schemas** live in `src/AI/Functions/*` and implement `FunctionSchema`. They define the JSON schema sent to OpenAI and are stored in `ToolRegistry`.
- **Tool executors** live in `src/Intent/Tools/*` and implement `ExecutableToolInterface`. They perform the actual work and are registered with `ToolDispatcher`.
- **Tool dispatch** is handled by `ToolDispatcher`, which validates arguments and routes tool calls to executors.
- **Function registry (legacy)** powers `function_suggestions` in response payloads via `FunctionRegistry`. Defaults are derived from handler tool lists (`ToolSuggestionProvider`) and filtered against the `ToolRegistry` when available. It does **not** register tool schemas or executors.

If you are adding a new tool, you must provide **both** the schema and the executor, register them via a service provider, and ensure the handler exposes the tool via `getToolNames()`. The “function” hooks below only affect suggestions and intent-to-function mapping.

---

## Core Plugin Hooks

### `agentwp_register_providers` (Action)

Register custom service providers during plugin initialization.

| Property | Value |
|----------|-------|
| **File** | `src/Plugin.php:152` |
| **When** | After core providers are registered, before booting |

**Parameters:**
- `ContainerInterface $container` — The dependency injection container

**Example:**
```php
add_action( 'agentwp_register_providers', function( $container ) {
    $container->bind( MyService::class, MyService::class );
}, 10, 1 );
```

---

### `agentwp_boot_providers` (Action)

Fires after all service providers have been booted. Use for post-boot initialization.

| Property | Value |
|----------|-------|
| **File** | `src/Plugin.php:174` |
| **When** | After all core and custom providers are booted |

**Parameters:**
- `ContainerInterface $container` — The dependency injection container

**Example:**
```php
add_action( 'agentwp_boot_providers', function( $container ) {
    // Post-boot initialization logic
}, 10, 1 );
```

---

## Intent System Hooks

### `agentwp_intent_handlers` (Filter)

Modify the list of intent handlers before registration.

| Property | Value |
|----------|-------|
| **File** | `src/Intent/Engine.php:75` |
| **When** | During engine construction |

**Parameters:**
- `array $handlers` — Current array of handler instances
- `Engine $engine` — The intent engine instance

**Returns:** Array of handler instances

**Example:**
```php
add_filter( 'agentwp_intent_handlers', function( $handlers, $engine ) {
    $handlers[] = new MyCustomHandler();
    return $handlers;
}, 10, 2 );
```

---

### `agentwp_register_intent_functions` (Action)

Register custom function suggestions (legacy) that are surfaced in response payloads.
This does **not** register tool schemas or executors. To add executable tools, register a tool schema in `ToolRegistry`, a tool executor in `ToolDispatcher`, and expose it via `getToolNames()`.
Suggestions are filtered against the `ToolRegistry` when available.

| Property | Value |
|----------|-------|
| **File** | `src/Intent/Engine.php:82` |
| **When** | During engine construction after handlers are registered |

**Parameters:**
- `FunctionRegistry $registry` — Registry for AI functions
- `Engine $engine` — The intent engine instance

**Example:**
```php
add_action( 'agentwp_register_intent_functions', function( $registry, $engine ) {
    $registry->register( 'my_custom_function', new MyCustomFunction() );
}, 10, 2 );
```

---

### `agentwp_default_function_mapping` (Filter)

Customize which function suggestions are associated with each intent.
Defaults are derived from handlers that implement `ToolSuggestionProvider` (all `AbstractAgenticHandler` subclasses do).
This mapping does **not** affect tool execution; it only populates `function_suggestions` in responses.

| Property | Value |
|----------|-------|
| **File** | `src/Intent/Engine.php:248` |
| **When** | When resolving functions for an intent |

**Parameters:**
- `array $mapping` — Default intent-to-function mapping
- `Engine $engine` — The intent engine instance

**Returns:** Modified mapping array

**Example:**
```php
add_filter( 'agentwp_default_function_mapping', function( $mapping, $engine ) {
    $mapping['custom_intent'] = array( 'my_function', 'another_function' );
    return $mapping;
}, 10, 2 );
```

---

## Intent Classification Hooks

### `agentwp_intent_scorers` (Filter)

Add custom intent scorers for classification.

| Property | Value |
|----------|-------|
| **File** | `src/Providers/IntentServiceProvider.php:180` |
| **When** | During scorer registry initialization |

**Parameters:**
- `IntentScorerInterface[] $scorers` — Array of built-in scorers

**Returns:** Array of scorers implementing `IntentScorerInterface`

**Built-in scorers:** RefundScorer, StatusScorer, StockScorer, EmailScorer, AnalyticsScorer, CustomerScorer, SearchScorer

**Example:**
```php
add_filter( 'agentwp_intent_scorers', function( $scorers ) {
    $scorers[] = new MyCustomScorer();
    return $scorers;
} );
```

---

### `agentwp_intent_classified` (Action)

Fires after intent classification completes. Useful for analytics, logging, or debugging.

| Property | Value |
|----------|-------|
| **File** | `src/Intent/Classifier/ScorerRegistry.php:140` |
| **When** | After classification scoring completes |

**Parameters:**
- `string $intent` — The classified intent constant
- `array $scores` — All weighted intent scores
- `string $input` — Original user input
- `array $context` — Classification context

**Example:**
```php
add_action( 'agentwp_intent_classified', function( $intent, $scores, $input, $context ) {
    error_log( "Classified intent: {$intent} for input: {$input}" );
}, 10, 4 );
```

---

## Error Handling Hooks

### `agentwp_log_error` (Action)

Log structured errors for debugging and monitoring.

| Property | Value |
|----------|-------|
| **File** | `src/Error/Handler.php:172` |
| **When** | When errors are logged via `Error\Handler::logError()` |

**Parameters:**
- `array $log_entry` — Structured error log entry with keys:
  - `plugin` (string) — Always `'agentwp'`
  - `timestamp` (string) — ISO 8601 datetime
  - `code` (string) — Error code
  - `type` (string) — Error category
  - `message` (string) — Error message
  - `context` (array) — Additional context

**Example:**
```php
add_action( 'agentwp_log_error', function( $log_entry ) {
    // Send to external logging service
    wp_remote_post( 'https://logs.example.com', array(
        'body' => wp_json_encode( $log_entry ),
    ) );
} );
```

---

## Configuration Hooks

AgentWP uses a dynamic filter system for configuration values. All configuration values can be overridden via filters following the pattern: `agentwp_config_{key}`.

### `agentwp_config_{key}` (Filter)

Override any configuration value dynamically.

| Property | Value |
|----------|-------|
| **File** | `src/Config/AgentWPConfig.php:244` |
| **When** | When configuration values are retrieved |

**Parameters:** Mixed (current configuration value)

**Returns:** Modified value

**Available configuration keys:**

#### Agentic Loop Settings
| Filter | Description | Default |
|--------|-------------|---------|
| `agentwp_config_agentic_max_turns` | Max iterations in agentic loop | 10 |

#### Intent Classification Weights
| Filter | Description |
|--------|-------------|
| `agentwp_config_intent_weight_order_search` | Order search intent weight |
| `agentwp_config_intent_weight_order_refund` | Refund intent weight |
| `agentwp_config_intent_weight_order_status` | Status intent weight |
| `agentwp_config_intent_weight_product_stock` | Stock intent weight |
| `agentwp_config_intent_weight_email_draft` | Email draft intent weight |
| `agentwp_config_intent_weight_analytics_query` | Analytics intent weight |
| `agentwp_config_intent_weight_customer_lookup` | Customer lookup intent weight |

#### Confidence Thresholds
| Filter | Description |
|--------|-------------|
| `agentwp_config_confidence_threshold_high` | High confidence threshold |
| `agentwp_config_confidence_threshold_medium` | Medium confidence threshold |
| `agentwp_config_confidence_threshold_low` | Low confidence threshold |
| `agentwp_config_intent_minimum_threshold` | Minimum intent threshold |
| `agentwp_config_intent_similarity_threshold` | Intent similarity threshold |

#### Cache Settings
| Filter | Description |
|--------|-------------|
| `agentwp_config_cache_ttl_default` | Default cache TTL |
| `agentwp_config_cache_ttl_short` | Short cache TTL |
| `agentwp_config_cache_ttl_draft` | Draft cache TTL |

#### API Settings
| Filter | Description |
|--------|-------------|
| `agentwp_config_api_timeout_default` | Default API timeout |
| `agentwp_config_api_timeout_min` | Minimum API timeout |
| `agentwp_config_api_timeout_max` | Maximum API timeout |
| `agentwp_config_api_max_retries` | Maximum API retries |
| `agentwp_config_api_initial_delay` | Initial retry delay |
| `agentwp_config_api_max_delay` | Maximum retry delay |

#### OpenAI Settings
| Filter | Description |
|--------|-------------|
| `agentwp_config_openai_api_base_url` | OpenAI API base URL |
| `agentwp_config_openai_default_model` | Default OpenAI model |
| `agentwp_config_openai_timeout_default` | Default OpenAI timeout |
| `agentwp_config_openai_max_retries` | Max OpenAI retries |

**Example:**
```php
// Increase order search intent weight
add_filter( 'agentwp_config_intent_weight_order_search', function( $value ) {
    return 2.0;
} );

// Extend draft cache TTL to 1 hour
add_filter( 'agentwp_config_cache_ttl_draft', function( $value ) {
    return 3600;
} );
```

---

### `agentwp_memory_limit` (Filter)

Customize the maximum number of memory entries stored.

| Property | Value |
|----------|-------|
| **File** | `src/Providers/IntentServiceProvider.php:110` |
| **Default** | 5 |
| **Minimum** | 1 |

**Parameters:**
- `int $limit` — Maximum memory entries

**Returns:** Modified limit integer

**Example:**
```php
add_filter( 'agentwp_memory_limit', function( $limit ) {
    return 10; // Store more conversation history
} );
```

---

### `agentwp_memory_ttl` (Filter)

Customize the time-to-live for memory entries.

| Property | Value |
|----------|-------|
| **File** | `src/Providers/IntentServiceProvider.php:111` |
| **Default** | 1800 (30 minutes) |
| **Minimum** | 60 |

**Parameters:**
- `int $ttl` — TTL in seconds

**Returns:** Modified TTL integer

**Example:**
```php
add_filter( 'agentwp_memory_ttl', function( $ttl ) {
    return 3600; // 1 hour
} );
```

---

## Customer Service Hooks

### `agentwp_customer_health_thresholds` (Filter)

Customize customer health score thresholds.

| Property | Value |
|----------|-------|
| **File** | `src/Services/CustomerService.php:716` |
| **When** | When calculating customer health scores |

**Parameters:**
- `array $thresholds` — Default thresholds with keys:
  - `active` (int) — Days threshold for active status (default: 60)
  - `at_risk` (int) — Days threshold for at-risk status (default: 180)

**Returns:** Modified thresholds array

**Example:**
```php
add_filter( 'agentwp_customer_health_thresholds', function( $thresholds ) {
    $thresholds['active']  = 30;  // Consider active if ordered in last 30 days
    $thresholds['at_risk'] = 90;  // At risk after 90 days
    return $thresholds;
} );
```

---

## Encryption Hooks

### `agentwp_encryption_rotation_materials` (Filter)

Provide additional encryption key materials for key rotation.

| Property | Value |
|----------|-------|
| **File** | `src/Security/Encryption.php:373` |
| **When** | During key rotation operations |

**Parameters:**
- `array $rotations` — Previously set rotation materials

**Returns:** Array of encryption key material strings

**Example:**
```php
add_filter( 'agentwp_encryption_rotation_materials', function( $rotations ) {
    // Add legacy key for decryption during rotation
    $rotations[] = get_option( 'my_legacy_encryption_key' );
    return $rotations;
} );
```

---

## Notification Hooks

### `agentwp_refund_notify_customer` (Filter)

Control whether refund emails are sent.

| Property | Value |
|----------|-------|
| **When** | Before sending refund notification |

**Parameters:**
- `bool $notify` — Whether to send notification
- `WC_Order_Refund $refund` — The refund object
- `WC_Order $order` — The parent order
- `array $payload` — Refund payload data

**Returns:** Boolean

**Example:**
```php
add_filter( 'agentwp_refund_notify_customer', function( $notify, $refund, $order, $payload ) {
    // Never notify for orders under $10
    if ( $order->get_total() < 10 ) {
        return false;
    }
    return $notify;
}, 10, 4 );
```

---

### `agentwp_status_notify_customer` (Filter)

Control whether order status emails are sent.

| Property | Value |
|----------|-------|
| **When** | Before sending status change notification |

**Parameters:**
- `bool $notify` — Whether to send notification
- `WC_Order $order` — The order object
- `string $new_status` — The new order status

**Returns:** Boolean

**Example:**
```php
add_filter( 'agentwp_status_notify_customer', function( $notify, $order, $new_status ) {
    // Only notify for completed orders
    return 'completed' === $new_status;
}, 10, 3 );
```

---

## Extension Patterns

### Registering a Custom Service Provider

```php
add_action( 'agentwp_register_providers', function( $container ) {
    // Register your service
    $container->singleton( MyService::class, function( $c ) {
        return new MyService(
            $c->get( SomeDependency::class )
        );
    } );

    // Register a custom intent handler
    $container->singleton( MyIntentHandler::class, function( $c ) {
        return new MyIntentHandler(
            $c->get( MyService::class )
        );
    } );

    // Tag handler for discovery by Engine
    $container->tag( MyIntentHandler::class, 'intent.handler' );
}, 10, 1 );
```

---

### Adding a Custom Intent Handler

```php
use AgentWP\Intent\Attributes\HandlesIntent;
use AgentWP\Intent\Handlers\BaseHandler;
use AgentWP\AI\Response;

#[HandlesIntent( 'shipment_delay' )]
class ShipmentDelayHandler extends BaseHandler {

    public function __construct() {
        parent::__construct( 'shipment_delay' );
    }

    public function handle( array $context ): Response {
        // Handle the intent
        return $this->build_response( $context, 'Shipment delay handled.' );
    }
}

// Register via filter
add_filter( 'agentwp_intent_handlers', function( $handlers, $engine ) {
    $handlers[] = new ShipmentDelayHandler();
    return $handlers;
}, 10, 2 );
```

---

### Creating a Custom Intent Scorer

```php
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
add_filter( 'agentwp_intent_scorers', function( $scorers ) {
    $scorers[] = new ShipmentDelayScorer();
    return $scorers;
} );
```

---

### Registering Custom AI Functions

These hooks register **function suggestions** only. They do not create tool schemas or tool executors.
To add a callable tool, create a schema in `src/AI/Functions`, an executor in `src/Intent/Tools`, register both in a service provider, and include the tool name in your handler's `getToolNames()`. `AbstractAgenticHandler` implements `ToolSuggestionProvider`, so suggestions are derived from your tool list by default.

```php
add_action( 'agentwp_register_intent_functions', function( $registry, $engine ) {
    $registry->register( 'check_shipment_status', new CheckShipmentStatusFunction() );
    $registry->register( 'draft_delay_notification', new DraftDelayNotificationFunction() );
}, 10, 2 );

// Map functions to your custom intent
add_filter( 'agentwp_default_function_mapping', function( $mapping, $engine ) {
    $mapping['shipment_delay'] = array(
        'check_shipment_status',
        'draft_delay_notification',
    );
    return $mapping;
}, 10, 2 );
```

---

### Overriding Configuration

```php
// Increase intent weight for order search
add_filter( 'agentwp_config_intent_weight_order_search', fn() => 1.5 );

// Extend conversation memory
add_filter( 'agentwp_memory_limit', fn() => 10 );
add_filter( 'agentwp_memory_ttl', fn() => 3600 );

// Customize health scoring thresholds
add_filter( 'agentwp_customer_health_thresholds', function( $thresholds ) {
    return array(
        'active'  => 30,
        'at_risk' => 90,
    );
} );
```

---

### Custom Error Logging

```php
add_action( 'agentwp_log_error', function( $log_entry ) {
    // Log to custom location
    $logfile = WP_CONTENT_DIR . '/agentwp-errors.log';
    file_put_contents(
        $logfile,
        wp_json_encode( $log_entry ) . "\n",
        FILE_APPEND
    );

    // Or send to external service
    if ( 'critical' === $log_entry['type'] ) {
        wp_remote_post( 'https://alerts.example.com', array(
            'body' => wp_json_encode( $log_entry ),
        ) );
    }
} );
```

---

### Registering a Custom REST Controller

Custom REST controllers can be registered via the `rest.controller` container tag. This allows your extension to add new API endpoints that integrate with AgentWP's infrastructure (rate limiting, response formatting, error handling).

**Step 1: Create a controller class extending RestController:**

```php
namespace MyPlugin\AgentWP;

use AgentWP\Rest\RestController;
use WP_REST_Server;

class ShipmentController extends RestController {

    /**
     * Register routes.
     *
     * @return void
     */
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
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array( $this, 'update_shipment' ),
                    'permission_callback' => array( $this, 'permissions_check' ),
                ),
            )
        );
    }

    /**
     * Get all shipments.
     *
     * @param \WP_REST_Request $request Request instance.
     * @return \WP_REST_Response
     */
    public function get_shipments( $request ) {
        // Resolve service from container
        $service = $this->resolve( ShipmentServiceInterface::class );
        if ( ! $service ) {
            return $this->response_error(
                'service_unavailable',
                __( 'Shipment service is not available.', 'my-plugin' ),
                500
            );
        }

        $shipments = $service->getAll();

        return $this->response_success(
            array( 'shipments' => $shipments )
        );
    }

    /**
     * Get a single shipment.
     *
     * @param \WP_REST_Request $request Request instance.
     * @return \WP_REST_Response
     */
    public function get_shipment( $request ) {
        $id = (int) $request->get_param( 'id' );

        $service = $this->resolve( ShipmentServiceInterface::class );
        if ( ! $service ) {
            return $this->response_error(
                'service_unavailable',
                __( 'Shipment service is not available.', 'my-plugin' ),
                500
            );
        }

        $shipment = $service->find( $id );
        if ( ! $shipment ) {
            return $this->response_error(
                'not_found',
                __( 'Shipment not found.', 'my-plugin' ),
                404
            );
        }

        return $this->response_success( array( 'shipment' => $shipment ) );
    }

    /**
     * Update a shipment.
     *
     * @param \WP_REST_Request $request Request instance.
     * @return \WP_REST_Response
     */
    public function update_shipment( $request ) {
        $validation = $this->validate_request( $request, $this->get_update_schema() );
        if ( is_wp_error( $validation ) ) {
            return $this->response_error(
                'invalid_request',
                $validation->get_error_message(),
                400
            );
        }

        $id      = (int) $request->get_param( 'id' );
        $payload = $request->get_json_params();

        $service = $this->resolve( ShipmentServiceInterface::class );
        if ( ! $service ) {
            return $this->response_error(
                'service_unavailable',
                __( 'Shipment service is not available.', 'my-plugin' ),
                500
            );
        }

        $result = $service->update( $id, $payload );

        return $this->response_success(
            array(
                'updated'  => true,
                'shipment' => $result,
            )
        );
    }

    /**
     * Get update schema.
     *
     * @return array
     */
    private function get_update_schema(): array {
        return array(
            'type'       => 'object',
            'properties' => array(
                'status'   => array(
                    'type' => 'string',
                    'enum' => array( 'pending', 'shipped', 'delivered' ),
                ),
                'tracking' => array(
                    'type' => 'string',
                ),
            ),
        );
    }
}
```

**Step 2: Register and tag the controller via service provider:**

```php
add_action( 'agentwp_register_providers', function( $container ) {
    // Register the service your controller depends on
    $container->singleton(
        \MyPlugin\AgentWP\ShipmentServiceInterface::class,
        fn() => new \MyPlugin\AgentWP\ShipmentService()
    );

    // Register the controller
    $container->bind(
        \MyPlugin\AgentWP\ShipmentController::class,
        \MyPlugin\AgentWP\ShipmentController::class
    );

    // Tag the controller for discovery
    $container->tag(
        \MyPlugin\AgentWP\ShipmentController::class,
        'rest.controller'
    );
}, 10, 1 );
```

**How it works:**

1. AgentWP's `RestServiceProvider` calls `RestRouteRegistrar::fromContainer()`
2. The registrar retrieves all services tagged with `rest.controller`
3. On `rest_api_init`, each controller's `register_routes()` method is called
4. Your endpoints are now available at `/wp-json/agentwp/v1/shipments`

**Available RestController helpers:**

| Method | Description |
|--------|-------------|
| `$this->container()` | Get the DI container instance |
| `$this->resolve($id)` | Resolve a service, returns null if unavailable |
| `$this->resolveRequired($id, $name)` | Resolve a service, returns error response if unavailable |
| `$this->permissions_check($request)` | Built-in permission check (requires `manage_woocommerce`) |
| `$this->validate_request($request, $schema)` | Validate request against JSON schema |
| `$this->response_success($data, $status)` | Build standardized success response |
| `$this->response_error($code, $message, $status)` | Build standardized error response |

**Endpoints created by the example:**

- `GET /wp-json/agentwp/v1/shipments` — List all shipments
- `GET /wp-json/agentwp/v1/shipments/{id}` — Get a single shipment
- `POST /wp-json/agentwp/v1/shipments/{id}` — Update a shipment

---

## Summary Reference

| Hook Type | Hook Name | File | Purpose |
|-----------|-----------|------|---------|
| Action | `agentwp_register_providers` | Plugin.php | Register service providers |
| Action | `agentwp_boot_providers` | Plugin.php | Post-boot initialization |
| Filter | `agentwp_intent_handlers` | Engine.php | Customize intent handlers |
| Action | `agentwp_register_intent_functions` | Engine.php | Register function suggestions (legacy) |
| Filter | `agentwp_default_function_mapping` | Engine.php | Map tool suggestions to intents |
| Filter | `agentwp_intent_scorers` | IntentServiceProvider.php | Add custom scorers |
| Action | `agentwp_intent_classified` | ScorerRegistry.php | Post-classification hook |
| Action | `agentwp_log_error` | Handler.php | Log errors |
| Filter | `agentwp_config_{key}` | AgentWPConfig.php | Override config values |
| Filter | `agentwp_memory_limit` | IntentServiceProvider.php | Customize memory limit |
| Filter | `agentwp_memory_ttl` | IntentServiceProvider.php | Customize memory TTL |
| Filter | `agentwp_encryption_rotation_materials` | Encryption.php | Key rotation materials |
| Filter | `agentwp_customer_health_thresholds` | CustomerService.php | Health score thresholds |
| Filter | `agentwp_refund_notify_customer` | — | Refund notifications |
| Filter | `agentwp_status_notify_customer` | — | Status notifications |
| Tag | `intent.handler` | IntentServiceProvider.php | Register intent handlers |
| Tag | `rest.controller` | RestServiceProvider.php | Register REST controllers |

---

## Related Documentation

- [DEVELOPER.md](DEVELOPER.md) — Full developer guide with architecture overview
- [ARCHITECTURE.md](ARCHITECTURE.md) — Technical architecture details
- [API.md](API.md) — REST API documentation
