# Specification: Scheduler Resilience & Stability Overhaul

## Overview
This track addresses critical stability and resilience issues identified in the "REV-SCHEDULER-RESILIENCE-ANALYSIS" ticket. The goal is to eliminate silent failures, prevent 24-hour outages caused by mutex deadlocks, and ensure the GTA Alerts platform robustly handles external API failures, empty feeds, and data corruption.

## Core Directives
- **Zero Silent Failures:** All exceptions in scheduled tasks must be caught, logged, and allow the mutex to release.
- **Resilient Data Ingestion:** Empty feed responses must be distinguished from valid "zero alerts" states to prevent mass data loss.
- **Graceful Degradation:** Individual malformed records should not halt the processing of an entire batch.
- **Job-Based Architecture:** Shift all data ingestion to Laravel Jobs to leverage built-in retry and backoff mechanisms.

## Functional Requirements

### 1. Scheduler & Command Resilience
- **Top-Level Exception Handling:**
    - Wrap the `handle()` method of all 4 fetch commands (`Fire`, `Police`, `GoTransit`, `TtcTransit`) in a global `try-catch` block.
    - Ensure any exception is logged with full context (stack trace, input parameters).
    - **CRITICAL:** Ensure the `withoutOverlapping` mutex is released upon failure (via command exit code or explicit release if necessary/possible).
- **Mutex Protection:**
    - Add `withoutOverlapping(600)` (10 minutes) to the `police:fetch-calls` command to prevent infinite execution overlaps.

### 2. Job-Based Ingestion Architecture
- **Migration to Jobs:**
    - Refactor `routes/console.php` to schedule Jobs (`Schedule::job(...)`) instead of Commands for:
        - `FetchFireIncidentsJob`
        - `FetchPoliceCallsJob`
        - `FetchGoTransitAlertsJob`
        - `FetchTransitAlertsJob`
- **Retry Logic:**
    - Configure each Job with:
        - `$tries = 3` (or appropriate value from analysis).
        - `$backoff = 30` (exponential or fixed).
        - `$timeout = 120` (seconds).
- **Notification Reliability:**
    - Add retry configuration to `DeliverAlertNotificationJob` ($tries=5, $backoff=10).

### 3. Data Integrity & Validation
- **Empty Feed Protection:**
    - Implement `ALLOW_EMPTY_FEEDS` environment variable (default: `false` in production).
    - In all 4 Feed Services, validate empty responses:
        - If feed is empty AND `ALLOW_EMPTY_FEEDS=false`, throw a `RuntimeException` (preventing mass deactivation).
        - If feed is empty AND `ALLOW_EMPTY_FEEDS=true`, proceed with 0 alerts.
- **Graceful Record Parsing:**
    - Wrap individual record parsing in `try-catch` blocks within the loop.
    - Log warnings for malformed records but **continue processing** the rest of the batch.
- **Data Sanity Checks:**
    - Warn on future timestamps (>15 min drift).
    - Warn on unreasonable coordinates (outside GTA bounds).

### 4. Monitoring & Maintenance
- **Queue Monitoring:**
    - Create a scheduled closure/command to check `jobs` table depth.
    - Log error if queue depth > 100.
- **Failed Job Pruning:**
    - Schedule `queue:prune-failed --hours=168` (7 days) daily.
- **Circuit Breaker:**
    - Implement a basic circuit breaker pattern in Feed Services to temporarily disable fetch attempts after repeated failures (threshold: 5 failures, reset: 5 mins).

## Non-Functional Requirements
- **Performance:** Circuit breaker should reduce load on failing external APIs.
- **Observability:** All caught exceptions must be logged to the application log with structured context.
- **Configuration:** New settings (`ALLOW_EMPTY_FEEDS`) must be documented in `.env.example`.

## Acceptance Criteria
- [ ] **Mutex Release Test:** A simulated crash in a fetch command allows the command to run again in the next schedule window (verified via test).
- [ ] **Empty Feed Test:** A simulated empty response from Fire/Police API throws an exception (and preserves old data) when `ALLOW_EMPTY_FEEDS=false`.
- [ ] **Retry Verification:** Jobs automatically retry 3 times on transient failures before failing.
- [ ] **Partial Success Test:** A feed with 10 items (1 malformed) results in 9 items persisted and 1 warning log.
- [ ] **Queue Alerting:** Queue depth > 100 triggers a log error.
- [ ] **Documentation:** `docs/backend/production-scheduler.md` updated with new failure modes and `ALLOW_EMPTY_FEEDS` usage.

## Out of Scope
- Implementation of a custom dedicated monitoring dashboard UI (logs are sufficient).
- Rewriting the external API clients from scratch (refactor existing services).
