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
- **Job Overlap Protection:**
    - Implement `WithoutOverlapping` middleware (or `ShouldBeUnique`) in each Job class to prevent concurrent execution of the same job type.
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
- **Police Pagination Resilience:**
    - Modify `TorontoPoliceFeedService` to handle mid-stream pagination failures gracefully (Issue 3: High severity).
    - When a page fetch fails mid-pagination, persist partial results from successfully fetched pages rather than discarding all work.
    - Add empty features array validation on the first page (`$resultOffset === 0`) to distinguish API errors from legitimate empty responses.
- **Graceful Record Parsing:**
    - Wrap individual record parsing in `try-catch` blocks within the loop in all 4 fetch commands.
    - Specifically: `FetchFireIncidentsCommand` and `FetchGoTransitAlertsCommand` currently hard-fail (`return self::FAILURE`) on single malformed records — change to `continue` with warning log.
    - Log warnings for malformed records but **continue processing** the rest of the batch.
- **Data Sanity Checks:**
    - Warn on future timestamps (>15 min drift).
    - Warn on unreasonable coordinates (outside GTA bounds).
- **Memory Safety:**
    - Enforce a safety limit (e.g., 100,000 records) in the `TorontoPoliceFeedService` pagination loop to prevent OOM errors.
- **Scene Intel Monitoring:**
    - Track success/failure counts for Scene Intel processing within each command run.
    - Log a warning if the failure rate exceeds 50%.

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
- **Documentation:** Runbooks for scheduler and queue troubleshooting; maintenance docs updated with failed job pruning; persistent vs transient failure characteristics documented; monitoring thresholds and alerting setup documented.

## Acceptance Criteria
- [ ] **Mutex Release Test:** A simulated crash in a fetch command allows the command to run again in the next schedule window (verified via test).
- [ ] **Empty Feed Test:** A simulated empty response from each of the 4 sources throws an exception (and preserves old data) when `ALLOW_EMPTY_FEEDS=false`.
- [ ] **Retry Verification:** Jobs automatically retry 3 times on transient failures before failing.
- [ ] **Partial Success Test:** A feed with 10 items (1 malformed) results in 9 items persisted and 1 warning log.
- [ ] **Police Pagination Test:** A mid-pagination HTTP failure preserves partial results from successfully fetched pages.
- [ ] **Queue Alerting:** Queue depth > 100 triggers a log error.
- [ ] **Memory Safety Test:** Police pagination exceeding the safety limit throws a clear error rather than OOM.
- [ ] **Scene Intel Monitoring Test:** Scene Intel failure rate >50% triggers a warning log.
- [ ] **Circuit Breaker Test:** Feed service circuit breaker opens after 5 failures and auto-recovers after 5-minute TTL.
- [ ] **Documentation:** Scheduler docs, runbooks, and maintenance docs updated to reflect all new failure modes, monitoring thresholds, persistent vs transient failure characteristics, and `ALLOW_EMPTY_FEEDS` configuration.

## Out of Scope
- Implementation of a custom dedicated monitoring dashboard UI (logs are sufficient).
- Rewriting the external API clients from scratch (refactor existing services).
- NTP/clock drift monitoring (Priority 4/Low in analysis — R4.1).
- API response time metrics logging (Priority 4/Low in analysis — R4.2).
- Partial data corruption detection beyond timestamp/coordinate sanity (Edge Case 1 — subtle invalid data like swapped lat/lng).
