# ADR 0005: REST Rate Limiting Approach

**Date:** 2026-01-17
**Status:** Accepted

## Context

AgentWP implements rate limiting to protect the REST API from abuse and ensure fair resource allocation. Currently, two rate limiting mechanisms exist:

1. **Static `RestController::check_rate_limit()`**: A static method in the base controller that directly uses WordPress transients (`get_transient()`, `set_transient()`). This approach:
   - Cannot be mocked or replaced in tests
   - Uses separate check-then-increment operations (race condition susceptible)
   - Hardcodes transient key format (`agentwp_rate_{user_id}`)
   - Duplicates transient logic that exists in `TransientCacheInterface`

2. **Injected `RateLimiterInterface`**: A proper service registered in `RestServiceProvider` with:
   - `RateLimiter` implementation using `TransientCacheInterface` and `ClockInterface`
   - `FakeRateLimiter` for testing with time manipulation support
   - Atomic `checkAndIncrement()` method with lock-based concurrency control
   - Configurable limits, windows, and key prefixes

The coexistence of both approaches creates ambiguity about which to use and maintains duplicate code paths.

## Decision

**The single supported rate limiting mechanism is the injected `RateLimiterInterface` resolved from the container.**

### Approach

Controllers check rate limits by resolving `RateLimiterInterface` from the container and calling its methods:

```php
// In RestController::permissions_check()
$rateLimiter = $this->resolve(RateLimiterInterface::class);
if ($rateLimiter instanceof RateLimiterInterface) {
    $userId = get_current_user_id();
    if (!$rateLimiter->check($userId)) {
        $retryAfter = $rateLimiter->getRetryAfter($userId);
        return new WP_Error(
            AgentWPConfig::ERROR_CODE_RATE_LIMITED,
            __('Rate limit exceeded. Please retry later.', 'agentwp'),
            [
                'status' => 429,
                'retry_after' => $retryAfter,
            ]
        );
    }
    $rateLimiter->increment($userId);
}
```

**Note:** The concrete `RateLimiter` class also provides an atomic `checkAndIncrement()` method with lock-based concurrency control for high-throughput scenarios. Controllers may type-hint against the concrete class or cast to access this method when atomicity is required.

### Why This Approach

| Criteria | Injected `RateLimiterInterface` | Static `check_rate_limit()` |
|----------|--------------------------------|----------------------------|
| Testable | Yes (swap with `FakeRateLimiter`) | No (uses globals) |
| Mockable | Yes | No |
| Thread-safe | Yes (concrete `RateLimiter` has atomic `checkAndIncrement()`) | No (check-then-increment race) |
| Configurable | Yes (limit, window, prefix) | No (hardcoded constants) |
| Container-integrated | Yes | No |
| Follows ADR 0001 | Yes | No |

The injected approach:
- **Aligns with ADR 0001**: Controllers resolve dependencies from the container
- **Enables testing**: `FakeRateLimiter` provides time manipulation and count inspection
- **Prevents race conditions**: Concrete `RateLimiter::checkAndIncrement()` uses transient-based locking
- **Supports configuration**: Limits and windows can be configured per-environment

### Retry-After Behavior

When rate limit is exceeded, the response includes:

1. **HTTP Status**: `429 Too Many Requests`
2. **Error Code**: `AgentWPConfig::ERROR_CODE_RATE_LIMITED` (`rate_limited`)
3. **Retry-After**: Seconds until the rate limit window resets

The `Retry-After` value is calculated as:

```php
$retryAfter = max(1, $window - ($now - $bucketStart));
```

Where:
- `$window` is the configured window duration (default: 60 seconds)
- `$now` is the current timestamp
- `$bucketStart` is when the current window started

The frontend should use this value to display a countdown or disable retry buttons.

### Storage Keys

Rate limit state is stored in WordPress transients with the following key format:

```
{prefix}{user_id}
```

- **Prefix**: Configurable, defaults to `rate_` (full transient key: `agentwp_rate_{user_id}`)
- **User ID**: WordPress user ID from `get_current_user_id()`

The stored value is an array:

```php
[
    'start' => (int) $windowStartTimestamp,
    'count' => (int) $requestCount,
]
```

**Lock keys** for atomic operations:

```
{prefix}{user_id}_lock
```

Lock timeout is 5 seconds with up to 10 acquisition attempts.

### Default Configuration

| Setting | Value | Constant/Location |
|---------|-------|-------------------|
| Limit | 30 requests | `RateLimiter::DEFAULT_LIMIT` |
| Window | 60 seconds | `RateLimiter::DEFAULT_WINDOW` |
| Lock timeout | 5 seconds | `RateLimiter::LOCK_TIMEOUT` |
| Max lock attempts | 10 | `RateLimiter::MAX_LOCK_ATTEMPTS` |

### Testing Strategy

#### Unit Tests

Tests use `FakeRateLimiter` which provides:

```php
// Time manipulation
$rateLimiter->setCurrentTime(1000);
$rateLimiter->advanceTime(30);

// State inspection
$rateLimiter->getCount($userId);
$rateLimiter->getRemaining($userId);

// State manipulation
$rateLimiter->exhaust($userId);  // Hit the limit
$rateLimiter->reset($userId);    // Clear limit
$rateLimiter->disable();         // Bypass all checks
```

Example test:

```php
public function test_rate_limit_exceeded_returns_429(): void {
    $rateLimiter = new FakeRateLimiter(limit: 5, window: 60);
    $rateLimiter->exhaust($userId);

    $this->container->singleton(
        RateLimiterInterface::class,
        fn() => $rateLimiter
    );

    $response = $this->controller->permissions_check($request);

    $this->assertInstanceOf(WP_Error::class, $response);
    $this->assertEquals('rate_limited', $response->get_error_code());
    $this->assertEquals(429, $response->get_error_data()['status']);
    $this->assertArrayHasKey('retry_after', $response->get_error_data());
}
```

#### Integration Tests

Integration tests verify the full flow with `RateLimiter`:

```php
public function test_rate_limit_with_real_transients(): void {
    $cache = new TransientCache();
    $clock = new SystemClock();
    $rateLimiter = new RateLimiter($cache, $clock, limit: 2, window: 60);

    // First two requests succeed (using atomic checkAndIncrement)
    $this->assertTrue($rateLimiter->checkAndIncrement(1));
    $this->assertTrue($rateLimiter->checkAndIncrement(1));

    // Third request fails
    $this->assertFalse($rateLimiter->checkAndIncrement(1));
    $this->assertGreaterThan(0, $rateLimiter->getRetryAfter(1));
}
```

### Migration Path

#### Phase 1: Update `permissions_check()` (This ADR)

Replace the static `check_rate_limit()` call with container-resolved `RateLimiterInterface`:

```php
// Before
$rate_error = self::check_rate_limit($request);

// After
$rate_error = $this->check_rate_limit_via_service();
```

Where `check_rate_limit_via_service()` resolves from container with fallback.

#### Phase 2: Deprecate Static Method

Mark `RestController::check_rate_limit()` as deprecated:

```php
/**
 * @deprecated Use RateLimiterInterface from container instead.
 */
public static function check_rate_limit($request) {
    // ...
}
```

#### Phase 3: Remove Static Method

Remove `RestController::check_rate_limit()` and `RATE_LIMIT`/`RATE_WINDOW` constants.

## Consequences

### Positive

- **Testable**: Rate limiting can be fully tested without touching transients
- **Consistent**: Follows the DI pattern established in ADR 0001
- **Safe**: Atomic `checkAndIncrement()` prevents race conditions
- **Flexible**: Limits, windows, and prefixes are configurable
- **Observable**: `FakeRateLimiter` allows inspection of internal state in tests

### Negative

- **Container dependency**: Rate limiting requires container to be available
- **Migration effort**: Existing code must be updated to use the service
- **Fallback complexity**: Need graceful handling when container is unavailable

### Neutral

- **Performance**: Minimal overhead from container resolution (singleton)
- **Storage**: Same underlying transient storage mechanism

## References

- ADR 0001: REST Controller Dependency Resolution
- `src/Contracts/RateLimiterInterface.php` - Contract definition
- `src/API/RateLimiter.php` - Production implementation
- `tests/Fakes/FakeRateLimiter.php` - Test double
- `src/Providers/RestServiceProvider.php` - Container registration
- `src/API/RestController.php` - Current static implementation (to be replaced)
