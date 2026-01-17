# AgentWP Architecture Improvement Plan

**Date:** 2026-01-17  
**Status:** Draft  
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

### Phase 0 — Baseline + decisions (1–2 days)

- Write/refresh ADRs for:
  - Handler registration strategy (attributes vs legacy methods)
  - AI client architecture (monolithic vs modular components)
  - Rate limiting strategy (REST base vs injected service)
- Add a short “How the plugin boots” diagram to `docs/ARCHITECTURE.md` or a new ADR.

**Deliverables**
- 2–4 ADRs in `docs/adr/`
- Updated diagrams (Mermaid is fine)

### Phase 1 — Consolidation: remove ambiguity (1 sprint)

**Goal:** eliminate duplicate or misleading architecture paths.

- Bootstrap cleanup:
  - Deprecate/remove legacy wiring in `src/Plugin.php` that overlaps with provider-based wiring (menu/assets/rest hooks).
  - Remove unused imports (e.g., stale provider references).
  - Resolve references to missing classes (either implement `BulkHandler` or remove guarded references).
- Single source of truth for settings:
  - Consolidate option keys/defaults into `src/Plugin/SettingsManager.php` (or a dedicated config class) and reference from elsewhere.
  - Ensure demo-mode settings and key storage are handled consistently (decrypt/encrypt in one place).
- “Stranded subsystem” decisions:
  - For each unused subsystem (AI client components, scorer registry, order-search pipeline, rate limiter/retry), decide:
    1) integrate + delete legacy implementation, or
    2) remove the unused code to reduce maintenance.

**Acceptance criteria**
- There is exactly one recommended path for: REST wiring, settings access, intent classification, order search, HTTP/retry.
- No dead references to non-existent classes remain (even if guarded).

### Phase 2 — Make DI real (1 sprint)

**Goal:** make the container the single source of truth for runtime services.

- REST controllers:
  - Move `SettingsController` to resolve services from container (SettingsManager, Encryption, HttpClient/OpenAI validator, etc.).
  - Remove “fallback new” where it would produce broken behavior (e.g., `new Engine()` with no handlers).
- Intent wiring:
  - Register and inject `FunctionRegistry` and `HandlerRegistry` via container and pass them into `Engine`.
  - Register context providers via container tagging (e.g., `intent.context_provider`) and build `ContextBuilder` from tagged providers.
- Services:
  - Remove internal `new TransientDraftStorage()` fallbacks in services that are already container-provided; instead ensure the provider always wires them.

**Acceptance criteria**
- In production boot, `Engine` is always the provider-wired instance (handlers, registries, classifier, context, memory).
- Controllers do not reach into `get_option()/update_option()` for settings when SettingsManager exists.

### Phase 3 — Intent engine modernization (1–2 sprints)

**Goal:** standardize handler registration and classification.

- Handler registration:
  - Standardize on `#[HandlesIntent(...)]` and deprecate `getIntent()` / `getSupportedIntents()` usage.
  - Update `Engine` to rely on `HandlerRegistry` only (no O(n) fallback) after a deprecation window.
- Classification:
  - Replace `IntentClassifier`’s hardcoded scoring with `Intent\\Classifier\\ScorerRegistry`.
  - Expose a filter/action to register additional scorers.
  - Use `AgentWPConfig` weights/thresholds (or make them settings-driven with filters).
- Memory + context:
  - Make memory TTL/limit configurable (via SettingsManager/config/filters).
  - Formalize context schema passed to handlers (documented contract).

**Acceptance criteria**
- Adding a new handler is a documented, one-path process (attribute + provider/tag or filter).
- Classification is testable via unit tests against scorer inputs.

### Phase 4 — AI client refactor (1–2 sprints)

**Goal:** improve testability, consistency, and resilience of OpenAI integration.

- Decide whether to:
  1) Integrate the modular classes in `src/AI/Client/*` into `OpenAIClient`, or
  2) Remove them and keep a monolith (but then use infra abstractions).
- Refactor `OpenAIClient` to use:
  - `HttpClientInterface` for HTTP requests
  - `RetryExecutor` for retry/backoff
  - A shared response parser/stream parser
- Centralize OpenAI settings:
  - Base URL, timeouts, retry limits, and model selection should come from SettingsManager/config with filter overrides.
- Demo-mode support:
  - Define the expected behavior in demo mode (use a demo key vs stubbed responses) and implement it consistently in `AIClientFactory`.

**Acceptance criteria**
- OpenAI client behavior is unit-testable without WordPress HTTP globals.
- Retry/rate-limit behavior is consistent and centrally configured.

### Phase 5 — REST API layer polish (1 sprint)

**Goal:** make the API layer thin, uniform, and self-documenting.

- Replace static rate limiting in `RestController` with injected `RateLimiterInterface` (or remove the unused RateLimiter entirely).
- Consider request DTOs + validation helpers so controllers are mostly orchestration.
- Route registration:
  - Replace `RestRouteRegistrar::getDefaultControllers()` hard-coded list with tag-based registration (`rest.controller`) from the container.
  - Keep the current list as a fallback only if needed.
- OpenAPI maintenance:
  - Add a repeatable script to regenerate `docs/openapi.json` and validate that it matches controller annotations.

**Acceptance criteria**
- All controllers share the same patterns: auth + nonce + rate limit + validation + response envelope.
- API docs generation is deterministic and part of CI/dev workflow.

### Phase 6 — Domain services refactor (1–3 sprints, incremental)

**Goal:** align services with the contracts/infrastructure abstractions already present.

- Standardize service outputs:
  - Adopt `DTO\\ServiceResult` (or a similar standard) and migrate services gradually.
- Separate “policy” concerns:
  - Capability/permission checks should live in controllers or a dedicated policy layer, not scattered through services.
- Reduce direct WooCommerce/global calls in services:
  - Expand repository interfaces or infra adapters as needed.
- Draft/confirm patterns:
  - Ensure refund/status/stock flows use a consistent draft lifecycle, storage, expiry, and id generation.

**Acceptance criteria**
- Services are unit-testable without needing WooCommerce globals (via repositories/adapters).
- Draft/confirm flows share a consistent domain model and storage interface.

### Phase 7 — Search subsystem strategy (optional, 1–2 sprints)

**Goal:** decide whether search indexing stays static or becomes a service.

Options:
1) Keep `Search\\Index` static but isolate SQL and add stronger migration/version handling.
2) Convert into a container-managed service with explicit lifecycle hooks and test coverage.

**Acceptance criteria**
- Clear ownership of search index schema/migrations and backfill behavior.
- No unexpected DB work on every request (where avoidable).

### Phase 8 — Frontend/API alignment (ongoing)

- Generate TypeScript types from `docs/openapi.json` (or a curated subset) and use them in:
  - `react/src/api/AgentWPClient.ts`
  - Feature stores and hooks
- Add runtime validation (optional) for critical endpoints (settings/intent).
- Normalize error handling:
  - Keep frontend error-code mapping aligned with backend `AgentWPConfig` error codes.
- Reduce cross-store coupling:
  - Prefer React Query for server state and reserve Zustand for UI state when possible.

**Acceptance criteria**
- API changes fail fast in TypeScript during development.
- Frontend error states are consistent across features.

## 7) Work Breakdown by Epic (Actionable Checklist)

### Epic A — Remove duplicated bootstrap paths

- [ ] Audit `src/Plugin.php` for dead/overlapping responsibilities vs `src/Plugin/*`.
- [ ] Remove or deprecate unused methods and constants.
- [ ] Ensure activation/deactivation flows route through dedicated managers/services (Settings initialization, scheduled jobs, cleanup).

### Epic B — Settings and secret management

- [ ] Consolidate option keys/defaults into one module (prefer `SettingsManager`).
- [ ] Introduce a single “API key storage” service that handles: encrypt/decrypt/rotate + last4.
- [ ] Define demo-mode credential rules and implement them in `AIClientFactory`.

### Epic C — Intent wiring + handler registration

- [ ] Move to tag-based handler registration (`intent.handler`) with attribute-driven intent mapping.
- [ ] Ensure `Engine` uses injected registries (no internal defaults in production wiring).
- [ ] Add tests: handler resolution, registry contents, classification outcomes.

### Epic D — AI client unification

- [ ] Choose modular vs monolithic; delete the unused path.
- [ ] Use `HttpClientInterface` + `RetryExecutor`.
- [ ] Centralize retry/timeouts/base URL via config/settings.

### Epic E — REST consistency + docs

- [ ] Use one rate limiting approach (DI service or static helper, not both).
- [ ] Tag-based controller registration.
- [ ] Deterministic OpenAPI generation workflow.

### Epic F — Order search consolidation

- [ ] Decide whether `src/Services/OrderSearch/*` replaces `src/Services/OrderSearchService.php`.
- [ ] Wire the chosen implementation via `ServicesServiceProvider`.
- [ ] Remove the unused implementation after migration.

## 8) Risks and Mitigations

- **Risk: breaking extensions** (filters like `agentwp_intent_handlers`).
  - Mitigation: keep filters; implement deprecation windows; document changes in `docs/CHANGELOG.md`.
- **Risk: runtime regressions due to DI changes**.
  - Mitigation: add unit tests + smoke e2e tests for `/intent`, `/settings`, `/analytics`.
- **Risk: WordPress lifecycle edge cases** (container unavailable in unusual boot paths).
  - Mitigation: keep minimal defensive fallbacks, but ensure fallbacks are functionally correct.

## 9) Success Metrics (Practical)

- 0 occurrences of `new Engine()` or `new OpenAIClient()` in the REST layer.
- Provider-wired services are the ones executing in runtime requests.
- Reduced duplicate implementations (order search, classification, rate limiting, retry).
- Improved test coverage for intent routing, classification, and core REST endpoints.

