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

- [ ] Task: Data Integrity - Timestamp Sanity Checks
    - [ ] Add warning logic for future timestamps (>15 min) in all parsers.
    - [ ] Add warning logic for unreasonable coordinates (outside GTA) in Police/Fire parsers.
- [ ] Task: Data Integrity - Memory Safety
    - [ ] Implement safety limit (max records) in `TorontoPoliceFeedService` pagination loop.
- [ ] Task: Data Integrity - Scene Intel Monitoring
    - [ ] Add failure rate tracking to `FetchFireIncidentsCommand`/`Job` (and others using Scene Intel).
    - [ ] Log warning if Scene Intel failure rate > 50%.
- [ ] Task: Maintenance - Failed Job Pruning
    - [ ] Add scheduled command `queue:prune-failed --hours=168` to `routes/console.php`.
- [ ] Task: Resilience - Implement Circuit Breaker
    - [ ] Add basic circuit breaker logic (cache-based counter) to all 4 feed services:
        - [ ] `TorontoFireFeedService`
        - [ ] `TorontoPoliceFeedService`
        - [ ] `GoTransitFeedService`
        - [ ] `TtcAlertsFeedService`
    - [ ] Threshold: 5 failures, TTL: 5 minutes.
- [ ] Task: Testing - Phase 3 Verification
    - [ ] Test circuit breaker opens after threshold and auto-recovers after TTL (integration test).
    - [ ] Test memory safety limit triggers error on oversized police pagination response (unit test).
    - [ ] Test Scene Intel failure rate logging triggers warning at >50% threshold (unit test).
- [ ] Task: Conductor - User Manual Verification 'Phase 3: Data Integrity & Maintenance' (Protocol in workflow.md)

## Phase 4: Quality & Documentation
**Goal:** Final verification and documentation maintenance for the shipped scope.

- [ ] Task: Coverage and Linting Verification
    - [ ] Execute `composer test` - Ensure all tests pass.
    - [ ] Execute `pnpm run quality:check` - Ensure frontend quality (if applicable).
    - [ ] Verify test coverage meets >90% threshold for modified files.
- [ ] Task: Documentation Update
    - [ ] Update `docs/backend/production-scheduler.md` with new failure modes & `ALLOW_EMPTY_FEEDS` configuration.
    - [ ] Create `docs/runbooks/scheduler-troubleshooting.md` covering common failure scenarios and recovery steps.
    - [ ] Create `docs/runbooks/queue-troubleshooting.md` covering queue backlog management and failed job analysis.
    - [ ] Update `docs/backend/maintenance.md` to include Failed Job pruning policy.
    - [ ] Update `docs/backend/scene-intel.md` to document the retry policy and acceptable failure modes for Scene Intel.
    - [ ] Document persistent vs transient failure characteristics (24-hour mutex lockout cycles vs auto-recovery).
    - [ ] Document monitoring thresholds and alerting setup (queue depth, command failure rate, Scene Intel failure rate, etc.).
    - [ ] Document empty feed handling strategy and the `ALLOW_EMPTY_FEEDS` flag with usage guidance.
- [ ] Task: Conductor - User Manual Verification 'Phase 4: Quality & Documentation' (Protocol in workflow.md)
