# ADR 0002: Intent Handler Registration

**Date:** 2026-01-17
**Status:** Accepted

## Context

AgentWP's intent system routes natural language queries to domain-specific handlers (order search, refund processing, analytics, etc.). Historically, three different mechanisms have been used to register and discover intent handlers:

1. **Legacy `getIntent()` / `getSupportedIntents()` methods**: Handlers expose supported intents through instance methods. This requires reflection or method checking during discovery and results in O(n) lookup at runtime.

2. **`agentwp_intent_handlers` filter**: A WordPress filter that allows third-party code to add or modify the handlers array passed to the Engine constructor.

3. **`#[HandlesIntent(...)]` attribute + `intent.handler` container tag**: Modern approach using PHP 8 attributes for declarative intent mapping and DI container tags for automatic collection.

The coexistence of multiple registration approaches creates:
- Confusion about which method to use for new handlers
- Maintenance burden supporting three code paths
- Inconsistent discovery mechanisms across the codebase
- Risk of handlers being registered but not properly mapped to intents

## Decision

### Primary Registration Mechanism

The **single supported registration mechanism** for intent handlers is:

```php
#[HandlesIntent(Intent::ORDER_SEARCH)]
class OrderSearchHandler extends AbstractAgenticHandler {
    // ...
}
```

Combined with container registration in a service provider:

```php
$this->container->singleton(
    OrderSearchHandler::class,
    fn($c) => new OrderSearchHandler(
        $c->get(OrderSearchServiceInterface::class),
        $c->get(AIClientFactoryInterface::class)
    )
);
$this->container->tag(OrderSearchHandler::class, 'intent.handler');
```

### Why This Approach

| Criteria | `#[HandlesIntent]` + Tag | `agentwp_intent_handlers` | `getIntent()` / `getSupportedIntents()` |
|----------|--------------------------|---------------------------|----------------------------------------|
| Declarative | Yes | No | No |
| Type-safe | Yes | No | Partial |
| IDE support | Excellent | None | Limited |
| Open/Closed | Yes | Requires modification | Requires modification |
| O(1) lookup | Yes (via HandlerRegistry) | No | No |
| Static analysis | Possible | No | Possible |

The attribute approach:
- **Declarative**: Intent mapping is visible at the class level without reading constructor or method internals
- **Discoverable**: Static analysis tools and IDEs can find all handlers for an intent
- **Extensible**: New handlers can be added without modifying Engine or existing handlers (Open/Closed Principle)
- **Container-integrated**: Works naturally with dependency injection and service providers

### Backward Compatibility

#### `agentwp_intent_handlers` Filter

**Support period:** Maintained through version 2.x
**Deprecation:** Version 2.0 (next major release)
**Removal:** Version 3.0

The filter remains functional for third-party integrations but is considered deprecated for core development:

```php
// Still works but deprecated - use #[HandlesIntent] instead
add_filter('agentwp_intent_handlers', function($handlers) {
    $handlers[] = new MyLegacyHandler();
    return $handlers;
});
```

Deprecation notices will be logged when the filter is used (controlled by `WP_DEBUG`).

#### `getIntent()` / `getSupportedIntents()` Methods

**Support period:** Maintained through version 2.x
**Deprecation:** Version 2.0
**Removal:** Version 3.0

The Engine will continue to check for these methods as a fallback when:
1. No `#[HandlesIntent]` attribute is present
2. Handler was added via filter without explicit intent registration

This enables gradual migration without breaking existing custom handlers.

### Deprecation Path

#### Phase 1: Soft Deprecation (Current - v2.0) ✅ IMPLEMENTED
- ✅ Document `#[HandlesIntent]` as the recommended approach
- ✅ Add `@deprecated` annotations to `getIntent()` in `BaseHandler`
- ✅ Log deprecation warnings when fallback discovery is used (debug mode only)
  - Warnings trigger via `_doing_it_wrong()` or `E_USER_DEPRECATED` in WP_DEBUG mode
  - Tracked per-handler to avoid duplicate warnings
  - Points developers to this ADR for migration instructions
- Update all core handlers to use attributes (infrastructure in place, handler migration pending)

#### Phase 2: Hard Deprecation (v2.0)
- Emit `E_USER_DEPRECATED` notices for:
  - Use of `agentwp_intent_handlers` filter
  - Handlers using `getIntent()` / `getSupportedIntents()` without attributes
- Add migration guide to release notes
- Provide automated migration tooling if feasible

#### Phase 3: Removal (v3.0)
- Remove `agentwp_intent_handlers` filter support from Engine
- Remove fallback method discovery in `Engine::get_handler_intents()`
- Remove `getIntent()` from `BaseHandler` (or make it final/private)
- Remove `$needs_fallback_lookup` flag and O(n) fallback loop

## Consequences

### Positive
- Single clear pattern for all new handler development
- Improved runtime performance with O(1) handler resolution
- Better tooling support (IDE navigation, static analysis)
- Cleaner Engine code after legacy removal
- Explicit intent-to-handler mapping visible in source

### Negative
- Third-party code using filters must migrate before v3.0
- Custom handlers using legacy methods need updates
- Breaking change in v3.0 for unmigrated code

### Neutral
- Requires PHP 8.0+ (already a project requirement)
- Adds dependency on container tagging mechanism

## Migration Checklist

### For Core Developers

- [ ] Add `#[HandlesIntent]` attribute to all core handlers (currently use constructor-based intent via `BaseHandler`)
- [x] Register all handlers with `intent.handler` tag in `IntentServiceProvider`
- [x] Implement `HandlerRegistry` for O(1) lookup
- [x] Implement `HandlesIntent` attribute class (`src/Intent/Attributes/HandlesIntent.php`)
- [x] Implement attribute detection in `Engine::get_handler_intents()`
- [x] Add deprecation logging for legacy discovery paths
- [ ] Update DEVELOPER.md with new handler registration guide
- [ ] Create migration script for custom handlers (optional tooling)

### For Third-Party Developers

1. **Add the attribute to your handler class:**
   ```php
   use AgentWP\Intent\Attributes\HandlesIntent;
   use AgentWP\Intent\Intent;

   #[HandlesIntent(Intent::MY_CUSTOM_INTENT)]
   class MyCustomHandler extends AbstractAgenticHandler {
       // ...
   }
   ```

2. **Register with container tag** (if using a service provider):
   ```php
   $container->singleton(MyCustomHandler::class, fn($c) => new MyCustomHandler(...));
   $container->tag(MyCustomHandler::class, 'intent.handler');
   ```

3. **Or use the filter with explicit registration** (transitional):
   ```php
   add_filter('agentwp_intent_handlers', function($handlers) {
       // Handler will be auto-detected via attribute
       $handlers[] = new MyCustomHandler();
       return $handlers;
   });
   ```

4. **Remove legacy methods** once migrated:
   - Delete `getIntent()` override if present
   - Delete `getSupportedIntents()` if present

### Verification

To verify a handler is correctly registered:

```php
// Check attribute presence
$reflection = new ReflectionClass(MyHandler::class);
$attributes = $reflection->getAttributes(HandlesIntent::class);
assert(!empty($attributes), 'Handler must have #[HandlesIntent] attribute');

// Check container registration
$handlers = $container->tagged('intent.handler');
assert(in_array(MyHandler::class, array_map('get_class', $handlers)));
```

## References

- [PHP 8 Attributes RFC](https://wiki.php.net/rfc/attributes_v2)
- ADR 0001: REST Controller Dependency Resolution
- `src/Intent/Attributes/HandlesIntent.php` - Attribute implementation
- `src/Intent/HandlerRegistry.php` - O(1) lookup implementation
- `src/Providers/IntentServiceProvider.php` - Container registration example
