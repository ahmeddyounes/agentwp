# Architecture Inventory: Subsystem Integration Status

**Date:** 2026-01-17 (Updated: 2026-01-18)
**Status:** Completed
**Related:** `docs/ARCHITECTURE-IMPROVEMENT-PLAN.md`

This document tracks subsystems that were previously identified as "stranded" - implemented but not integrated into the runtime wiring. All decisions have now been executed.

## Overview

During the architecture audit (2026-01-17), several subsystems were identified as needing integration or deletion. This document has been updated to reflect the current state after all decisions have been implemented.

---

## Subsystem Status (Current)

### 1. AI Client Components

**Original Location:** `src/AI/Client/`
**Original Decision:** DELETE
**Current Status:** ✅ **COMPLETED** - Files deleted

The modular components (`ParsedResponse.php`, `StreamParser.php`, `RequestBuilder.php`, `ResponseParser.php`, `ToolNormalizer.php`, `UsageEstimator.php`) have been removed. The `OpenAIClient` now uses infrastructure abstractions (`HttpClientInterface`, `RetryExecutor`) as specified in ADR 0004.

---

### 2. Scorer Registry

**Location:** `src/Intent/Classifier/`
**Files:**
- `ScorerRegistry.php` - Pluggable registry for intent scorers
- `IntentScorerInterface.php` - Scorer contract
- `AbstractScorer.php` - Base scorer with word-boundary matching
- `Scorers/*.php` - Individual scorer implementations (7 scorers)

**Original Decision:** INTEGRATE
**Current Status:** ✅ **COMPLETED** - Integrated in runtime

The `ScorerRegistry` is now registered as `IntentClassifierInterface` in `src/Providers/IntentServiceProvider.php:156-195`. The implementation includes:
- All 7 default scorers (RefundScorer, StatusScorer, StockScorer, EmailScorer, AnalyticsScorer, CustomerScorer, SearchScorer)
- `agentwp_intent_scorers` filter for third-party extension
- Proper WPFunctions injection for testability

---

### 3. Rate Limiter Service

**Location:** `src/API/RateLimiter.php`, `src/Contracts/RateLimiterInterface.php`
**Related:** `tests/Fakes/FakeRateLimiter.php`

**Original Decision:** INTEGRATE
**Current Status:** ✅ **COMPLETED** - Integrated in runtime

The `RateLimiterInterface` is now used by `RestController::check_rate_limit_via_service()` (line 147) which resolves the service from the container. The implementation includes:
- Atomic `check()` and `increment()` methods
- `getRetryAfter()` for Retry-After header support
- `FakeRateLimiter` test double for testing

---

### 4. Retry Executor

**Location:** `src/Retry/`
**Files:**
- `RetryExecutor.php` - Generic retry infrastructure
- `ExponentialBackoffPolicy.php` - Backoff policy with OpenAI preset
- `RetryExhaustedException.php` - Exception for exhausted retries

**Contract Location:** `src/Contracts/RetryPolicyInterface.php`

**Original Decision:** INTEGRATE
**Current Status:** ✅ **COMPLETED** - Integrated in runtime

The `OpenAIClient` now uses `RetryExecutor` internally (see `src/AI/OpenAIClient.php:100-130`). The implementation includes:
- `buildRetryExecutor()` method that creates the executor with injected or default dependencies
- Configuration via `AgentWPConfig` for retry settings
- `onRetry` callback for logging retry attempts
- `executeWithCheck()` for success-based retry evaluation

---

### 5. ServiceResult DTO

**Location:** `src/DTO/ServiceResult.php`

**Original Decision:** DEFER (integrate incrementally)
**Current Status:** ✅ **COMPLETED** - Widely adopted

The `ServiceResult` DTO is now used extensively across the codebase (34+ files), including:
- All domain services: `OrderRefundService`, `OrderStatusService`, `ProductStockService`, `CustomerService`, `AnalyticsService`, `EmailDraftService`
- Service interfaces: `OrderSearchServiceInterface`, `CustomerServiceInterface`, `AnalyticsServiceInterface`, etc.
- Pipeline services: `PipelineOrderSearchService`, `OrderQueryService`
- Test fakes and unit tests

---

### 6. EmailContextBuilder

**Original Location:** `src/Context/EmailContextBuilder.php`
**Original Decision:** DEFER
**Current Status:** ✅ **COMPLETED** - Deleted

The `EmailContextBuilder` has been removed. The standard `ContextBuilder` is sufficient for current email drafting needs. If richer context is needed in the future, it can be rebuilt based on actual requirements.

---

### 7. OrderSearch Pipeline

**Location:** `src/Services/OrderSearch/`
**Files:**
- `PipelineOrderSearchService.php` - Adapter implementing `OrderSearchServiceInterface`
- `OrderQueryService.php` - Query execution with caching
- `OrderFormatter.php` - Order result formatting
- `ArgumentNormalizer.php` - Search argument normalization
- `OrderSearchParser.php` - Natural language parsing
- `DateRangeParser.php` - Date range extraction

**Original Decision:** INTEGRATE
**Current Status:** ✅ **COMPLETED** - Integrated in runtime

The pipeline is now wired in `src/Providers/ServicesServiceProvider.php:122-196`. The implementation includes:
- Full DI wiring of all pipeline components
- `PipelineOrderSearchService` registered as `OrderSearchServiceInterface`
- Fallback stub when WooCommerce is unavailable
- The old monolithic `OrderSearchService.php` has been deleted

---

## Summary Table

| Subsystem | Original Decision | Current Status | Implementation Location |
|-----------|------------------|----------------|------------------------|
| AI Client Components | DELETE | ✅ Deleted | N/A |
| Scorer Registry | INTEGRATE | ✅ Integrated | `IntentServiceProvider.php:156-195` |
| Rate Limiter Service | INTEGRATE | ✅ Integrated | `RestController.php:147-173` |
| Retry Executor | INTEGRATE | ✅ Integrated | `OpenAIClient.php:100-130` |
| ServiceResult DTO | DEFER | ✅ Adopted | 34+ files |
| EmailContextBuilder | DEFER | ✅ Deleted | N/A |
| OrderSearch Pipeline | INTEGRATE | ✅ Integrated | `ServicesServiceProvider.php:122-196` |

## Related Documents

- [ADR 0003: Intent Classification Strategy](adr/0003-intent-classification-strategy.md) - ScorerRegistry decision
- [ADR 0004: OpenAI Client Architecture](adr/0004-openai-client-architecture.md) - AI client components decision
- [ADR 0005: REST Rate Limiting Approach](adr/0005-rest-rate-limiting.md) - Rate limiter decision
- [Architecture Improvement Plan](ARCHITECTURE-IMPROVEMENT-PLAN.md) - Roadmap and epics

## Changelog

| Date | Change |
|------|--------|
| 2026-01-17 | Initial inventory created |
| 2026-01-18 | Updated to reflect completion of all integration/deletion decisions |
