# AgentWP Architecture Improvement Plan

**Last updated:** 2026-01-18  
**Scope:** PHP backend (`src/`), React admin UI (`react/`), build/CI, docs, and operational concerns.

This document is the actionable roadmap to (1) fix currently observed issues, and (2) evolve the architecture while preserving the project’s existing boundaries (DI + contracts, ServiceResult, gateways, DTO validation, and the service provider bootstrap).

---

## 0) Guiding Principles

- **Keep the current layering intact:** `Rest` (I/O) → `Intent` (orchestration) → `Services` (business rules) → `Infrastructure` (WordPress/WooCommerce adapters).
- **Preserve testability:** Services depend on contracts; minimize WP globals outside Infrastructure/Plugin/Rest.
- **Prefer additive changes with deprecations** over breaking rewrites (WordPress plugin stability).
- **Make CI the source of truth:** plan work so the repo can run `./scripts/validate.sh` cleanly.

---

## 1) Current State (Findings from a repo-wide audit)

### 1.1 CI / Tooling Status (Observed Locally)

- **Docs link checker fails** due to missing `docs/ARCHITECTURE-IMPROVEMENT-PLAN.md` (this file is meant to fix that).  
  - Verify with: `composer run docs:check-links`
- **PHPStan fails** with interface/signature and trivial logic issues:
  - `src/Contracts/HooksInterface.php` PHPDoc/signature mismatch
  - `src/Intent/Engine.php` calls `HooksInterface::applyFilters()` with 3 args but the interface declares only 2
  - `src/Infrastructure/RateLimiting/RateLimiter.php` has a trivially-always-true condition flagged by PHPStan
  - Verify with: `composer run phpstan`
- **React ESLint fails** because `react/scripts/check-openapi-types.cjs` is linted with browser globals and disallows `require()`:
  - Verify with: `npm --prefix react run lint`
- **PHPUnit passes** (889 tests) but emits **deprecation warnings** from `src/Intent/IntentClassifier.php`.
  - Verify with: `vendor/bin/phpunit`
- **OpenAPI spec validation passes**.
  - Verify with: `composer run openapi:validate`

### 1.2 Versioning / Release Hygiene Issues

- `agentwp.php` declares plugin version **`0.2.0`**, while `src/Plugin/Upgrader.php` contains steps for **`0.1.1`** and **`0.1.2`** and docs mention later work in “Unreleased” (keep these aligned for future releases).
- Deprecations claim “since 2.0.0 / removed in 3.0.0” in `src/Intent/IntentClassifier.php`, which is inconsistent with the plugin’s current semver.

### 1.3 Architecture Mismatches / Drift

- **Hook abstraction drift:** `HooksInterface` is conceptually variadic, but the interface is not, while `WPFunctions::applyFilters()` effectively supports variadic usage.
- **Intent “function” naming drift:** the codebase uses both:
  - “functions” (`src/Intent/FunctionRegistry.php`, docs and filters such as `agentwp_register_intent_functions`)
  - “tools” (`src/Intent/ToolRegistry.php`, `ToolDispatcher`, `src/AI/Functions/*` schemas)
  This is workable but confusing for extension authors and future contributors.
- **Suggestion mapping drift:** `Engine::register_default_functions()` includes entries like `select_orders` / `bulk_update` that do not appear in current handler tool lists (e.g., `OrderSearchHandler::getToolNames()` only returns `search_orders`).

### 1.4 Code Quality / Lint Notes

- PHPCS reports warnings (not errors), mostly unused parameters (e.g., `src/Infrastructure/NullLogger.php`).

---

## 2) Roadmap (Phased)

### Phase 1 — Make CI Green (highest priority)

Goal: eliminate current red builds so architectural work can land safely.

- [ ] **Docs:** ensure `composer run docs:check-links` passes (this file should resolve the current missing-target failures).
- [ ] **PHPStan:** fix `HooksInterface::applyFilters()` contract so it matches real usage:
  - Update `src/Contracts/HooksInterface.php` to accept variadic args (`...$args`) and update the docblock accordingly.
  - Ensure `src/Infrastructure/WPFunctions.php` signature matches the interface (may keep current implementation but update method signature).
  - Update test fakes implementing `HooksInterface` (e.g., `tests/Fakes/FakeWPFunctions.php`) to match.
  - Re-run: `composer run phpstan`
- [ ] **PHPStan:** resolve the trivial “always true” condition:
  - In `src/Infrastructure/RateLimiting/RateLimiter.php`, simplify the `finally` block since `$lockAcquired` is guaranteed true after the early-return guard.
  - Re-run: `composer run phpstan`
- [ ] **React lint:** add an ESLint override for Node scripts:
  - Configure `react/eslint.config.js` to treat `react/scripts/**/*.cjs` as Node/CommonJS (or exclude `react/scripts/` from lint).
  - Re-run: `npm --prefix react run lint`
- [ ] **CI parity:** run the combined local checks:
  - `./scripts/validate.sh`

Deliverable: green CI on `php`, `node`, `security`, and docs checks.

### Phase 2 — Normalize Versioning & Deprecation Policy

Goal: establish a coherent semantic version story across plugin header, Upgrader, and docs.

- [ ] Decide the **authoritative current version** (likely >= `0.1.2` given `Upgrader` steps and docs), then align:
  - `agentwp.php` header + `AGENTWP_VERSION`
  - `docs/CHANGELOG.md` sections (move implemented changes out of “Unreleased” into a tagged release)
  - `src/Plugin/Upgrader.php` steps (ensure step versions are <= current, and add new steps only for future versions)
  - `react/package.json` version (optional, but recommended to keep consistent)
- [ ] Establish a **deprecation policy** for internal APIs:
  - Replace “since 2.0.0 / removed in 3.0.0” language in `src/Intent/IntentClassifier.php` with plugin-appropriate versions (or document that these are “internal architecture versions” and keep them consistent everywhere).
  - Ensure PHPUnit runs without noisy deprecations by default (either by updating tests, adjusting triggering conditions, or documenting expected warnings).

Deliverable: clear release history + predictable upgrades.

### Phase 3 — Clarify “Functions” vs “Tools” (Intent System)

Goal: reduce conceptual complexity and make extension points easier to use safely.

- [x] Document the canonical terminology:
  - “Tool schema” (OpenAI tool definition) lives in `src/AI/Functions/*` (or rename/move in a later step).
  - “Tool executor” lives in `src/Intent/Tools/*` and is dispatched by `ToolDispatcher`.
- [x] Reconcile `FunctionRegistry` vs `ToolRegistry`:
  - Option A (minimal): keep `FunctionRegistry` for backward compatibility but document it as “legacy suggestions only”.
  - Option B (preferred long-term): replace `FunctionRegistry` with a `ToolSuggestionRegistry` or derive suggestions from `ToolRegistry` + handler tool lists.
- [x] Fix mapping drift:
  - Ensure `Engine::register_default_functions()` mappings match the actual tools exposed by each handler (`getToolNames()`), or remove unused mappings if nothing consumes them.
- [ ] Improve unknown-tool behavior:
  - Ensure errors returned by `ToolDispatcher::dispatch()` are surfaced in a consistent, user-safe way (and logged via `AuditLogger`/`LoggerInterface` without secrets).

Deliverable: one mental model for “what the model can call” and “what executes”.

### Phase 4 — DI Consistency & Extension Patterns

Goal: make service wiring and extension registration simpler and harder to misuse.

- [ ] Bind key interfaces to concrete services where it improves consistency:
  - Consider binding `HooksInterface` → `WPFunctions` in `CoreServiceProvider` so callers don’t need fallback logic.
  - Consider binding `WPUserFunctionsInterface` → `WPFunctions` similarly.
- [ ] Strengthen tagging conventions:
  - Keep `rest.controller` + `intent.handler` tagging, and document the “tag contract” (constructor constraints, required interfaces).
- [ ] Add an explicit extension hook for tool schemas/executors:
  - e.g., `agentwp_register_tools` action that receives `ToolRegistryInterface` and `ToolDispatcherInterface`.
  - This avoids requiring third parties to write a whole provider just to add one tool.

Deliverable: clearer DI wiring with stable, minimal extension APIs.

### Phase 5 — REST API Hardening & Observability

Goal: protect endpoints and improve supportability without leaking sensitive data.

- [ ] Standardize authorization:
  - Consider a filtered capability (default `manage_woocommerce`) so sites can delegate access safely.
  - Ensure all state-changing endpoints enforce nonce verification (already centralized in `RestController`, keep it that way).
- [ ] Add operational visibility:
  - Optional “diagnostics” endpoint/page (admin-only) exposing:
    - health (already exists), last errors, rate-limit status, config flags, search index state
  - Ensure all logs redact secrets (`WooCommerceLogger` already attempts this; extend patterns as needed).

Deliverable: safer API + faster debugging in production.

### Phase 6 — Background Work Reliability (Search Index, Usage Tracking)

Goal: make long-running maintenance tasks robust on typical WP hosting.

- [ ] Evaluate moving cron-based work to Action Scheduler when WooCommerce provides it:
  - Search index backfill (`src/Search/Index.php`)
  - Usage purge (`src/Billing/UsageTracker.php`)
- [ ] Make multi-site behavior explicit:
  - Document network activation expectations
  - Ensure uninstall/cleanup remains correct per-site and network-wide

Deliverable: fewer “cron didn’t run” issues for merchants.

### Phase 7 — Frontend Architecture & UX Maintainability

Goal: keep the React UI modular, testable, and compatible with WP admin constraints.

- [ ] Fix lint config (Phase 1) and then:
  - [ ] Add ESLint overrides for `scripts/`, `tests/`, and browser globals as appropriate.
  - [ ] Consolidate shared UI components (either keep `react/components` with aliases or migrate under `react/src/components` and update paths).
  - [ ] Tighten API client patterns (`react/src/api/AgentWPClient.ts`): typed error handling aligned with `ResponseFormatter` envelope.
  - [ ] Add UI-level tests around critical flows (settings, intent execution, error states) if gaps exist.

Deliverable: clean, enforceable UI conventions and predictable API integration.

---

## 3) Definition of Done (for the roadmap)

For each phase, prefer merging work only when:

- `composer run phpcs` has **0 errors** (warnings are triaged intentionally)
- `composer run phpstan` has **0 errors**
- `vendor/bin/phpunit` is green
- `composer run openapi:validate` is green
- `composer run docs:check-links` is green
- `npm --prefix react run typecheck` and `npm --prefix react run lint` are green

---

## 4) Follow-up ADRs (when needed)

Create a new ADR in `docs/adr/` when making a change that:

- breaks extension APIs, or
- changes the tool/function model, or
- replaces cron with Action Scheduler, or
- changes storage/encryption semantics.
