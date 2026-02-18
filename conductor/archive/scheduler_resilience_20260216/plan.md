# Implementation Plan - Scheduler Resilience Overhaul

This plan addresses critical stability and resilience issues in the GTA Alerts scheduler, implementing top-level exception handling, job-based architecture, and robust data validation.

## Phase 1: Critical Fixes & Foundation
**Goal:** Eliminate silent failures, prevent 24-hour mutex lockouts, and establish basic monitoring.

- [x] (0d35cf2) Task: Scheduler - Add Top-Level Exception Handling (Fire)
    - [x] Wrap `FetchFireIncidentsCommand::handle` in try-catch.
    - [x] Log full exception details.
    - [x] Ensure `Command::FAILURE` is returned.
- [x] (0d35cf2) Task: Scheduler - Add Top-Level Exception Handling (Police)
    - [x] Wrap `FetchPoliceCallsCommand::handle` in try-catch.
    - [x] Log full exception details.
    - [x] Ensure `Command::FAILURE` is returned.
    - [x] Add `withoutOverlapping(10)` (10 minutes) to `police:fetch-calls` schedule in `routes/console.php`.
- [x] (0d35cf2) Task: Scheduler - Add Top-Level Exception Handling (GO Transit)
    - [x] Wrap `FetchGoTransitAlertsCommand::handle` in try-catch.
    - [x] Log full exception details.
    - [x] Ensure `Command::FAILURE` is returned.
- [x] (0d35cf2) Task: Scheduler - Add Top-Level Exception Handling (TTC Transit)
    - [x] Wrap `FetchTransitAlertsCommand::handle` in try-catch.
    - [x] Log full exception details.
    - [x] Ensure `Command::FAILURE` is returned.
- [x] (89f4471) Task: Monitoring - Implement Queue Depth Check
    - [x] Create scheduled closure in `routes/console.php` to check queue depth via `Queue::size()`.
    - [x] Log error if depth > 100.
- [x] (89f4471) Task: Testing - Phase 1 Verification
    - [x] Test mutex release on exception (unit test): simulated crash in fetch command allows next scheduled run.
    - [x] Test command behavior on database connection loss (integration test).
    - [x] Test queue depth monitoring logs error when threshold exceeded.
- [x] Task: Conductor - User Manual Verification 'Phase 1: Critical Fixes & Foundation' (Protocol in workflow.md; verified 2026-02-17, log: `storage/logs/manual_tests/scheduler_resilience_phase_1_critical_fixes_foundation_2026_02_17_045150.log`)

## Phase 2: Resilience & Architecture Upgrade
**Goal:** Migrate to Job-based scheduling for retries and implement graceful degradation.

- [x] (a83e653) Task: Architecture - Migrate Fire Fetch to Job
    - [x] Update `FetchFireIncidentsJob` with `$tries=3`, `$backoff=30`, `$timeout=120`.
    - [x] Ensure `FetchFireIncidentsJob` uses `WithoutOverlapping` middleware to prevent concurrent execution.
    - [x] Update `routes/console.php` to schedule `FetchFireIncidentsJob` instead of command.
- [x] (a83e653) Task: Architecture - Migrate Police Fetch to Job
    - [x] Update `FetchPoliceCallsJob` with `$tries=3`, `$backoff=30`, `$timeout=120`.
    - [x] Ensure `FetchPoliceCallsJob` uses `WithoutOverlapping` middleware to prevent concurrent execution.
    - [x] Update `routes/console.php` to schedule `FetchPoliceCallsJob` instead of command.
- [x] (a83e653) Task: Architecture - Migrate GO Transit Fetch to Job
    - [x] Update `FetchGoTransitAlertsJob` with `$tries=3`, `$backoff=30`, `$timeout=120`.
    - [x] Ensure `FetchGoTransitAlertsJob` uses `WithoutOverlapping` middleware to prevent concurrent execution.
    - [x] Update `routes/console.php` to schedule `FetchGoTransitAlertsJob` instead of command.
- [x] (a83e653) Task: Architecture - Migrate TTC Transit Fetch to Job
    - [x] Update `FetchTransitAlertsJob` with `$tries=3`, `$backoff=30`, `$timeout=120`.
    - [x] Ensure `FetchTransitAlertsJob` uses `WithoutOverlapping` middleware to prevent concurrent execution.
    - [x] Update `routes/console.php` to schedule `FetchTransitAlertsJob` instead of command.
- [x] (a83e653) Task: Resilience - Implement Empty Feed Protection (Environment)
    - [x] Add `ALLOW_EMPTY_FEEDS` to `.env.example`.
    - [x] Update `config/app.php` (or relevant config) to read `ALLOW_EMPTY_FEEDS`.
- [x] (a83e653) Task: Resilience - Implement Empty Feed Validation (Services)
    - [x] Update `TorontoFireFeedService` to throw if empty & !allowed.
    - [x] Update `TorontoPoliceFeedService` to throw if empty & !allowed.
    - [x] Update `GoTransitFeedService` to throw if empty & !allowed.
    - [x] Update `TtcAlertsFeedService` to throw if empty & !allowed.
- [x] (a83e653) Task: Resilience - Police Pagination Mid-Stream Failure Recovery
    - [x] Modify `TorontoPoliceFeedService` to persist partial results when a mid-pagination HTTP failure occurs (Issue 3: High severity).
    - [x] Add empty features array validation on first page (`$resultOffset === 0`) to distinguish API errors from legitimate empty states.
- [x] (a83e653) Task: Resilience - Implement Graceful Record Parsing
    - [x] `FetchFireIncidentsCommand`: Change `return self::FAILURE` to `continue` on per-record timestamp parse failure (Issue 5).
    - [x] `FetchGoTransitAlertsCommand`: Change `return self::FAILURE` to `continue` on per-record parse failure (Issue 5).
    - [x] `FetchPoliceCallsCommand`: Add per-record try-catch if not already present.
    - [x] `FetchTransitAlertsCommand`: Add per-record try-catch if not already present.
    - [x] Log warning on individual record failure, continue batch processing.
- [x] (a83e653) Task: Resilience - Configure Notification Job Retry
    - [x] Update `DeliverAlertNotificationJob` with `$tries=5`, `$backoff=10`.
- [x] (a83e653) Task: Testing - Phase 2 Verification
    - [x] Test batch processing resilience: feed with 10 items (1 malformed) results in 9 persisted and 1 warning log (unit test).
    - [x] Test job retry behavior on transient API failure (integration test).
    - [x] Test empty feed handling across all 4 sources: empty response throws exception and preserves existing data (integration test).
    - [x] Test police pagination mid-stream failure: partial results from successful pages are not lost (unit test).
- [x] (dc21c57) Task: Conductor - User Manual Verification 'Phase 2: Resilience & Architecture Upgrade' (Protocol in workflow.md; script: `tests/manual/verify_scheduler_resilience_phase_2_resilience_architecture_upgrade.php`)

## Phase 3: Data Integrity & Maintenance
**Goal:** Ensure data quality and long-term system health.

- [x] (ddc0c3d) Task: Data Integrity - Timestamp Sanity Checks
    - [x] Add warning logic for future timestamps (>15 min) in all parsers.
    - [x] Add warning logic for unreasonable coordinates (outside GTA) in Police/Fire parsers.
        - [x] Note: Toronto Fire feed does not provide coordinates; coordinate sanity is applied to police records only.
- [x] (ddc0c3d) Task: Data Integrity - Memory Safety
    - [x] Implement safety limit (max records) in `TorontoPoliceFeedService` pagination loop.
- [x] (ddc0c3d) Task: Data Integrity - Scene Intel Monitoring
    - [x] Add failure rate tracking to `FetchFireIncidentsCommand`/`Job` (and others using Scene Intel).
    - [x] Log warning if Scene Intel failure rate > 50%.
- [x] (ddc0c3d) Task: Maintenance - Failed Job Pruning
    - [x] Add scheduled command `queue:prune-failed --hours=168` to `routes/console.php`.
- [x] (ddc0c3d) Task: Resilience - Implement Circuit Breaker
    - [x] Add basic circuit breaker logic (cache-based counter) to all 4 feed services:
        - [x] `TorontoFireFeedService`
        - [x] `TorontoPoliceFeedService`
        - [x] `GoTransitFeedService`
        - [x] `TtcAlertsFeedService`
    - [x] Threshold: 5 failures, TTL: 5 minutes.
- [x] (ddc0c3d) Task: Testing - Phase 3 Verification
    - [x] Test circuit breaker opens after threshold and auto-recovers after TTL (integration test).
    - [x] Test memory safety limit triggers error on oversized police pagination response (unit test).
    - [x] Test Scene Intel failure rate logging triggers warning at >50% threshold (unit test).
- [x] Task: Conductor - User Manual Verification 'Phase 3: Data Integrity & Maintenance' (Protocol in workflow.md; script: `tests/manual/verify_scheduler_resilience_phase_3_data_integrity_maintenance.php`)

## Phase 4: Quality & Documentation
**Goal:** Final verification and documentation maintenance for the shipped scope.

- [x] Task: Coverage and Linting Verification
    - [x] Execute `composer test` - Ensure all tests pass.
    - [x] Execute `pnpm run quality:check` - Ensure frontend quality (if applicable).
    - [x] Verify test coverage meets >90% threshold for modified files. (coverage driver setup helper: `scripts/setup-coverage.sh` in commit 90db9f9)
        - [x] Add `FeedDataSanity` tests for future timestamp warnings, in-grace no-op, and GTA bounds warnings/null coords no-op.
        - [x] Add `FeedDataSanity` edge-case tests for grace window at 0/negative, missing/invalid bounds, and timestamp exactly at grace boundary.
        - [x] Add `FeedCircuitBreaker` tests for disabled breaker, cache failure handling (get/put/forget), and open-breaker logging path.
        - [x] Add `FeedCircuitBreaker` edge-case tests for non-int cache values, threshold/ttl <= 0 clamping, and trimmed feed name keys.
        - [x] Add `FetchPoliceCallsCommand` tests for QueryException rethrow and per-record non-DB exception skip + warning log.
        - [x] Add `FetchPoliceCallsCommand` edge-case tests for partial fetch with empty calls, no alert dispatch on unchanged active, and duplicate object_id handling.
        - [x] Add `FetchTransitAlertsCommand` tests for invalid `external_id` skip, QueryException rethrow, and accessibility notification dispatch rules.
        - [x] Add `FetchTransitAlertsCommand` edge-case tests for whitespace external_id, accessibility null effect no-op, and IN/OUT service transitions.
        - [x] Add `TorontoPoliceFeedService` edge-case tests for missing attributes, empty first-page features throw, later empty pages allowed, and max_records boundary behavior.
- [x] (eb53051) Task: Documentation Update
    - [x] Update `docs/backend/production-scheduler.md` with new failure modes & `ALLOW_EMPTY_FEEDS` configuration.
    - [x] Create `docs/runbooks/scheduler-troubleshooting.md` covering common failure scenarios and recovery steps.
    - [x] Create `docs/runbooks/queue-troubleshooting.md` covering queue backlog management and failed job analysis.
    - [x] Update `docs/backend/maintenance.md` to include Failed Job pruning policy.
    - [x] Update `docs/backend/scene-intel.md` to document the retry policy and acceptable failure modes for Scene Intel.
    - [x] Document persistent vs transient failure characteristics (24-hour mutex lockout cycles vs auto-recovery).
    - [x] Document monitoring thresholds and alerting setup (queue depth, command failure rate, Scene Intel failure rate, etc.).
    - [x] Document empty feed handling strategy and the `ALLOW_EMPTY_FEEDS` flag with usage guidance.
- [ ] Task: Conductor - User Manual Verification 'Phase 4: Quality & Documentation' (Protocol in workflow.md)
