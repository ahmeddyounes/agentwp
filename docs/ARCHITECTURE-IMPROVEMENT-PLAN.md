# AgentWP Architecture Improvement Plan

**Generated:** 2026-01-18  
**Scope:** PHP backend (`src/`), REST API, admin UI (`assets/` + `react/`), tooling (`tests/`, CI)

## Executive summary

AgentWP already has a strong backend architecture (custom container + service providers + contracts, and a clean REST layer). The frontend is now fully consolidated:

- The React-based UI in `react/` builds to `assets/build/` (hashed chunks via Vite, Shadow DOM mounting, stores/hooks)
- The `AssetManager` reads the Vite manifest and enqueues the production assets
- Legacy wp-element bundle has been removed (Phase 1.3 completed)

This plan prioritizes **tightening module boundaries and standardizing service contracts and cross-cutting concerns** (logging, usage tracking, upgrade/migrations).

## Baseline (what exists today)

### Backend composition and boundaries
- Entry point: `agentwp.php` → `AgentWP\Plugin::init()` with environment gating (`src/Compatibility/Environment.php`).
- DI container: `src/Container/Container.php` (singletons, auto-wiring, tags).
- Providers: `src/Providers/*` (Core, Infrastructure, Services, Rest, Intent).
- Contracts + adapters: `src/Contracts/*` + `src/Infrastructure/*` keeps most services testable without WordPress/WooCommerce runtime.

### REST API
- Base controller: `src/API/RestController.php` (permissions, nonce verification, schema validation, standard responses).
- Controller discovery: `src/Plugin/RestRouteRegistrar.php` via container tag `rest.controller`.
- Response normalization: `src/Plugin/ResponseFormatter.php` on `rest_post_dispatch`.

### Intent / AI runtime
- Intent engine: `src/Intent/Engine.php` with attribute-based handler registration (`src/Intent/Attributes/HandlesIntent.php`) and O(1) handler lookup via `src/Intent/HandlerRegistry.php`.
- Classifier: `src/Intent/Classifier/ScorerRegistry.php` wired by `src/Providers/IntentServiceProvider.php`.
- AI: `src/AI/AIClientFactory.php` + `src/AI/OpenAIClient.php` using injected `HttpClientInterface` and centralized retry (`src/Retry/RetryExecutor.php`).

### Domain services
- Command-style services (mutations) largely use `src/DTO/ServiceResult.php` with a policy layer and gateway abstractions.
- Query-style services still return arrays in a few areas (e.g., analytics/customer/search) and are consumed by handlers/controllers.

### Tooling
- PHPUnit + PHPCS + PHPStan configured via `composer.json`.
- React tooling lives under `react/` (TypeScript, ESLint/Prettier, Vitest, OpenAPI type generation).
- CI workflows exist under `.github/workflows/`.

## Guiding principles
1. **One runtime wiring path**: all runtime dependencies flow through container + providers (no direct instantiation in controllers/handlers).
2. **One frontend delivery mechanism**: deterministic build output + deterministic WordPress enqueueing.
3. **Clear boundaries**: domain/services must not depend on WordPress/WooCommerce globals; infrastructure adapters isolate platform calls.
4. **Consistent contracts**: use `ServiceResult` (or DTOs) where the caller needs uniform error handling and HTTP mapping.
5. **Deprecate intentionally**: versioned, documented deprecations aligned with the project’s actual release cadence.

## Roadmap overview

| Phase | Theme | Primary outcome |
|------:|-------|-----------------|
| 1 | Frontend consolidation | A single, supported UI build + enqueue path |
| 2 | Public API hardening | Stable extension surface + module boundaries enforced |
| 3 | Contract normalization | Consistent service/controller result + error mapping |
| 4 | Cross-cutting services | Replace remaining static/global access blocking testability |
| 5 | Deprecation + upgrades | Clean removal path + upgrade/migration strategy |
| 6 | Observability + performance | Logging, auditability, and background work budgets |
| 7 | Automation | CI gates reflect the architecture rules |

## Phase 1 — Frontend consolidation (highest priority)

### Goals
- Decide and document the **single supported frontend** for the product surface(s).
- Make build output deterministic and correctly enqueued by WordPress.
- Remove (or clearly quarantine) the unused frontend to reduce ongoing maintenance cost.

### Decision to make (pick one)

**Option A — Keep the legacy wp-element UI**
- Keep `assets/agentwp-admin.js` + `assets/agentwp-admin.css` as the only shipped UI.
- Treat `react/` as experimental and remove it from release/CI (or delete it entirely).

**Option B — Adopt the Vite React app** ✅ **SELECTED**
- Treat `react/` as the source of truth for the UI.
- Wire its build output into `src/Plugin/AssetManager.php`.
- Deprecate and remove the legacy bundle.

### Recommended direction
Adopt **Option B** if the React app in `react/` is the intended "Command Deck" experience (it already includes Shadow DOM isolation, testing, and a modular feature structure).

---

## Phase 1 Decision Record

**Decision:** Option B — Adopt the Vite React app as the single supported frontend.

**Date:** 2026-01-17

**Rationale:**
1. **Feature completeness**: The `react/` app is the full "Command Deck" experience with analytics, voice controls, usage tracking, and a modern component architecture. The legacy bundle is limited to basic settings management.
2. **Architecture quality**: The React app uses modern patterns (Shadow DOM isolation, TanStack Query, Zustand, TypeScript) that provide better maintainability and testability.
3. **Testing infrastructure**: The React app has comprehensive testing (Vitest, Testing Library, MSW mocks) while the legacy bundle has none.
4. **Type safety**: OpenAPI type generation ensures API contract alignment.
5. **Build tooling**: Vite provides faster development iteration and optimized production builds.

### Migration Strategy

**Phase 1.1 — Wire Vite output into WordPress (immediate)**
1. Configure Vite to emit a `manifest.json` and build to `assets/build/` (or similar plugin-owned directory).
2. Update `src/Plugin/AssetManager.php` to:
   - Read the Vite manifest to resolve hashed entry file names.
   - Enqueue the main JS/CSS entry points with correct dependencies.
   - Pass localization data via `wp_add_inline_script`.
3. Update `src/Plugin/AdminMenuManager.php` to render `#agentwp-root` instead of `#agentwp-admin-root` (or update `react/src/main.tsx` to accept `#agentwp-admin-root`).

**Phase 1.2 — Fallback behavior (transitional)**
- During the migration period, `AssetManager` will check for the Vite manifest:
  - If manifest exists → enqueue Vite React app.
  - If manifest missing → fall back to legacy `assets/agentwp-admin.*`.
- This allows safe rollback if issues arise during rollout.

**Phase 1.3 — Legacy removal** ✅ **COMPLETED**
- The Vite React app is confirmed stable in production:
  - ✅ Removed `assets/agentwp-admin.js` and `assets/agentwp-admin.css`.
  - ✅ Removed fallback logic from `AssetManager` (`enqueueLegacyScript`, `enqueueStyle` methods).
  - Update `.github/workflows/release.yml` to only package Vite build output (if not already done).

### Runtime Surfaces
The Command Deck will be available on:
1. **AgentWP admin page** (`WooCommerce > AgentWP`) — full settings + command interface.
2. **All WooCommerce screens** — command deck overlay triggered via Cmd/Ctrl+K or admin bar button.

### Compatibility Notes
- Vite `base` config must use a plugin-relative path to support subdirectory installs and multisite.
- Chunk loading URLs must be derived from the WordPress plugin URL, not hardcoded.
- Shadow DOM ensures CSS isolation from WordPress admin styles.

### Removal Timeline
| Milestone | Target |
|-----------|--------|
| Vite build wired into WP | Next patch release |
| Legacy fallback deprecated | +1 minor release |
| Legacy assets removed | +2 minor releases |

---

### Work items (Option B)
- **Mount point alignment**
  - Either update `react/src/main.tsx` to mount to `#agentwp-admin-root`, or update `src/Plugin/AdminMenuManager.php` to render `#agentwp-root` (or both during migration).
- **Deterministic asset enqueueing**
  - Configure Vite to emit a `manifest.json` and build into a plugin-owned directory (e.g., `assets/build/`).
  - Update `src/Plugin/AssetManager.php` to load the entry JS/CSS from the manifest and enqueue all required chunks/styles.
  - Keep a fallback path to the legacy assets while migrating (feature flag or “if manifest exists”).
- **Release workflow correctness**
  - Update `.github/workflows/release.yml` to package the actual UI build artifacts used by `AssetManager` (and prevent stale legacy artifacts from shipping).
- **Runtime surfaces**
  - Decide where the Command Deck is available:
    - only the AgentWP admin page, or
    - all WooCommerce screens (requires injecting a mount node + triggers globally).
- **Compatibility**
  - Verify that chunk loading uses plugin-relative URLs (Vite `base` and output paths) under typical WP installs (subdirectory installs, multisite).

### Definition of done
- No ambiguity about which frontend is shipped and supported.
- A fresh `react` build is what WordPress loads at runtime (no stale bundles).
- E2E tests prove the UI loads and key flows work in the packaged plugin zip.

## Phase 2 — Public API hardening and module boundaries

### Goals
- Treat hooks, filters, REST endpoints, and contracts as **public APIs** with explicit stability expectations.
- Reduce accidental coupling across modules.

### Work items
- **Extension surface inventory**
  - List “supported extension points” (filters/actions) in one place and link to them from `docs/DEVELOPER.md`.
  - Document required contracts for: custom intent handlers, custom scorers, and custom REST controllers.
- **Boundary enforcement**
  - Add PHPStan rules (or architectural conventions) to prevent `src/Services/*` from directly calling WordPress/WooCommerce globals.
  - Prefer interfaces in controllers and handlers (already common) and remove remaining concrete lookups where unnecessary.

### Definition of done
- Extension developers have a clear list of supported integration points.
- Violations of “domain code calls WP globals” are caught automatically.

## Phase 3 — Contract normalization (services + REST)

### Goals
- Make service outcomes easy to consume consistently by controllers and handlers.
- Reduce “mixed return type” propagation (arrays vs `ServiceResult` vs `WP_Error`).

### Work items
- **ServiceResult coverage**
  - Expand `ServiceResult` usage to remaining services where the caller needs reliable HTTP/error mapping (analytics/customer/search are common candidates).
  - Keep pure query helpers returning arrays if they remain internal-only and well-documented.
- **REST error flow consistency**
  - Decide on one canonical error mechanism at controller boundaries:
    - return `WP_Error` and let `ResponseFormatter` normalize, or
    - return normalized `WP_REST_Response` errors consistently.
  - Remove duplicated error formatting logic once one path is chosen.
- **Schema-first validation**
  - Prefer request DTOs + JSON schema validation patterns consistently across controllers.
  - Ensure `docs/openapi.json` stays synchronized with actual request/response formats.

### Definition of done
- Controllers have a predictable “happy path + failure path”.
- A contributor can add a new endpoint without inventing a new validation/response style.

## Phase 4 — Cross-cutting services (reduce static/global coupling)

### Goals
- Replace remaining static/global access that blocks testability or modularity.

### Work items
- **Usage tracking**
  - Wire `src/Contracts/UsageTrackerInterface.php` and provide an implementation adapter over `src/Billing/UsageTracker.php`.
  - Update callers (e.g., `src/AI/OpenAIClient.php`, `src/Rest/SettingsController.php`) to depend on the interface via container resolution.
- **Search index access**
  - If the static design remains (per ADR 0006), add a thin interface adapter for consumers so “search” can be mocked in tests and swapped in future.
- **Container string IDs**
  - Prefer `::class` identifiers in providers where possible (avoid raw string IDs unless required for backward compatibility).

### Definition of done
- Core cross-cutting concerns can be mocked/replaced via DI.
- Static calls are isolated behind adapters where they still exist.

## Phase 5 — Deprecation cleanup and upgrade strategy

### Goals
- Make upgrades safe and predictable and keep the codebase free of long-lived compatibility shims.

### Work items
- **Deprecation policy**
  - Align `@deprecated since …` annotations with the actual plugin versioning strategy (currently some deprecations reference versions that do not match `AGENTWP_VERSION`).
  - Define the removal window (e.g., “remove after next major”).
- **Upgrade/migration runner**
  - Add a stored “installed version” option and run upgrade steps on `plugins_loaded` when version changes.
  - Use it for schema changes (billing/search tables), option migrations, and cleanup of removed keys.
- **Remove deprecated surfaces**
  - Remove deprecated constants/methods only after the upgrade runner has migrated off them.

### Definition of done
- Upgrades have a single, testable entry point and are documented.
- Deprecated APIs are actively removed on a schedule, not indefinitely maintained.

## Phase 6 — Observability, security, and performance hardening

### Goals
- Improve diagnosability without leaking secrets.
- Ensure background work and heavy operations have explicit time and memory budgets.

### Work items
- **Logging**
  - Introduce a minimal logger interface with a WordPress/WooCommerce adapter.
  - Add structured logging around AI calls (timing, retry count, error codes) without logging prompts or API keys.
- **Audit events**
  - Log sensitive actions (API key updates, draft confirmations) to a dedicated audit sink.
- **Background work budgets**
  - Move expensive operations (search backfill, periodic usage purges) to scheduled/background execution with strict time slicing and lock discipline.
- **Config hardening**
  - Ensure all operational knobs (rate limits, timeouts, memory TTLs/limits) are consistently configurable via settings and/or filters.

### Definition of done
- A production issue can be diagnosed with logs/metrics without adding ad-hoc debug code.
- No expensive work is executed unintentionally on unrelated page loads.

## Phase 7 — Automation (architecture as guardrails)

### Goals
- Make the architecture rules enforceable by CI.

### Work items
- Add `phpstan` to `.github/workflows/ci.yml` (it’s configured locally but not currently executed in CI).
- Add a lightweight “release artifact validation” job (confirm required assets exist; ensure no `node_modules` in the package).
- Add a doc check that `docs/openapi.json` validates and TypeScript types can be generated.

### Definition of done
- Architectural regressions are caught by CI before merge/release.

## Tracking and ownership

Recommended tracking approach:
- Create one GitHub Project/board with phases as epics.
- Each phase has 5–15 scoped issues with explicit “Definition of done”.
- Keep a running “Architecture decision log” in `docs/adr/` when tradeoffs are non-trivial.

## References
- Technical architecture: `docs/ARCHITECTURE.md`
- Developer guide: `docs/DEVELOPER.md`
- ADRs: `docs/adr/0001-*.md`
- Prior audit snapshot: `ARCHITECTURE_REPORT.md`
