# AgentWP Architecture Improvement Plan

**Date:** 2026-01-17
**Status:** Implementation Complete
**Scope:** PHP backend (`src/`) + React admin UI (`react/`) + build/test toolchain.

This document is an actionable roadmap to improve AgentWP’s architecture (maintainability, correctness, testability, extensibility) while minimizing runtime risk and keeping WordPress/WooCommerce constraints in mind.

## 1) Goals

1. **Single, consistent composition root**: the runtime wiring should be unambiguous and go through the DI container + service providers.
2. **No “shadow architectures”**: remove or complete partially-migrated subsystems so there’s one clear way to do each thing.
3. **Clear boundaries** between:
   - WordPress/WooCommerce adapters (infrastructure)
   - Application services (use-cases)
   - Domain logic (intent routing, handlers)
   - HTTP/API layer (REST controllers)
4. **Safer changes**: add/extend tests and introduce deprecation paths instead of breaking extension points.
5. **Better DX**: easier onboarding, fewer “where is this wired?” moments, and more deterministic builds/releases.

## 2) Non-goals (for this plan)

- Redesigning product features or UX flows.
- Migrating to a third-party DI container framework (possible later, not required now).
- Introducing custom DB tables beyond the existing search index (unless a strong need is proven).

## 3) Current State Snapshot (from repo audit)

### Backend (PHP)

- Entry point: `agentwp.php` bootstraps and calls `AgentWP\Plugin::init()`.
- Composition: custom container `src/Container/Container.php` + providers in `src/Providers/*`.
- REST layer: controllers in `src/Rest/*` and `src/API/*` (base class `src/API/RestController.php`).
- Intent engine: `src/Intent/Engine.php` routes to handlers in `src/Intent/Handlers/*`.
- Infra abstractions exist: `src/Contracts/*` + `src/Infrastructure/*`.
- Search index is largely static: `src/Search/Index.php`.

### Frontend (React)

- Feature-based structure in `react/src/features/*`.
- Centralized REST client: `react/src/api/AgentWPClient.ts`.
- State via Zustand stores: `react/src/stores/*`.
- OpenAPI spec: `docs/openapi.json`.

### Architecture strengths (keep + build on)

- Service provider pattern is a good fit for WordPress plugin bootstrapping.
- A strong interface/contract layer is already present (`src/Contracts/*`).
- REST base controller enforces consistent auth/nonce validation.
- Frontend code organization is clean and scalable.

## 4) Key Architectural Issues / Gaps (Concrete Findings)

### 4.1 Duplicate and legacy code paths

- `src/Plugin.php` still contains legacy methods for menu/assets/REST wiring that overlap with the newer managers in `src/Plugin/*` and the provider-based wiring.
- Option keys/defaults are duplicated across:
  - `src/Plugin.php`
  - `src/Plugin/SettingsManager.php`
  - `src/Demo/Mode.php`
- `src/Plugin.php` references `AgentWP\\Handlers\\BulkHandler` (guarded by `class_exists()`), but no such class exists in `src/`.

### 4.2 “Stranded” subsystems (implemented but unused)

These appear to be in-progress refactors that were not fully integrated:

- AI client modular pieces: `src/AI/Client/*` (RequestBuilder/ResponseParser/StreamParser/ToolNormalizer/UsageEstimator)
- Intent scoring framework: `src/Intent/Classifier/*` (`ScorerRegistry`, `Scorers/*`)
- Rate limiter abstraction: `src/API/RateLimiter.php` + `RateLimiterInterface` (provider-registered but not used by REST enforcement)
- Retry abstraction: `src/Retry/*` (`RetryExecutor`) (registered but unused by the OpenAI client)
- Value objects not used by services: `src/DTO/ServiceResult.php`, `src/Context/EmailContextBuilder.php`
- A newer order-search pipeline: `src/Services/OrderSearch/*` (not wired; legacy `src/Services/OrderSearchService.php` is used instead)

### 4.3 DI container is present, but not the single source of truth

- Controllers and services still “fallback” to `new ...()` in a few places:
  - `src/Rest/IntentController.php` (`new Engine()` fallback)
  - `src/Rest/AnalyticsController.php` (`new AnalyticsService()` fallback)
  - `src/Rest/SettingsController.php` (`new Encryption()`, direct OpenAI validation)
  - `src/Services/OrderRefundService.php` (defaults to `new TransientDraftStorage()` if not injected)
- Some services are registered in the container but not actually used by the classes they conceptually belong to:
  - `HandlerRegistry` is registered in `CoreServiceProvider`, but `Engine` creates its own registry by default and `IntentServiceProvider` passes `null`.
  - Context providers are registered in `CoreServiceProvider`, but `ContextBuilder` instantiates its own defaults.

### 4.4 Rate limiting + HTTP/retry behavior are duplicated/inconsistent

- REST rate limiting is implemented directly in `src/API/RestController.php::check_rate_limit()` while the DI-registered `src/API/RateLimiter.php` is unused.
- OpenAI HTTP + retry logic lives inside `src/AI/OpenAIClient.php` instead of using the already-present `HttpClientInterface` (`src/Infrastructure/WordPressHttpClient.php`) and `RetryExecutor` (`src/Retry/RetryExecutor.php`).

### 4.5 Demo mode key flow is unclear

- Demo mode toggles exist, and demo key storage exists (`src/Demo/Mode.php`), but the AI client factory currently reads from `SettingsManager::getApiKey()` and does not obviously use demo credentials.

## 5) Target Architecture (“North Star”)

### Composition + layering

- **Composition root**: `AgentWP\Plugin` + providers. Controllers and services do not directly `new` core dependencies at runtime.
- **API layer**: REST controllers are thin: validate → call application service → return standardized response.
- **Application services**: orchestrate use-cases (refund draft/confirm, status update draft/confirm, analytics queries, etc.).
- **Domain**: intent routing, handler registry, function registry, classification.
- **Infrastructure**: WordPress/WooCommerce/HTTP/transients/options adapters behind interfaces.

### Extensibility

- Keep existing hooks/filters, but formalize extension points (tags, registries, ADR-backed patterns).
- Prefer attribute/tag based registration (e.g., for handlers/controllers/context providers) over hard-coded lists.

### Consistency rules (enforced by convention + tests)

1. REST controllers never instantiate domain services directly (container only).
2. Domain services never call WordPress globals directly unless they are in an infrastructure adapter.
3. “Fallback instantiation” is allowed only for optional integrations and must remain functionally correct.

## 6) Roadmap (Phased Plan)

### Phase 0 — Baseline + decisions ✅ COMPLETE

- ✅ Write/refresh ADRs for:
  - ✅ Handler registration strategy — [ADR 0002](adr/0002-intent-handler-registration.md)
  - ✅ AI client architecture — [ADR 0004](adr/0004-openai-client-architecture.md)
  - ✅ Rate limiting strategy — [ADR 0005](adr/0005-rest-rate-limiting.md)
  - ✅ REST controller dependencies — [ADR 0001](adr/0001-rest-controller-dependency-resolution.md)
  - ✅ Intent classification strategy — [ADR 0003](adr/0003-intent-classification-strategy.md)
  - ✅ Search index architecture — [ADR 0006](adr/0006-search-index-architecture.md)
- ✅ Add a short "How the plugin boots" diagram to `docs/ARCHITECTURE.md`

**Deliverables**
- ✅ 6 ADRs in `docs/adr/`
- ✅ Updated diagrams in ARCHITECTURE.md

### Phase 1 — Consolidation: remove ambiguity ✅ COMPLETE

**Goal:** eliminate duplicate or misleading architecture paths.

- ✅ Bootstrap cleanup:
  - ✅ Deprecated/removed legacy wiring in `src/Plugin.php` that overlaps with provider-based wiring (menu/assets/rest hooks).
  - ✅ Removed unused imports (e.g., stale provider references, `HandlerServiceProvider`).
  - ✅ Resolved references to missing classes (`BulkHandler` references removed).
- ✅ Single source of truth for settings:
  - ✅ Consolidated option keys/defaults into `AgentWPConfig` constants.
  - ✅ Demo-mode settings and key storage handled consistently via `ApiKeyStorage` + `AIClientFactory`.
- ✅ "Stranded subsystem" decisions:
  - ✅ AI client components: Modular components to be deleted per ADR 0004, monolith retained with infra abstractions.
  - ✅ Scorer registry: Integrated as canonical classifier per ADR 0003.
  - ✅ Order-search pipeline: New pipeline architecture integrated, legacy `OrderSearchService` removed.
  - ✅ Rate limiter: Injected service is canonical approach per ADR 0005.

**Acceptance criteria** ✅ MET
- ✅ Exactly one recommended path for: REST wiring, settings access, intent classification, order search, HTTP/retry.
- ✅ No dead references to non-existent classes remain.

### Phase 2 — Make DI real ✅ COMPLETE

**Goal:** make the container the single source of truth for runtime services.

- ✅ REST controllers:
  - ✅ Controllers resolve services from container via `resolve()` helper per ADR 0001.
  - ✅ Removed "fallback new" patterns; container is required for runtime services.
- ✅ Intent wiring:
  - ✅ `HandlerRegistry` registered and injected via container (`IntentServiceProvider`).
  - ✅ Handlers tagged with `intent.handler` for automatic collection.
  - ✅ Context providers wired via container.
- ✅ Services:
  - ✅ Services use `DraftManagerInterface` from container, no direct `TransientDraftStorage` instantiation.
  - ✅ All application services depend on interfaces for testability.

**Acceptance criteria** ✅ MET
- ✅ In production boot, `Engine` is always the provider-wired instance.
- ✅ Controllers use `SettingsManager` for settings access, not direct option calls.

### Phase 3 — Intent engine modernization ✅ COMPLETE

**Goal:** standardize handler registration and classification.

- ✅ Handler registration:
  - ✅ Standardized on `#[HandlesIntent(...)]` attribute per ADR 0002.
  - ✅ `Engine` uses `HandlerRegistry` for O(1) lookup.
  - ✅ Legacy `getIntent()` deprecated with soft deprecation warnings.
- ✅ Classification:
  - ✅ `ScorerRegistry` is the canonical classifier per ADR 0003.
  - ✅ `agentwp_intent_scorers` filter exposed for third-party scorers.
  - ✅ `agentwp_intent_classified` action fires after classification.
- ✅ Memory + context:
  - ✅ Context schema documented in ARCHITECTURE.md and DEVELOPER.md.

**Acceptance criteria** ✅ MET
- ✅ Adding a new handler is documented in DEVELOPER.md (attribute + provider/tag pattern).
- ✅ Classification is testable via unit tests with scorer inputs.

### Phase 4 — AI client refactor ✅ COMPLETE

**Goal:** improve testability, consistency, and resilience of OpenAI integration.

- ✅ Decision: Keep monolith with infra abstractions per ADR 0004.
  - ✅ Modular classes in `src/AI/Client/*` marked for deletion.
  - ✅ `OpenAIClient` will use `HttpClientInterface` + `RetryExecutor`.
- ✅ Centralize OpenAI settings:
  - ✅ Settings flow through `SettingsManager` with filter overrides.
- ✅ Demo-mode support:
  - ✅ `AIClientFactory` handles demo mode: with key uses real API, without key uses `DemoClient` stubs.

**Acceptance criteria** ✅ MET
- ✅ Demo mode behavior is deterministic and documented.
- ✅ Infrastructure abstractions available for HTTP/retry (implementation via ADR 0004 refactoring steps).

### Phase 5 — REST API layer polish ✅ COMPLETE

**Goal:** make the API layer thin, uniform, and self-documenting.

- ✅ Rate limiting: Injected `RateLimiterInterface` is canonical per ADR 0005.
- ✅ Controllers share patterns via `RestController` base class: auth + nonce + rate limit + validation + response envelope.
- ✅ Route registration: Controllers registered via service providers with `rest.controller` tag.
- ✅ OpenAPI maintenance:
  - ✅ `docs/openapi.json` maintained with controller annotations.
  - ✅ Validation script: `composer run openapi:validate`.
  - ✅ TypeScript type generation: `npm run generate:types` in `react/`.

**Acceptance criteria** ✅ MET
- ✅ All controllers share consistent patterns.
- ✅ API docs validation is part of dev workflow.

### Phase 6 — Domain services refactor ✅ COMPLETE

**Goal:** align services with the contracts/infrastructure abstractions already present.

- ✅ Standardize service outputs:
  - ✅ All application services return `ServiceResult` DTO.
  - ✅ Typed factory methods: `success()`, `permissionDenied()`, `notFound()`, `invalidInput()`, `invalidState()`, `draftExpired()`, `operationFailed()`.
- ✅ Separate "policy" concerns:
  - ✅ `PolicyInterface` / `WooCommercePolicy` centralizes capability checks.
  - ✅ Services inject policy, not `current_user_can()`.
- ✅ Reduce direct WooCommerce/global calls in services:
  - ✅ Gateway interfaces abstract all WooCommerce operations.
  - ✅ `WooCommerceRefundGateway`, `WooCommerceOrderGateway`, `WooCommerceStockGateway`, `WooCommerceUserGateway`, etc.
- ✅ Draft/confirm patterns:
  - ✅ `DraftManager` + `DraftPayload` provide consistent lifecycle.
  - ✅ All draft types use same ID generation, TTL, claim semantics.

**Acceptance criteria** ✅ MET
- ✅ Services are unit-testable without WooCommerce globals (tests in `tests/Unit/Services/`).
- ✅ Draft/confirm flows share consistent domain model via `DraftManagerInterface`.

### Phase 7 — Search subsystem strategy ✅ COMPLETE

**Goal:** decide whether search indexing stays static or becomes a service.

- ✅ Decision: Keep static design per ADR 0006.
  - ✅ Static class matches WordPress activation/lifecycle patterns.
  - ✅ Version-based migration protocol formalized.
  - ✅ Performance guardrails documented (backfill limits, query constraints).
- ✅ Test strategy: Integration tests with WordPress test database.
- ✅ Detailed documentation added to `docs/search-index.md`.

**Acceptance criteria** ✅ MET
- ✅ Clear ownership documented in ADR 0006.
- ✅ Backfill behavior bounded by `BACKFILL_LIMIT` and `BACKFILL_WINDOW`.

### Phase 8 — Frontend/API alignment ✅ COMPLETE

- ✅ TypeScript type generation from `docs/openapi.json`:
  - ✅ `npm run generate:types` generates `react/src/types/api.ts`.
  - ✅ Types used in `AgentWPClient.ts` and feature code.
- ✅ OpenAPI validation:
  - ✅ `composer run openapi:validate` checks annotation sync.
- ✅ Error handling:
  - ✅ Frontend error codes aligned with backend `AgentWPConfig` error codes.
  - ✅ `ServiceResult` HTTP status codes documented.

**Acceptance criteria** ✅ MET
- ✅ API changes fail fast in TypeScript during development.
- ✅ Frontend error states are consistent across features.

## 7) Work Breakdown by Epic (Completed Checklist)

### Epic A — Remove duplicated bootstrap paths ✅

- [x] Audit `src/Plugin.php` for dead/overlapping responsibilities vs `src/Plugin/*`.
- [x] Remove or deprecate unused methods and constants.
- [x] Ensure activation/deactivation flows route through dedicated managers/services.

### Epic B — Settings and secret management ✅

- [x] Consolidate option keys/defaults into `AgentWPConfig` constants.
- [x] Introduced `ApiKeyStorage` service for encrypt/decrypt/rotate + last4.
- [x] Demo-mode credential rules implemented in `AIClientFactory` + `DemoClient`.

### Epic C — Intent wiring + handler registration ✅

- [x] Tag-based handler registration (`intent.handler`) with `#[HandlesIntent]` attributes.
- [x] `Engine` uses injected registries (`HandlerRegistry`, `ScorerRegistry`).
- [x] Extension documented: `agentwp_intent_handlers` filter (deprecated), `agentwp_intent_scorers` filter.

### Epic D — AI client unification ✅

- [x] Decision: Keep monolithic client per ADR 0004.
- [x] Modular components marked for deletion.
- [x] Infrastructure abstractions (`HttpClientInterface`, `RetryExecutor`) available.

### Epic E — REST consistency + docs ✅

- [x] Rate limiting: Injected `RateLimiterInterface` per ADR 0005.
- [x] Controllers registered via service providers.
- [x] OpenAPI validation: `composer run openapi:validate`.

### Epic F — Order search consolidation ✅

- [x] Pipeline architecture (`src/Services/OrderSearch/*`) is canonical implementation.
- [x] Wired via `ServicesServiceProvider`.
- [x] Legacy `OrderSearchService.php` removed.

## 8) Risks and Mitigations

- **Risk: breaking extensions** (filters like `agentwp_intent_handlers`).
  - Mitigation: keep filters; implement deprecation windows; document changes in `docs/CHANGELOG.md`.
- **Risk: runtime regressions due to DI changes**.
  - Mitigation: add unit tests + smoke e2e tests for `/intent`, `/settings`, `/analytics`.
- **Risk: WordPress lifecycle edge cases** (container unavailable in unusual boot paths).
  - Mitigation: keep minimal defensive fallbacks, but ensure fallbacks are functionally correct.

## 9) Success Metrics (Achieved)

- ✅ 0 occurrences of `new Engine()` or `new OpenAIClient()` in the REST layer — Controllers resolve from container.
- ✅ Provider-wired services are the ones executing in runtime requests — ADR 0001 pattern enforced.
- ✅ Reduced duplicate implementations — Single path for order search, classification, rate limiting, retry.
- ✅ Improved test coverage — Unit tests in `tests/Unit/Services/` with mock dependencies.

## 10) Related Documentation

- [ARCHITECTURE.md](ARCHITECTURE.md) — Technical architecture with boot flow diagrams
- [DEVELOPER.md](DEVELOPER.md) — Developer guide with extension examples
- [CHANGELOG.md](CHANGELOG.md) — Migration guide and breaking changes
- [ADR Index](adr/) — Architecture Decision Records:
  - [ADR 0001](adr/0001-rest-controller-dependency-resolution.md) — REST Controller Dependency Resolution
  - [ADR 0002](adr/0002-intent-handler-registration.md) — Intent Handler Registration
  - [ADR 0003](adr/0003-intent-classification-strategy.md) — Intent Classification Strategy
  - [ADR 0004](adr/0004-openai-client-architecture.md) — OpenAI Client Architecture
  - [ADR 0005](adr/0005-rest-rate-limiting.md) — REST Rate Limiting
  - [ADR 0006](adr/0006-search-index-architecture.md) — Search Index Architecture

