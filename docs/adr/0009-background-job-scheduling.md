# ADR 0009: Background Job Scheduling (WP-Cron vs Action Scheduler)

**Date:** 2026-01-18
**Status:** Accepted

## Context

AgentWP currently runs two background tasks using WP-Cron:

1. **Search index backfill** (`src/Search/Index.php`)
   - Hook: `agentwp_search_backfill`
   - Schedule: every minute (custom interval)
   - Characteristics: time-sliced batches, transient lock, unschedules when complete
   - Fallback: search queries fall back to source tables while backfill is incomplete

2. **Usage retention purge** (`src/Billing/UsageTracker.php`)
   - Hook: `agentwp_usage_purge`
   - Schedule: daily
   - Characteristics: deletes old rows based on retention config

WP-Cron is traffic-driven and may not run reliably on low-traffic sites or when `DISABLE_WP_CRON` is set. Action Scheduler (AS) offers more reliable queues, retry handling, and better observability, but it adds a dependency (typically via WooCommerce) and would require new storage tables plus integration work.

AgentWP should remain usable even when WooCommerce (and therefore AS) is not installed, so we cannot hard-require Action Scheduler.

## Decision

**We will keep WP-Cron as the primary scheduler and harden its usage rather than migrate to Action Scheduler at this time.**

WP-Cron already fits the low-volume, non-user-blocking nature of these tasks, and the current implementation includes mitigations (locks, time windows, and fallbacks) that reduce the impact of delayed execution.

## Compatibility Plan

- Continue scheduling via WP-Cron for both tasks.
- Maintain existing safeguards:
  - Backfill lock + time-sliced batches + automatic unschedule when complete.
  - Search fallback to source queries when backfill is incomplete.
- Document operational expectations: sites with `DISABLE_WP_CRON` should configure a real system cron to call `wp-cron.php` (or run WP-CLI cron events) to keep the schedules executing.
- Revisit Action Scheduler integration if background workloads grow or if AgentWP formally requires WooCommerce.

## Consequences

### Positive
- No new dependency or storage tables required.
- Works in minimal WordPress installs (WooCommerce not required).
- Low implementation complexity; consistent with existing behavior.

### Negative
- Execution may be delayed on low-traffic sites.
- No queue visibility or retry semantics beyond WP-Cron defaults.
- Large backfills may take longer on under-trafficked sites.

### Mitigations
- Backfill is incremental with locking and can resume safely.
- Search falls back to source tables when backfill is incomplete.
- Purge is non-critical and can tolerate delays.

## Alternatives Considered

### A. Migrate to Action Scheduler with WP-Cron fallback
**Rejected** for now because it introduces a dependency on AS availability and adds integration complexity. It is valuable for larger workloads but not necessary for current task volume.

### B. Bundle Action Scheduler in the plugin
**Rejected** due to increased bundle size, maintenance overhead, and potential conflicts with existing AS installations.

## References

- `src/Search/Index.php` — backfill scheduling, lock, and fallback
- `src/Billing/UsageTracker.php` — retention purge scheduling
- ADR 0006: Search Index Architecture
