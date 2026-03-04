---
ticket_id: FEED-014
title: "[Reliability] Stabilize Queue Worker Exit 137 and Refactor Alert Notification Fan-Out Idempotency"
status: Open
priority: Critical
assignee: Unassigned
created_at: 2026-03-03
tags: [reliability, queue, scheduler, notifications, idempotency, backend, devx]
related_files:
  - composer.json
  - routes/console.php
  - app/Listeners/DispatchAlertNotifications.php
  - app/Jobs/FanOutAlertNotificationsJob.php
  - app/Console/Commands/FetchFireIncidentsCommand.php
  - app/Console/Commands/FetchPoliceCallsCommand.php
  - app/Console/Commands/FetchTransitAlertsCommand.php
  - app/Console/Commands/FetchGoTransitAlertsCommand.php
  - app/Providers/QueueEnqueueDebugServiceProvider.php
---

## Summary

The development runtime intermittently terminates when the queue worker process exits with code `137`, causing `composer dev` to tear down all other processes (`server`, `schedule`, `vite`, and `logs`) due to `concurrently --kill-others` behavior.

In parallel, `FanOutAlertNotificationsJob` appears multiple times per scheduler cycle. Analysis confirms this is expected with the current event model (one fan-out job per `AlertCreated` event), but the architecture lacks explicit idempotency and batching controls, which creates risk for duplicate user notifications when upstream feeds flap or alert states oscillate.

This ticket addresses both:
1. Operational stability of local/worker execution (`exit 137` path).
2. Reliability hardening of notification fan-out semantics (idempotent delivery and optional batching).

## Problem Statement

### Observable Symptoms

- Queue logs show normal execution of feed fetch jobs followed by repeated `FanOutAlertNotificationsJob` executions.
- Shortly afterward, queue listener exits with code `137`.
- `composer dev` then stops all other processes because of `--kill-others`, leading to full local runtime shutdown.

Representative failure chain:

1. Scheduled fetch jobs run (`FetchFireIncidentsJob`, `FetchPoliceCallsJob`, `FetchTransitAlertsJob`, `FetchGoTransitAlertsJob`).
2. Multiple `FanOutAlertNotificationsJob` runs are processed.
3. `./vendor/bin/sail artisan queue:listen --tries=3 --timeout=0` exits `137`.
4. `concurrently` sends `SIGTERM` to all sibling processes.

## Technical Context from Debug Analysis

### 1) Why `FanOutAlertNotificationsJob` Runs Multiple Times

This is a direct result of current event granularity, not duplicate listener registration:

- The scheduler enqueues multiple fetch jobs in the same cadence window:
  - `routes/console.php` schedules fire/transit/GO every 5 minutes and police every 10 minutes.
- Each fetch command emits `AlertCreated` for newly-created or re-activated records.
  - Fire: `FetchFireIncidentsCommand` dispatches on `wasRecentlyCreated || (wasChanged('is_active') && is_active)`.
  - Police: same rule in `FetchPoliceCallsCommand`.
  - GO Transit: same rule in `FetchGoTransitAlertsCommand`.
  - TTC Transit: more permissive logic in `FetchTransitAlertsCommand::shouldDispatchNotification()` (includes accessibility effect transitions).
- `DispatchAlertNotifications` maps each `AlertCreated` to a new `FanOutAlertNotificationsJob`.

Therefore:

- `N` `AlertCreated` events in one cycle => `N` fan-out jobs.
- Multiple fan-out jobs in the same minute are expected behavior under current design.

Validation notes:

- Event wiring shows a single listener for `AlertCreated` (`php artisan event:list`), so current duplication is not caused by duplicate listener registration.
- Overlapping locks exist for fetch jobs (`withoutOverlapping` middleware + scheduler overlap guards), reducing duplicate fetch runs but not changing per-alert fan-out behavior.

### 2) Why the Runtime Dies

Primary operational issue is the queue listener being killed with `137` (typically OOM kill or forced SIGKILL in containerized runtime).

Contributing factors:

- `queue:listen` keeps a long-running PHP process and can accumulate memory across command execution paths.
- Feed ingestion and notification fan-out can produce bursty queue load.
- `composer dev` currently uses `concurrently ... --kill-others`; one process failure tears down all others.

This couples queue-worker fragility to full-stack local availability.

## Scope

### In Scope

1. Diagnose and mitigate queue worker `137` exits during dev/runtime.
2. Decouple single-process failure from total dev stack shutdown.
3. Add idempotency protections so repeated `AlertCreated` signals do not produce duplicate end-user notifications.
4. Instrument enqueue and notification flow for post-change observability.

### Out of Scope

1. Redesigning feed ingestion business logic for all data providers.
2. UX redesign of notification presentation.
3. Changing alert matching semantics beyond dedupe/idempotency safeguards.

## Root Causes

### Root Cause A: Process Supervision Coupling

`composer dev` command currently runs:

- `php artisan serve`
- `./vendor/bin/sail artisan queue:listen --tries=3 --timeout=0`
- `php artisan pail --timeout=0`
- `npm run dev`
- `./vendor/bin/sail artisan schedule:work`

with `concurrently --kill-others`.

Any abnormal queue exit causes cascading shutdown of the entire developer runtime.

### Root Cause B: Event-Per-Alert Fan-Out with No Cross-Run Idempotency Contract

Current pipeline is event-driven per alert and intentionally high-granularity. However:

- There is no explicit, durable dedupe key at fan-out boundary to guarantee one notification per `(source, alert_id, state-version)`.
- Upstream feed oscillations/reactivations can legitimately trigger repeated events.
- Without strict idempotency controls, behavior can degrade under burst/load scenarios.

## Proposed Technical Solution

## Phase 1: Runtime Stabilization (Queue 137 + Supervisor Behavior)

1. Replace `queue:listen` with bounded `queue:work` process strategy for dev and production-like local workflows.
   - Use controlled worker recycling (`--max-jobs`, `--max-time`, optional `--memory`) to limit memory growth.
2. Separate failure domains in dev process orchestration.
   - Keep queue and schedule processes isolated from frontend/web server where possible.
   - Avoid full-stack teardown on single worker failure, or add restart strategy.
3. Add explicit worker health diagnostics.
   - Structured logs for worker start/stop/restart reasons.
   - Record peak memory and queue depth snapshots near crash windows.

## Phase 2: Fan-Out Idempotency Hardening

1. Define a notification dedupe key contract at fan-out boundary, e.g.:
   - `dedupe_key = hash(source, alert_id, state_fingerprint)`
2. Enforce dedupe before scheduling chunk delivery jobs.
   - Cache lock and/or durable DB uniqueness strategy.
3. Ensure downstream delivery job respects idempotent transition rules.
   - Prevent duplicate sends for already-processed dedupe keys.
4. Preserve ability to send follow-up notifications when alert state materially changes.
   - State fingerprint must include fields representing meaningful change (e.g., status/effect/severity window).

## Phase 3: Observability and Diagnostics

1. Expand queue enqueue debug capability to include notification jobs (configurable matcher).
2. Emit metrics/logs for:
   - `AlertCreated` count by source per run.
   - fan-out jobs enqueued per scheduler cycle.
   - dedupe suppressions count.
   - actual deliveries attempted/sent/failed.
3. Add runbook notes for interpreting expected multi-fan-out behavior vs anomaly thresholds.

## Detailed Implementation Requirements

1. Queue worker command strategy
- Replace long-lived listener mode with bounded worker mode in dev scripts.
- Add documented worker flags for retries, timeout, memory, and recycling.

2. Process supervision
- Adjust process manager configuration so queue failure does not terminate unrelated services during development.
- If a strict fail-fast mode is still needed, provide explicit opt-in command.

3. Idempotency model
- Introduce deterministic dedupe-key computation from notification payload.
- Persist or lock dedupe keys with TTL aligned to alert update cadence.
- Ensure dedupe logic is race-safe under concurrent workers.

4. Fan-out behavior invariants
- Multiple `FanOutAlertNotificationsJob` runs per cycle may remain valid.
- Duplicate end-user notifications for same logical alert state must be prevented.
- Legitimate state transitions must still produce new notifications.

5. Backward compatibility
- Existing matching and chunk dispatch behavior should continue to function.
- No regression in current alert source support (fire, police, transit, GO).

## Acceptance Criteria

1. Worker stability
- Running the dev stack no longer collapses due to a single queue worker abnormal exit.
- Queue worker process has bounded lifecycle controls and predictable restart behavior.

2. Notification correctness
- Repeated ingestion of unchanged alert state does not generate duplicate user notifications.
- State changes that are configured as notify-worthy still generate exactly one notification event per unique change.

3. Scheduler/fan-out clarity
- Documentation clarifies that multiple fan-out jobs in a cycle can be expected based on event volume.
- Operational logs can distinguish expected multi-event fan-out from accidental duplicate dispatch.

4. Test coverage
- Automated tests cover dedupe key generation and duplicate suppression logic.
- Integration tests verify that duplicate `AlertCreated` events for same state do not result in multiple deliveries.
- Tests cover legitimate changed-state follow-up notification behavior.

## Engineering Notes

- Existing `withoutOverlapping` protections are necessary but insufficient for notification idempotency.
- The fix is not to force a single fan-out job globally; the fix is to make fan-out semantically idempotent while preserving throughput.
- `137` diagnosis should explicitly confirm whether host/container OOM killer is involved before selecting final worker limits.

## Risks and Mitigations

1. Risk: Over-aggressive dedupe suppresses legitimate updates.
- Mitigation: include explicit state fingerprint fields and tests for meaningful transitions.

2. Risk: New dedupe persistence layer introduces contention.
- Mitigation: use atomic lock semantics and bounded TTL; validate under parallel workers.

3. Risk: Process supervision changes hide queue failures.
- Mitigation: keep visible alerts/logs and optional strict fail-fast mode for CI diagnostics.

## Validation Plan

1. Functional validation
- Simulate repeated ingestion of identical alerts and confirm single delivery.
- Simulate reactivation/effect change and confirm one new delivery for changed state.

2. Operational validation
- Run extended dev session with scheduler + queue + feed jobs and verify no cascading shutdown.
- Capture memory/profile logs around queue worker lifecycle.

3. Regression validation
- Execute full notification test suite plus feed fetch command tests.
- Verify no breakage in chunk dispatch and preference matching behavior.

## Definition of Done

1. Queue worker execution strategy updated and documented.
2. Dev orchestration no longer cascades on isolated queue worker failure.
3. Notification fan-out dedupe contract implemented and tested.
4. Observability artifacts added to diagnose enqueue/fan-out behavior.
5. Ticket references and related docs updated for operational handoff.
