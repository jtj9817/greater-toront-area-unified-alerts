# Implementation Plan: YRT Service Advisories Integration

## Phase 1: Database + Model

- [ ] Task: Red - Write failing persistence/model tests for YRT alerts
    - [ ] Add migration-focused tests for `yrt_alerts` schema, unique `external_id`, and required indexes.
    - [ ] Add model tests for `YrtAlert` casts and `scopeActive()` behavior.
    - [ ] Run focused Pest tests first and confirm red state.
- [ ] Task: Green - Implement `yrt_alerts` storage and model contracts
    - [ ] Create migration for `yrt_alerts` with planned columns/indexes.
    - [ ] Implement `app/Models/YrtAlert.php` with fillable fields, casts, and active scope.
    - [ ] Re-run focused tests to reach green.
- [ ] Task: Refactor - Harden schema/model clarity while tests stay green
    - [ ] Remove duplication and align naming/casts with other transit alert models.
    - [ ] Re-run focused suite and confirm no regressions.
- [ ] Task: Conductor - User Manual Verification 'Phase 1: Database + Model' (Protocol in workflow.md)

## Phase 2: Feed Service (JSON + Optional Detail HTML)

- [ ] Task: Red - Write failing feed-service tests
    - [ ] Add tests for JSON payload normalization, UTC timestamp parsing, and `external_id` slug extraction.
    - [ ] Add tests for `list_hash` generation and detail-fetch eligibility rules.
    - [ ] Add failure-mode tests for timeout/network errors, invalid payloads, and empty-feed responses.
    - [ ] Run focused Pest tests and confirm red state.
- [ ] Task: Green - Implement `YrtServiceAdvisoriesFeedService`
    - [ ] Create `app/Services/YrtServiceAdvisoriesFeedService.php` using timeout/retry + circuit-breaker patterns.
    - [ ] Implement list-feed fetch + normalization and guarded detail-page enrichment flow.
    - [ ] Return normalized result shape with `updated_at` and `alerts`.
    - [ ] Re-run focused tests to reach green.
- [ ] Task: Refactor - Improve parsing helpers and resilience ergonomics
    - [ ] Extract helpers for route text parsing, whitespace normalization, and detail HTML extraction.
    - [ ] Re-run focused suite and confirm no behavior regressions.
- [ ] Task: Conductor - User Manual Verification 'Phase 2: Feed Service (JSON + Optional Detail HTML)' (Protocol in workflow.md)

## Phase 3: Fetch Command (Sync + Notifications)

- [ ] Task: Red - Write failing command lifecycle tests
    - [ ] Add tests for upsert of active advisories and feed timestamp persistence.
    - [ ] Add tests for stale-record deactivation when advisory IDs disappear from the latest feed.
    - [ ] Add tests for `AlertCreated` dispatch on create/reactivate paths.
    - [ ] Run focused Pest tests and confirm red state.
- [ ] Task: Green - Implement `yrt:fetch-alerts` command
    - [ ] Create `app/Console/Commands/FetchYrtAlertsCommand.php` and wire feed-service integration.
    - [ ] Implement upsert + stale deactivation semantics.
    - [ ] Extend notification factory path for `YrtAlert` if required by event flow.
    - [ ] Re-run focused tests to reach green.
- [ ] Task: Refactor - Consolidate sync logic and output clarity
    - [ ] Improve command/service boundaries and reduce repeated field mapping code.
    - [ ] Re-run focused suite and confirm no regressions.
- [ ] Task: Conductor - User Manual Verification 'Phase 3: Fetch Command (Sync + Notifications)' (Protocol in workflow.md)

## Phase 4: Queue Job Wrapper + Scheduler

- [ ] Task: Red - Write failing queue/scheduler tests
    - [ ] Add tests asserting job wrapper calls `yrt:fetch-alerts` and throws on non-zero Artisan exits.
    - [ ] Add tests for `ScheduledFetchJobDispatcher::dispatchYrtAlerts()` return/dispatch behavior.
    - [ ] Add scheduler registration assertions for five-minute cadence and overlap protection.
    - [ ] Run focused Pest tests and confirm red state.
- [ ] Task: Green - Implement YRT job + scheduler wiring
    - [ ] Create `app/Jobs/FetchYrtAlertsJob.php` with uniqueness and overlap middleware.
    - [ ] Update `app/Services/ScheduledFetchJobDispatcher.php` with `dispatchYrtAlerts()`.
    - [ ] Register schedule callback in `routes/console.php`.
    - [ ] Re-run focused tests to reach green.
- [ ] Task: Refactor - Align scheduler/job consistency with existing sources
    - [ ] Ensure naming, cadence, and guardrails match TTC/GO conventions.
    - [ ] Re-run focused suite and confirm no regressions.
- [ ] Task: Conductor - User Manual Verification 'Phase 4: Queue Job Wrapper + Scheduler' (Protocol in workflow.md)

## Phase 5: Unified Alerts Provider

- [ ] Task: Red - Write failing provider/query contract tests
    - [ ] Add tests for `YrtAlertSelectProvider` unified select columns and provider `id` composition.
    - [ ] Add tests for criteria filters (`source`, `status`, `sinceCutoff`, `query`) matching existing provider semantics.
    - [ ] Add tests validating `meta` payload fields expected by downstream resources.
    - [ ] Run focused Pest tests and confirm red state.
- [ ] Task: Green - Implement `YrtAlertSelectProvider` and registration
    - [ ] Create `app/Services/Alerts/Providers/YrtAlertSelectProvider.php`.
    - [ ] Implement driver-safe ID concat and unified timestamp/metadata selection.
    - [ ] Register provider in `app/Providers/AppServiceProvider.php` under `alerts.select-providers`.
    - [ ] Re-run focused tests to reach green.
- [ ] Task: Refactor - Reduce provider query complexity and improve readability
    - [ ] Extract repeated query fragments and align SQL JSON/meta shaping patterns with sibling providers.
    - [ ] Re-run focused suite and confirm no regressions.
- [ ] Task: Conductor - User Manual Verification 'Phase 5: Unified Alerts Provider' (Protocol in workflow.md)

## Phase 6: Alert Source + Backend Contract Plumbing

- [ ] Task: Red - Write failing backend contract tests for YRT source identity
    - [ ] Add tests ensuring unified resources expose source as `yrt`.
    - [ ] Add tests ensuring YRT rows pass through unified endpoint/resource flow.
    - [ ] Add regression tests for existing source enum values.
    - [ ] Run focused Pest tests and confirm red state.
- [ ] Task: Green - Implement enum + backend source plumbing
    - [ ] Add `Yrt` case to `app/Enums/AlertSource.php`.
    - [ ] Update backend switch/validation paths that require explicit source registration.
    - [ ] Re-run focused tests to reach green.
- [ ] Task: Refactor - Tighten source normalization and contract consistency
    - [ ] Simplify source-handling branches while preserving API contracts.
    - [ ] Re-run focused suite and confirm no regressions.
- [ ] Task: Conductor - User Manual Verification 'Phase 6: Alert Source + Backend Contract Plumbing' (Protocol in workflow.md)

## Phase 7: Frontend Domain Integration

- [ ] Task: Red - Write failing frontend mapping/presentation tests for YRT
    - [ ] Add mapper tests for `fromResource()` handling source `yrt`.
    - [ ] Add tests for `mapYrtAlert()` output shape and metadata fallbacks.
    - [ ] Add presentation mapping tests to ensure YRT uses existing transit presentation helpers.
    - [ ] Run focused frontend tests and confirm red state.
- [ ] Task: Green - Implement frontend YRT domain mapping
    - [ ] Create YRT schema/mapper files under `resources/js/features/gta-alerts/domain/alerts/transit/yrt/`.
    - [ ] Register `yrt` in resource/source types, domain unions, and `fromResource.ts`.
    - [ ] Wire YRT into presentation mapping without affecting existing source behavior.
    - [ ] Re-run focused frontend tests to reach green.
- [ ] Task: Refactor - Improve mapper/type ergonomics with tests green
    - [ ] Remove mapping duplication and align naming with DRT/MiWay patterns.
    - [ ] Re-run focused frontend suite and confirm no regressions.
- [ ] Task: Conductor - User Manual Verification 'Phase 7: Frontend Domain Integration' (Protocol in workflow.md)

## Phase 8: Quality + Documentation Closeout

- [ ] Task: Red - Run quality gates and capture failing gaps
    - [ ] Execute targeted backend/frontend suites for phases 1-7 and note failures.
    - [ ] Run full-suite and tooling gates (`artisan test`, coverage, typecheck, lint, format check, audits) and capture any red results.
- [ ] Task: Green - Resolve all gate failures until track is releasable
    - [ ] Fix regressions discovered during quality gates and re-run impacted suites.
    - [ ] Achieve passing results for full quality gate set including coverage threshold.
- [ ] Task: Refactor - Final cleanup and artifact reconciliation
    - [ ] Update docs (`docs/`, `README.md`, `CLAUDE.md`) only where durable behavior/ops usage changed.
    - [ ] Reconcile final implementation notes in this track's `spec.md` and `plan.md`.
    - [ ] Prepare `metadata.json` and registry status updates for archive handoff when implementation completes.
- [ ] Task: Conductor - User Manual Verification 'Phase 8: Quality + Documentation Closeout' (Protocol in workflow.md)
