# AgentWP Architecture Improvement Plan

**Last updated:** 2026-01-18  
**Status:** Draft (living document)  
**Scope:** PHP plugin architecture + React admin UI integration + build/test/documentation hygiene  

This plan focuses on improving maintainability, extensibility, and testability while preserving WordPress/WooCommerce conventions and existing public behavior.

---

## Goals

1. **Make boundaries obvious and enforceable**
   - Presentation (REST/UI) vs application (intent/handlers) vs domain services vs infrastructure.
   - Reduce “mixed responsibility” classes and “mystery wiring”.

2. **Reduce accidental complexity**
   - Prefer consistent patterns (DI/container resolution, DTO validation, ServiceResult, config).
   - Remove dead/duplicate code and consolidate drifted subsystems.

3. **Improve extension safety**
   - Keep extension points stable and documented (`docs/EXTENSIONS.md`).
   - Introduce deprecation paths for “legacy” internals (per ADRs).

4. **Make the system easier to operate**
   - Clear operational knobs and sane defaults (`docs/OPERATIONAL-KNOBS.md`).
   - Better diagnostics without leaking secrets.

## Non-goals

- Rewriting the plugin into a different framework.
- Replacing WordPress/WooCommerce primitives with a full external runtime.
- Large-scale refactors without clear benefit or test coverage.

---

## Current Architecture Snapshot (as of 2026-01-18)

### Backend composition

- **Entrypoint:** `agentwp.php`
  - Loads Composer autoloader (fallback PSR-4 loader)
  - Runs compatibility checks (`src/Compatibility/Environment.php`)
  - Runs version upgrades early (`src/Plugin/Upgrader.php`)
  - Boots `Plugin::init()` (`src/Plugin.php`)

- **Composition root:** `src/Plugin.php`
  - Creates the DI container (`src/Container/Container.php`)
  - Registers + boots service providers (`src/Providers/*`)
  - Initializes static subsystems (usage tracking, search index, demo mode)

- **Service providers:** `src/Providers/*`
  - `CoreServiceProvider`: settings/theme/menu/assets, context providers
  - `InfrastructureServiceProvider`: caches, HTTP client, clock, retry, gateways, policy, logging
  - `ServicesServiceProvider`: domain services (refund/status/stock/search/analytics/customer/email)
  - `RestServiceProvider`: REST controllers + response formatting + rate limiter wiring
  - `IntentServiceProvider`: intent engine/handlers/tool registry/classifier/memory

### Runtime request flow

- **REST:** base controller `src/API/RestController.php`, controllers in:
  - `src/Rest/*Controller.php` (most endpoints)
  - `src/API/*Controller.php` (history/theme endpoints)
- **Responses:** normalized via `src/Plugin/ResponseFormatter.php`
- **Request validation:** DTOs under `src/DTO/*RequestDTO.php` (ADR 0007)

### Intent system

- **Engine:** `src/Intent/Engine.php`
- **Handler registration:** `#[HandlesIntent]` + container tag `intent.handler` (ADR 0002)
- **Classification:** `src/Intent/Classifier/ScorerRegistry.php` bound to `IntentClassifierInterface` (ADR 0003)
- **AI tools:** schemas under `src/AI/Functions/*` and registry `src/Intent/ToolRegistry.php`

### Static subsystems

- **Search index:** `src/Search/Index.php` (kept static by ADR 0006)
- **Usage tracking:** `src/Billing/UsageTracker.php` (static) wrapped by `src/Infrastructure/UsageTrackerAdapter.php`
- **Demo mode:** `src/Demo/*` (static orchestrators)

### Frontend

- Vite React app under `react/`
- Built assets in `assets/build/` loaded by `src/Plugin/AssetManager.php`
- Frontend API client: `react/src/api/AgentWPClient.ts`

---

## Key Issues / Opportunities

### 1) REST layer naming and directory split

Symptoms:
- REST controllers are split between `src/Rest/*` and `src/API/*` while the base controller is `src/API/RestController.php`.
- Architecture tests primarily scan `src/Rest` controllers, so `src/API/*Controller.php` is easier to miss in enforcement.

Impact:
- Harder onboarding (“where do controllers live?”).
- Boundary enforcement gaps.
- Higher chance of drift (new controllers added in the “wrong” place).

### 2) Rate limiting atomicity is not expressible in the contract

Symptoms:
- `src/Infrastructure/RateLimiting/RateLimiter.php` supports atomic `checkAndIncrement()`.
- `src/Contracts/RateLimiterInterface.php` does not, so `src/Rest/RestController.php` can only do `check()` then `increment()` (race-prone).

Impact:
- Under concurrency, requests can exceed limits more easily.
- ADR 0005 intent is partially met, but not end-to-end.

### 3) Global WordPress calls still leak into “application” code

Examples:
- `src/Intent/Engine.php` uses `apply_filters()` / `do_action()` directly instead of `src/Infrastructure/WPFunctions.php`.
- Several services call `get_current_user_id()` directly (not currently forbidden by tests, but it couples service layer to WP runtime).

Impact:
- Harder unit testing and mocking.
- Mixed patterns make codebase harder to reason about.

### 4) Tool schema + tool execution are not a first-class “module”

Symptoms:
- Tool schemas exist (`src/AI/Functions/*`), and handlers implement `execute_tool()` manually.
- No central dispatch layer for tools → duplicated argument mapping/validation per handler.

Impact:
- More work to add tools safely.
- Higher chance of subtle inconsistencies across handlers.

### 5) Documentation drift (architecture references and inventories)

Symptoms:
- `docs/ARCHITECTURE.md` links to this plan file (previously missing).
- `docs/architecture-inventory.md` appears out-of-sync with current code paths and should be refreshed or retired.

Impact:
- Architecture docs become untrusted.

---

## Roadmap (phased)

Each phase should be implemented behind tests and with explicit backwards-compat notes.

### Phase 0 — Documentation + invariants (1–2 days)

Deliverables:
- This plan exists and becomes the canonical roadmap referenced by `docs/ARCHITECTURE.md`.
- Architecture docs and inventories match reality.

Tasks:
- Refresh or retire `docs/architecture-inventory.md` to reflect current code (or move to `docs/archive/`).
- Add a short “Where things live” section to `docs/ARCHITECTURE.md` pointing to:
  - REST controllers directory
  - Intent handlers directory
  - Services/infrastructure boundaries
- Ensure ADR statuses are reflected in docs (what is implemented vs future).

Acceptance criteria:
- Links in `docs/ARCHITECTURE.md` and ADRs resolve to real files.
- No “stranded subsystem” docs contradict current runtime wiring.

### Phase 1 — REST layer consolidation (2–4 days)

Goal: Make REST architecture obvious and enforceable.

Tasks:
- Choose a single home for REST controllers (recommended: `src/Rest/`).
  - Move `src/API/HistoryController.php` → `src/Rest/HistoryController.php` (or `src/Rest/User/HistoryController.php`).
  - Move `src/API/ThemeController.php` → `src/Rest/ThemeController.php` (or `src/Rest/User/ThemeController.php`).
  - Move/rename `src/API/RestController.php` → `src/Rest/RestController.php` (or `src/Rest/BaseController.php`) and update namespaces/imports.
- Keep non-controller REST plumbing outside the controllers folder (recommended):
  - ✅ **DONE:** `src/Infrastructure/RateLimiting/RateLimiter.php` (moved from `src/API/`).
- Update wiring:
  - `src/Plugin/RestRouteRegistrar.php#getDefaultControllers()` and tagging logic.
  - `src/Providers/RestServiceProvider.php` controller discovery.
- Update architecture tests to scan all controllers (including those previously under `src/API/`).

Acceptance criteria:
- A new contributor can find *all* REST endpoints under one directory/namespace.
- Architecture boundary tests cover every REST controller.

### Phase 2 — Rate limiting: make atomicity a contract (1–2 days)

Goal: make ADR 0005 “atomic by default” without forcing all implementers to change at once.

Recommended approach (non-breaking):
- Introduce `src/Contracts/AtomicRateLimiterInterface.php` extending `RateLimiterInterface`:
  - `checkAndIncrement(int $userId): bool`
- Implement it in:
  - `src/Infrastructure/RateLimiting/RateLimiter.php`
  - `tests/Fakes/FakeRateLimiter.php`
- Update `src/Rest/RestController.php` to prefer atomic operation when available:
  - If resolved limiter implements `AtomicRateLimiterInterface`, use `checkAndIncrement()`.
  - Else, fall back to `check()` + `increment()` (maintains BC).

Acceptance criteria:
- REST permission checks use an atomic limiter when possible.
- Unit tests cover concurrent-safe behavior via `FakeRateLimiter`.

### Phase 3 — Make WordPress interaction explicit (2–5 days, incremental)

Goal: reduce direct WP global usage in application/services and standardize on injected adapters.

Tasks:
- Inject `WPFunctions` into `src/Intent/Engine.php` (constructor param) and replace direct `apply_filters()` / `do_action()` calls.
- Introduce a small “request context” adapter if needed (optional):
  - e.g., `CurrentUserInterface` (or extend `WPFunctions`) to supply `getCurrentUserId()`
  - Pass user id into service methods that currently call `get_current_user_id()`
- Add/extend architecture tests to enforce the new boundary if adopted.

Acceptance criteria:
- Engine and core application flow can be unit-tested without WordPress globals.
- Clear rule: “services don’t reach into WP for runtime context”.

### Phase 4 — Tooling: unify schema + execution (medium effort)

Goal: reduce duplicated tool argument mapping and make tool extension safer.

Option A (recommended): “Tool classes”
- Introduce a `ToolInterface` that combines schema + execution:
  - `get_name()`, `get_description()`, `get_parameters()` (schema)
  - `execute(array $args, array $context): array` (execution)
- Provide a `ToolDispatcher` service:
  - Validates args against schema (server-side)
  - Calls `execute()` and returns structured result
- Refactor `AbstractAgenticHandler` to delegate tool execution to the dispatcher.

Option B (smaller): “Central argument normalizers”
- Keep schemas as-is, but move per-tool argument mapping into reusable normalizers/executors.

Acceptance criteria:
- Adding a tool is a single, consistent recipe with tests.
- Tool argument validation happens server-side (not only “best effort”).

### Phase 5 — Time, configuration, and constants consistency (incremental)

Targets:
- Replace “new DateTime(UTC)” patterns in services (e.g., `src/Services/AnalyticsService.php`) with `ClockInterface`.
- Centralize duplicated constants:
  - REST namespace: `ResponseFormatter::REST_NAMESPACE` vs `RestController::REST_NAMESPACE` vs frontend constant.
  - Transient prefixes: `Plugin::TRANSIENT_PREFIX` vs `AgentWPConfig::CACHE_PREFIX_*`.
- Feed critical runtime constants to the frontend via `window.agentwpSettings`:
  - `apiNamespace` (and potentially feature flags / limits).

Acceptance criteria:
- Deterministic time behavior in unit tests using `FakeClock`.
- No “stringly-typed” duplication for core constants across PHP + React.

### Phase 6 — Documentation + DX polish (ongoing)

Tasks:
- Ensure `docs/API.md` + `docs/openapi.json` + generated TS types stay aligned (automation).
- Add a “how to add a new endpoint / intent / tool” checklist that references:
  - ADR 0001 (controllers resolve dependencies)
  - ADR 0007 (DTO validation)
  - ADR 0002 (handlers with `#[HandlesIntent]`)
- Keep `docs/EXTENSIONS.md` as the single source of truth for hooks.

---

## ADR Status Matrix (implementation reality)

| ADR | Topic | Expected State | Current (2026-01-18) | Next action |
|-----|-------|----------------|----------------------|-------------|
| 0001 | REST controller DI | Controllers resolve via container | Implemented | Expand controller boundary tests to include all controllers |
| 0002 | Intent handler registration | `#[HandlesIntent]` + tags | Implemented | Plan deprecation/removal timeline for legacy filters (v2/v3) |
| 0003 | Intent classification | `ScorerRegistry` is canonical | Implemented | Deprecate/remove legacy `src/Intent/IntentClassifier.php` in next major |
| 0004 | OpenAI client | Use infra abstractions; remove unused modules | Implemented (client uses HttpClient + RetryExecutor) | Keep tests covering streaming + retries |
| 0005 | REST rate limiting | Injected limiter | Implemented, but atomicity not contract-based | Add `AtomicRateLimiterInterface` and prefer atomic check |
| 0006 | Search index | Static design retained | Implemented | Keep adapter boundary; focus tests/docs |
| 0007 | Request DTO validation | DTOs required | Implemented | Consider deprecating `RestController::validate_request()` after verification |

---

## Definition of Done (for architecture work)

For any phase that changes wiring or boundaries:

- Unit tests and integration tests cover the change (add tests before refactor when possible).
- `composer run phpcs` and `composer run phpstan` pass.
- Relevant docs updated (`docs/ARCHITECTURE.md`, `docs/EXTENSIONS.md`, ADR references).
- Backwards-compatibility callout included (especially for extension developers).

