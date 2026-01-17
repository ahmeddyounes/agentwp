# Architecture Inventory: Stranded Subsystems

**Date:** 2026-01-17
**Status:** Active
**Related:** `docs/ARCHITECTURE-IMPROVEMENT-PLAN.md`

This document inventories currently unused or partially integrated subsystems in the AgentWP codebase, with explicit decisions and owner tasks for each.

## Overview

During the architecture audit, several subsystems were identified as "stranded" - implemented but not integrated into the runtime wiring. This document provides:

1. A complete inventory of each subsystem
2. An explicit decision: **INTEGRATE**, **DELETE**, or **DEFER**
3. Owner task references for tracking

---

## Subsystem Inventory

### 1. AI Client Components

**Location:** `src/AI/Client/`
**Files:**
- `ParsedResponse.php` - Immutable DTO for parsed responses
- `StreamParser.php` - SSE streaming response parser
- `RequestBuilder.php` - Chat completion payload builder
- `ResponseParser.php` - Non-streaming response parser
- `ToolNormalizer.php` - Function-to-tool format converter
- `UsageEstimator.php` - Token usage estimator

**Status:** DEFINED BUT UNUSED
**Analysis:** These modular components duplicate functionality already present in the monolithic `OpenAIClient`. The `OpenAIClient` handles all HTTP requests, retry logic, response parsing, streaming, and tool normalization internally. These components have zero imports outside their own directory.

**Decision:** DELETE
**Rationale:** ADR 0004 explicitly decides to delete these components. The architectural improvement is achieved by refactoring `OpenAIClient` to use infrastructure abstractions (`HttpClientInterface`, `RetryExecutor`) rather than these intermediate components.

**Owner Task:** Phase 1 of ADR 0004 - Delete Unused Components
**Epic:** D - AI client unification

---

### 2. Scorer Registry

**Location:** `src/Intent/Classifier/`
**Files:**
- `ScorerRegistry.php` - Pluggable registry for intent scorers
- `IntentScorerInterface.php` - Scorer contract
- `AbstractScorer.php` - Base scorer with word-boundary matching
- `Scorers/*.php` - Individual scorer implementations (7 scorers)

**Status:** DEFINED BUT UNUSED BY RUNTIME
**Analysis:** A complete, well-designed pluggable classification system exists but is not wired. The `IntentClassifier` in `src/Intent/IntentClassifier.php` uses hardcoded inline scoring methods instead. The `ScorerRegistry` provides:
- Word-boundary regex matching (more precise than substring)
- DoS protection (MAX_INPUT_LENGTH)
- Deterministic tie-breaking (alphabetical)
- Testable in isolation (per-scorer tests)

**Decision:** INTEGRATE
**Rationale:** ADR 0003 explicitly designates `ScorerRegistry` as the canonical intent classification mechanism. It provides superior extensibility, precision, and testability compared to the legacy `IntentClassifier`.

**Owner Task:** Phase 3 of Architecture Improvement Plan - Intent engine modernization
**Epic:** C - Intent wiring + handler registration

**Migration Steps:**
1. Update `IntentServiceProvider` to register `ScorerRegistry` as `IntentClassifierInterface`
2. Add `agentwp_intent_scorers` filter for third-party extension
3. Deprecate legacy `IntentClassifier` (maintain through v2.x, remove in v3.0)

---

### 3. Rate Limiter Service

**Location:** `src/API/RateLimiter.php`, `src/Contracts/RateLimiterInterface.php`
**Related:** `tests/Fakes/FakeRateLimiter.php`

**Status:** REGISTERED BUT NOT USED BY REST LAYER
**Analysis:** A proper `RateLimiterInterface` service is registered in `RestServiceProvider` with atomic `checkAndIncrement()`, configurable limits, and a test double (`FakeRateLimiter`). However, `RestController::check_rate_limit()` uses static transient calls directly instead of the injected service.

**Decision:** INTEGRATE
**Rationale:** ADR 0005 explicitly mandates the injected `RateLimiterInterface` as the single supported rate limiting mechanism. The static method will be deprecated and removed.

**Owner Task:** Phase 5 of Architecture Improvement Plan - REST API layer polish
**Epic:** E - REST consistency + docs

**Migration Steps:**
1. Replace static `check_rate_limit()` calls with container-resolved `RateLimiterInterface`
2. Mark `RestController::check_rate_limit()` as deprecated
3. Remove static method in next major version

---

### 4. Retry Executor

**Location:** `src/Retry/`
**Files:**
- `RetryExecutor.php` - Generic retry infrastructure
- `RetryPolicyInterface.php` - Policy contract
- `ExponentialBackoffPolicy.php` - Backoff policy with OpenAI preset

**Status:** REGISTERED BUT UNUSED
**Analysis:** `RetryExecutor` is registered in `InfrastructureServiceProvider` as a singleton. `ExponentialBackoffPolicy::forOpenAI()` provides ready-to-use configuration. However, `OpenAIClient` implements its own retry logic internally (lines 214-247) instead of using this infrastructure.

**Decision:** INTEGRATE
**Rationale:** ADR 0004 mandates refactoring `OpenAIClient` to use `RetryExecutor` for consistency and testability. This eliminates duplicate retry implementations and enables unit testing without mocking WordPress HTTP globals.

**Owner Task:** Phase 4 of Architecture Improvement Plan - AI client refactor
**Epic:** D - AI client unification

**Migration Steps:**
1. Add `RetryExecutor` as optional constructor dependency to `OpenAIClient`
2. Replace internal `request_with_retry()` with `$this->retryExecutor->execute()`
3. Remove redundant internal methods: `sleep_with_backoff()`, `is_retryable_status()`, `is_retryable_error()`

---

### 5. ServiceResult DTO

**Location:** `src/DTO/ServiceResult.php`

**Status:** DEFINED BUT UNUSED
**Analysis:** A complete DTO with factory methods for service operation outcomes:
- `success($data, $message)`
- `failure($message, $code)`
- `error($message, $code)`
- `notFound($message)`
- `forbidden($message)`
- `validationError($message, $errors)`
- `serverError($message)`

Zero imports in the codebase. Services currently return raw arrays or throw exceptions.

**Decision:** INTEGRATE LATER (DEFER)
**Rationale:** Standardizing service outputs is valuable but lower priority than core wiring fixes. Adopt incrementally during Phase 6 (Domain services refactor) as services are touched.

**Owner Task:** Phase 6 of Architecture Improvement Plan - Domain services refactor
**Epic:** F (implied) - Service output standardization

**Migration Steps:**
1. Start with new services using `ServiceResult`
2. Migrate existing services during refactoring
3. Update controllers to handle `ServiceResult` response pattern

---

### 6. EmailContextBuilder

**Location:** `src/Context/EmailContextBuilder.php`

**Status:** DEFINED BUT UNUSED
**Analysis:** A comprehensive builder (~1000 lines) for constructing email drafting context with:
- Order details, line items, totals
- Customer information
- Shipping and tracking data
- Payment information
- Issue detection (late shipment, partial refund, etc.)

The simpler `ContextBuilder` class is used instead. `EmailContextBuilder` has zero imports in the codebase.

**Decision:** INTEGRATE LATER (DEFER)
**Rationale:** This builder provides rich context for AI-assisted email drafting. Integration depends on the email handler feature maturity. Keep for future integration when email drafting becomes a priority feature.

**Owner Task:** Future feature work - Email drafting enhancement
**Epic:** Not yet scheduled

**Migration Steps:**
1. Wire `EmailContextBuilder` in `CoreServiceProvider` or `IntentServiceProvider`
2. Update email-related handlers to use the rich context
3. Consider merging valuable extraction logic into `ContextBuilder` if overlap exists

---

### 7. OrderSearch Pipeline

**Location:** `src/Services/OrderSearch/`
**Files:**
- `OrderQueryService.php` - Query execution with caching
- `OrderFormatter.php` - Order result formatting
- `ArgumentNormalizer.php` - Search argument normalization
- `OrderSearchParser.php` - Natural language parsing
- `DateRangeParser.php` - Date range extraction

**Status:** DEFINED BUT UNUSED
**Analysis:** A modular, testable order search pipeline exists but is not wired. The monolithic `OrderSearchService` (in `src/Services/OrderSearchService.php`) is registered in `ServicesServiceProvider` instead. The pipeline components use:
- Repository interfaces (`OrderRepositoryInterface`)
- DTOs (`OrderDTO`, `OrderQuery`)
- Caching interfaces (`CacheInterface`, `TransientCacheInterface`)

**Decision:** INTEGRATE
**Rationale:** The modular pipeline is more testable and follows the established DI patterns. It should replace the monolithic service.

**Owner Task:** Phase 6 of Architecture Improvement Plan or dedicated Epic F
**Epic:** F - Order search consolidation

**Migration Steps:**
1. Wire `OrderQueryService` (with dependencies) in `ServicesServiceProvider`
2. Update `OrderSearchHandler` to use the new pipeline
3. Deprecate and remove `src/Services/OrderSearchService.php`
4. Add unit tests for pipeline components

---

## Summary Table

| Subsystem | Location | Decision | Priority | Owner Epic |
|-----------|----------|----------|----------|------------|
| AI Client Components | `src/AI/Client/` | DELETE | High | D |
| Scorer Registry | `src/Intent/Classifier/` | INTEGRATE | High | C |
| Rate Limiter Service | `src/API/RateLimiter.php` | INTEGRATE | Medium | E |
| Retry Executor | `src/Retry/` | INTEGRATE | Medium | D |
| ServiceResult DTO | `src/DTO/ServiceResult.php` | DEFER | Low | F |
| EmailContextBuilder | `src/Context/EmailContextBuilder.php` | DEFER | Low | Future |
| OrderSearch Pipeline | `src/Services/OrderSearch/` | INTEGRATE | Medium | F |

## Decision Legend

- **DELETE**: Remove the code; it provides no value over existing implementations
- **INTEGRATE**: Wire into runtime; replaces or supplements existing implementation
- **DEFER**: Keep for future integration; no immediate action required

## Related Documents

- [ADR 0003: Intent Classification Strategy](adr/0003-intent-classification-strategy.md) - ScorerRegistry decision
- [ADR 0004: OpenAI Client Architecture](adr/0004-openai-client-architecture.md) - AI client components decision
- [ADR 0005: REST Rate Limiting Approach](adr/0005-rest-rate-limiting.md) - Rate limiter decision
- [Architecture Improvement Plan](ARCHITECTURE-IMPROVEMENT-PLAN.md) - Roadmap and epics

## Changelog

| Date | Change |
|------|--------|
| 2026-01-17 | Initial inventory created |
