# ADR 0004: OpenAI Client Architecture

**Date:** 2026-01-17
**Status:** Accepted

## Context

AgentWP's OpenAI integration currently exists in two forms:

1. **`OpenAIClient`** (Monolithic): A self-contained 750-line class in `src/AI/OpenAIClient.php` that handles HTTP requests, retry logic, response parsing, stream parsing, tool normalization, and usage estimation all internally. It uses WordPress HTTP functions directly (`wp_remote_post()`, `wp_remote_get()`).

2. **`src/AI/Client/*`** (Modular Components): A set of extracted, single-responsibility classes:
   - `RequestBuilder` - Builds chat completion payloads and HTTP arguments
   - `ResponseParser` - Parses non-streaming responses
   - `StreamParser` - Parses SSE streaming responses
   - `ParsedResponse` - Immutable DTO for parsed responses
   - `ToolNormalizer` - Normalizes function definitions to OpenAI tool format
   - `UsageEstimator` - Estimates token usage when not provided by API

**The modular components are currently unused.** Grep analysis confirms they have no imports outside their own directory. They appear to be an in-progress refactor that was never integrated.

Additionally, the codebase has infrastructure abstractions that `OpenAIClient` could use but doesn't:

- **`HttpClientInterface`** (`src/Contracts/HttpClientInterface.php`): Contract for HTTP operations, implemented by `WordPressHttpClient`.
- **`RetryExecutor`** (`src/Retry/RetryExecutor.php`): Generic retry infrastructure with `ExponentialBackoffPolicy` already configured for OpenAI.
- **`HttpResponse`** (`src/DTO/HttpResponse.php`): Immutable HTTP response DTO with retry-related helpers.

The current `OpenAIClient` duplicates functionality that exists in these abstractions:
- Retry logic with exponential backoff (lines 214-247)
- Retry-After header parsing (lines 473-509)
- HTTP error classification (lines 408-425)
- Response header normalization (lines 337-358)

This creates:
- **Untestable HTTP layer**: `OpenAIClient` cannot be unit-tested without mocking WordPress globals.
- **Inconsistent retry behavior**: `OpenAIClient` uses its own retry implementation instead of the centralized `RetryExecutor`.
- **Maintenance burden**: Two parallel implementations of the same concerns.
- **Unclear extension path**: Should new AI providers implement their own HTTP/retry, or use the shared infrastructure?

## Decision

### 1. Delete the Modular Components

The modular components in `src/AI/Client/*` will be **deleted**:
- `ToolNormalizer.php`
- `ParsedResponse.php`
- `UsageEstimator.php`
- `RequestBuilder.php`
- `ResponseParser.php`
- `StreamParser.php`

**Rationale:**
- They are unused and add maintenance overhead.
- The monolithic `OpenAIClient` already works and is battle-tested in production.
- Integrating them would require significant refactoring with minimal benefit.
- The real architectural improvement is using the infrastructure abstractions, not these intermediate components.

### 2. Refactor `OpenAIClient` to Use Infrastructure Abstractions

`OpenAIClient` will be refactored to use:

1. **`HttpClientInterface`** for all HTTP operations
2. **`RetryExecutor`** for retry logic
3. **`ExponentialBackoffPolicy::forOpenAI()`** for retry policy (already exists)

This achieves the testability and consistency goals without the complexity of the modular components.

### 3. Constructor Injection with Backward Compatibility

The refactored `OpenAIClient` will accept dependencies via constructor:

```php
public function __construct(
    string $apiKey,
    string $model = Model::GPT_4O_MINI,
    array $options = [],
    ?HttpClientInterface $httpClient = null,
    ?RetryPolicyInterface $retryPolicy = null,
    ?SleeperInterface $sleeper = null
) {
    // ... existing initialization ...

    // Use injected dependencies or create defaults for backward compatibility
    $this->httpClient = $httpClient ?? new WordPressHttpClient($this->timeout);
    $this->retryPolicy = $retryPolicy ?? ExponentialBackoffPolicy::forOpenAI();
    $this->sleeper = $sleeper ?? new RealSleeper();
    $this->retryExecutor = new RetryExecutor($this->retryPolicy, $this->sleeper);
}
```

**Backward compatibility:** Existing code that instantiates `OpenAIClient` without the new parameters will continue to work. The defaults create the same behavior as today.

### 4. Streaming Remains Internal

Streaming response parsing will remain internal to `OpenAIClient` because:
- It requires tight integration with the HTTP response handling
- The current implementation includes important DoS protections (max content length, max tool calls, max raw chunks)
- Streaming is OpenAI-specific and unlikely to be reused by other providers

### 5. Configuration via SettingsManager

All OpenAI configuration should flow through `SettingsManager` or be overridable via filters:

```php
// In AIClientFactory
$timeout = apply_filters('agentwp_openai_timeout', $settingsManager->getTimeout() ?? 60);
$maxRetries = apply_filters('agentwp_openai_max_retries', 3);
$baseUrl = apply_filters('agentwp_openai_base_url', 'https://api.openai.com/v1');
```

## Refactoring Steps

### Phase 1: Delete Unused Components (Immediate)

1. Delete `src/AI/Client/ToolNormalizer.php`
2. Delete `src/AI/Client/ParsedResponse.php`
3. Delete `src/AI/Client/UsageEstimator.php`
4. Delete `src/AI/Client/RequestBuilder.php`
5. Delete `src/AI/Client/ResponseParser.php`
6. Delete `src/AI/Client/StreamParser.php`
7. Delete `src/AI/Client/` directory

### Phase 2: Add Dependencies to OpenAIClient (1-2 days)

1. Add optional constructor parameters for `HttpClientInterface`, `RetryPolicyInterface`, `SleeperInterface`
2. Create private `RetryExecutor` instance from injected dependencies
3. Add getter methods for testing: `getHttpClient()`, `getRetryPolicy()`

### Phase 3: Replace Internal HTTP Calls (1-2 days)

1. Replace `wp_remote_post()` in `send_request()` with `$this->httpClient->post()`
2. Update `validateKey()` to use `$this->httpClient->get()`
3. Adapt response handling to use `HttpResponse` DTO
4. Remove `normalize_response_headers()` (handled by `WordPressHttpClient`)

### Phase 4: Replace Internal Retry Logic (1-2 days)

1. Replace `request_with_retry()` with `$this->retryExecutor->execute()`
2. Remove `sleep_with_backoff()` (handled by `RetryExecutor`)
3. Remove `is_retryable_status()` (handled by `ExponentialBackoffPolicy`)
4. Remove `is_retryable_error()` (handled by `ExponentialBackoffPolicy`)
5. Remove `parse_retry_after()` (handled by `HttpResponse::getRetryAfter()`)

### Phase 5: Update Service Provider (Half day)

1. Update `AIClientFactory` to inject dependencies from container
2. Ensure `InfrastructureServiceProvider` registers all required services
3. Add tests for container wiring

### Phase 6: Add Unit Tests (1-2 days)

1. Create `tests/Unit/AI/OpenAIClientTest.php` with injected `FakeHttpClient`
2. Test retry behavior with mock responses
3. Test streaming response parsing
4. Test error handling and edge cases

## Streaming Behavior

Streaming will continue to work via the `stream` option and `on_stream` callback:

```php
$client = new OpenAIClient($apiKey, $model, [
    'stream' => true,
    'on_stream' => function(array $chunk) {
        // Handle each SSE chunk
    },
]);
```

The internal `parse_stream_response()` method will remain unchanged. It provides critical protections:
- `MAX_STREAM_CONTENT_LENGTH` (1MB) - Prevents memory exhaustion
- `MAX_STREAM_TOOL_CALLS` (50) - Limits tool call accumulation
- `MAX_STREAM_RAW_CHUNKS` (100) - Caps raw chunk storage
- `MAX_TOOL_ARGUMENTS_LENGTH` (100KB) - Limits individual tool argument size

These constants and the parsing logic are specific to the OpenAI SSE format and should not be abstracted.

## Consequences

### Positive

- **Testable**: Unit tests can inject `FakeHttpClient` to test all code paths
- **Consistent**: Retry behavior matches other services using `RetryExecutor`
- **Maintainable**: HTTP and retry concerns have single implementations
- **Backward compatible**: Existing instantiation patterns continue to work
- **Cleaner codebase**: ~600 lines of unused code removed

### Negative

- **Migration effort**: Requires careful refactoring of working code
- **Risk during migration**: Must ensure streaming and retry behavior is preserved
- **Testing requirement**: Need comprehensive tests before refactoring

### Neutral

- **Performance**: No significant change (same underlying WordPress HTTP functions)
- **API surface**: Public interface of `OpenAIClient` remains unchanged

## Verification

After refactoring:

1. **Unit tests pass** with injected fake HTTP client
2. **Integration tests pass** against real OpenAI API (existing tests)
3. **Streaming works** with callback receiving chunks
4. **Retry behavior** is triggered on 429/5xx responses
5. **Timeout handling** respects configured timeouts
6. **Demo mode** continues to work via `AIClientFactory`

## References

- `src/AI/OpenAIClient.php` - Current monolithic implementation
- `src/AI/Client/*` - Unused modular components (to be deleted)
- `src/Contracts/HttpClientInterface.php` - HTTP contract
- `src/Infrastructure/WordPressHttpClient.php` - HTTP implementation
- `src/Retry/RetryExecutor.php` - Retry infrastructure
- `src/Retry/ExponentialBackoffPolicy.php` - Retry policy
- `src/DTO/HttpResponse.php` - HTTP response DTO
- ADR 0001: REST Controller Dependency Resolution (establishes DI patterns)
- `docs/ARCHITECTURE-IMPROVEMENT-PLAN.md` - Phase 4 references this decision
