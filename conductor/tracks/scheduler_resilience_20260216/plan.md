# Implementation Plan - Scheduler Resilience Overhaul

This plan addresses critical stability and resilience issues in the GTA Alerts scheduler, implementing top-level exception handling, job-based architecture, and robust data validation.

## Phase 1: Critical Fixes & Foundation
**Goal:** Eliminate silent failures, prevent 24-hour mutex lockouts, and establish basic monitoring.

- [ ] Task: Scheduler - Add Top-Level Exception Handling (Fire)
    - [ ] Wrap `FetchFireIncidentsCommand::handle` in try-catch.
    - [ ] Log full exception details.
    - [ ] Ensure `Command::FAILURE` is returned.
- [ ] Task: Scheduler - Add Top-Level Exception Handling (Police)
    - [ ] Wrap `FetchPoliceCallsCommand::handle` in try-catch.
    - [ ] Log full exception details.
    - [ ] Ensure `Command::FAILURE` is returned.
    - [ ] Add `withoutOverlapping(600)` to `police:fetch-calls` schedule in `routes/console.php`.
- [ ] Task: Scheduler - Add Top-Level Exception Handling (GO Transit)
    - [ ] Wrap `FetchGoTransitAlertsCommand::handle` in try-catch.
    - [ ] Log full exception details.
    - [ ] Ensure `Command::FAILURE` is returned.
- [ ] Task: Scheduler - Add Top-Level Exception Handling (TTC Transit)
    - [ ] Wrap `FetchTransitAlertsCommand::handle` in try-catch.
    - [ ] Log full exception details.
    - [ ] Ensure `Command::FAILURE` is returned.
- [ ] Task: Monitoring - Implement Queue Depth Check
    - [ ] Create scheduled closure in `routes/console.php` to check `jobs` table count.
    - [ ] Log error if depth > 100.
- [ ] Task: Conductor - User Manual Verification 'Phase 1: Critical Fixes & Foundation' (Protocol in workflow.md)

## Phase 2: Resilience & Architecture Upgrade
**Goal:** Migrate to Job-based scheduling for retries and implement graceful degradation.

- [ ] Task: Architecture - Migrate Fire Fetch to Job
    - [ ] Update `FetchFireIncidentsJob` with `$tries=3`, `$backoff=30`, `$timeout=120`.
    - [ ] Update `routes/console.php` to schedule `FetchFireIncidentsJob` instead of command.
- [ ] Task: Architecture - Migrate Police Fetch to Job
    - [ ] Update `FetchPoliceCallsJob` with `$tries=3`, `$backoff=30`, `$timeout=120`.
    - [ ] Update `routes/console.php` to schedule `FetchPoliceCallsJob` instead of command.
- [ ] Task: Architecture - Migrate GO Transit Fetch to Job
    - [ ] Update `FetchGoTransitAlertsJob` with `$tries=3`, `$backoff=30`, `$timeout=120`.
    - [ ] Update `routes/console.php` to schedule `FetchGoTransitAlertsJob` instead of command.
- [ ] Task: Architecture - Migrate TTC Transit Fetch to Job
    - [ ] Update `FetchTransitAlertsJob` with `$tries=3`, `$backoff=30`, `$timeout=120`.
    - [ ] Update `routes/console.php` to schedule `FetchTransitAlertsJob` instead of command.
- [ ] Task: Resilience - Implement Empty Feed Protection (Environment)
    - [ ] Add `ALLOW_EMPTY_FEEDS` to `.env.example`.
    - [ ] Update `config/app.php` (or relevant config) to read `ALLOW_EMPTY_FEEDS`.
- [ ] Task: Resilience - Implement Empty Feed Validation (Services)
    - [ ] Update `TorontoFireFeedService` to throw if empty & !allowed.
    - [ ] Update `TorontoPoliceFeedService` to throw if empty & !allowed.
    - [ ] Update `GoTransitFeedService` to throw if empty & !allowed.
    - [ ] Update `TtcAlertsFeedService` to throw if empty & !allowed.
- [ ] Task: Resilience - Implement Graceful Record Parsing
    - [ ] Wrap record parsing loops in `FetchFireIncidentsCommand` (and others) with try-catch.
    - [ ] Log warning on individual record failure, continue batch.
- [ ] Task: Resilience - Configure Notification Job Retry
    - [ ] Update `DeliverAlertNotificationJob` with `$tries=5`, `$backoff=10`.
- [ ] Task: Conductor - User Manual Verification 'Phase 2: Resilience & Architecture Upgrade' (Protocol in workflow.md)

## Phase 3: Data Integrity & Maintenance
**Goal:** Ensure data quality and long-term system health.

- [ ] Task: Data Integrity - Timestamp Sanity Checks
    - [ ] Add warning logic for future timestamps (>15 min) in all parsers.
    - [ ] Add warning logic for unreasonable coordinates (outside GTA) in Police/Fire parsers.
- [ ] Task: Maintenance - Failed Job Pruning
    - [ ] Add scheduled command `queue:prune-failed --hours=168` to `routes/console.php`.
- [ ] Task: Resilience - Implement Circuit Breaker
    - [ ] Add basic circuit breaker logic (cache-based counter) to `TorontoFireFeedService` (and others).
    - [ ] Threshold: 5 failures, TTL: 5 minutes.
- [ ] Task: Documentation - Update System Docs
    - [ ] Update `docs/backend/production-scheduler.md` with new failure modes & `ALLOW_EMPTY_FEEDS`.
    - [ ] Create/Update runbooks for Queue/Scheduler troubleshooting.
- [ ] Task: Conductor - User Manual Verification 'Phase 3: Data Integrity & Maintenance' (Protocol in workflow.md)
