# Implementation Plan: MiWay Service Alerts (GTFS-RT) Integration

## Phase 1: Database + Model [checkpoint: d6f8ddd]

- [x] Task: Write failing tests for MiWay alert persistence and model behavior
    - [x] Add tests for `miway_alerts` schema expectations, unique `external_id`, and index coverage.
    - [x] Add tests for `MiwayAlert` casts and `scopeActive()` behavior.
    - [x] Run focused Pest tests first and confirm red state.
- [x] Task: Implement `miway_alerts` storage and model contracts
    - [x] Create migration for `miway_alerts` with required columns and indexes from the implementation plan.
    - [x] Implement `app/Models/MiwayAlert.php` with typed casts and active scope.
    - [x] Re-run focused tests and bring them to green.
- [x] Task: Conductor - User Manual Verification 'Phase 1: Database + Model' [d6f8ddd]

## Phase 2: GTFS-RT Feed Service

- [ ] Task: Write failing feed-service tests (red phase)
    - [ ] Add tests for successful protobuf decode and normalization to persisted fields.
    - [ ] Add tests for conditional GET (`ETag` / `Last-Modified`) including `304 Not Modified`.
    - [ ] Add failure-mode tests for timeout/network errors, malformed protobuf, and empty payload handling.
- [ ] Task: Implement `MiwayGtfsRtAlertsFeedService` (green phase)
    - [ ] Create `app/Services/MiwayGtfsRtAlertsFeedService.php` with timeout/retry, circuit-breaker hooks, and conditional-fetch caching.
    - [ ] Decode GTFS-RT Alerts payload and normalize `external_id`, text, active period, cause/effect, URL, and detour PDF URL.
    - [ ] Return normalized result shape with `updated_at`, `alerts`, and optional `not_modified`.
- [ ] Task: Refactor feed service while keeping tests green
    - [ ] Extract translation/period parsing helpers for readability.
    - [ ] Confirm no behavior regressions via focused test rerun.
- [ ] Task: Conductor - User Manual Verification 'Phase 2: GTFS-RT Feed Service' (Protocol in workflow.md)

## Phase 3: Fetch Command (Sync + Notifications)

- [ ] Task: Write failing command tests for sync lifecycle
    - [ ] Add tests for upsert of active alerts and feed timestamp persistence.
    - [ ] Add tests for stale-record deactivation when IDs disappear from feed.
    - [ ] Add tests for early exit on `not_modified` and `AlertCreated` dispatch on create/reactivate.
- [ ] Task: Implement `miway:fetch-alerts` command
    - [ ] Create `app/Console/Commands/FetchMiwayAlertsCommand.php` and wire feed-service call.
    - [ ] Implement upsert/deactivate behavior and `not_modified` short-circuit.
    - [ ] Extend `NotificationAlertFactory` path for `MiwayAlert` if required by event flow.
- [ ] Task: Conductor - User Manual Verification 'Phase 3: Fetch Command (Sync + Notifications)' (Protocol in workflow.md)

## Phase 4: Queue Job Wrapper + Scheduler

- [ ] Task: Write failing tests for queue-dispatch scheduling behavior
    - [ ] Add tests asserting job wrapper invokes `miway:fetch-alerts` and throws on non-zero Artisan exit.
    - [ ] Add tests for `ScheduledFetchJobDispatcher::dispatchMiwayAlerts()` return/dispatch behavior.
    - [ ] Add scheduler registration assertions for 5-minute cadence with overlap protection.
- [ ] Task: Implement MiWay job + scheduler wiring
    - [ ] Create `app/Jobs/FetchMiwayAlertsJob.php` with uniqueness and overlap middleware.
    - [ ] Update `app/Services/ScheduledFetchJobDispatcher.php` with `dispatchMiwayAlerts()`.
    - [ ] Register schedule callback in `routes/console.php`.
- [ ] Task: Conductor - User Manual Verification 'Phase 4: Queue Job Wrapper + Scheduler' (Protocol in workflow.md)

## Phase 5: Unified Alerts Provider

- [ ] Task: Write failing provider/query contract tests
    - [ ] Add tests for `MiwayAlertSelectProvider` unified select columns and `id` composition.
    - [ ] Add tests for criteria filters (`source`, `status`, `sinceCutoff`, `query`) matching existing provider semantics.
    - [ ] Add tests validating `meta` payload fields expected by downstream resources.
- [ ] Task: Implement `MiwayAlertSelectProvider` and provider registration
    - [ ] Create `app/Services/Alerts/Providers/MiwayAlertSelectProvider.php`.
    - [ ] Implement driver-safe `id` concat and unified timestamp selection (`COALESCE(...)`).
    - [ ] Tag provider in `app/Providers/AppServiceProvider.php` under `alerts.select-providers`.
- [ ] Task: Conductor - User Manual Verification 'Phase 5: Unified Alerts Provider' (Protocol in workflow.md)

## Phase 6: Alert Source + API Contract Plumbing

- [ ] Task: Write failing backend contract tests for MiWay source identity
    - [ ] Add tests ensuring unified resources expose source as `miway`.
    - [ ] Add tests ensuring MiWay records pass through existing unified endpoint/resource flow.
    - [ ] Confirm no regressions for existing source enum values.
- [ ] Task: Implement enum and backend source plumbing
    - [ ] Add `Miway` case to `app/Enums/AlertSource.php`.
    - [ ] Update any backend switch/validation paths that require explicit source registration.
    - [ ] Re-run focused backend tests to green.
- [ ] Task: Conductor - User Manual Verification 'Phase 6: Alert Source + API Contract Plumbing' (Protocol in workflow.md)

## Phase 7: Frontend Domain Integration

- [ ] Task: Write failing frontend domain tests for MiWay mapping
    - [ ] Add mapper tests for `fromResource()` source switch handling `miway`.
    - [ ] Add tests for `mapMiwayAlert()` output shape and metadata fallbacks.
    - [ ] Add tests ensuring existing source mappers remain unchanged.
- [ ] Task: Implement frontend MiWay domain mapping
    - [ ] Implement `mapMiwayAlert()` and integrate it into unified frontend mapping flow.
    - [ ] Add/update MiWay domain types (`kind: 'miway'`) and presentation metadata mapping.
    - [ ] Ensure UI source filtering can treat MiWay independently from TTC/GO.
- [ ] Task: Conductor - User Manual Verification 'Phase 7: Frontend Domain Integration' (Protocol in workflow.md)

## Phase 8: QA Phase

- [ ] Task: Execute targeted automated test gates first
    - [ ] Run focused Pest suites added in Phases 1–7 using `vendor/bin/sail artisan test --compact`.
    - [ ] Run focused frontend/Vitest suites for MiWay mapper integration.
    - [ ] Resolve regressions before broad-suite execution.
- [ ] Task: Execute full project quality gates
    - [ ] Run `vendor/bin/sail artisan test --compact`.
    - [ ] Run `vendor/bin/sail artisan test --coverage --min=90`.
    - [ ] Run `vendor/bin/sail pnpm typecheck`, `vendor/bin/sail pnpm lint`, and `vendor/bin/sail pnpm format:check`.
    - [ ] Run `vendor/bin/sail composer audit` and `vendor/bin/sail pnpm audit`.
- [ ] Task: Conductor - User Manual Verification 'Phase 8: QA Phase' (Protocol in workflow.md)

## Phase 9: Documentation Phase

- [ ] Task: Update project documentation for MiWay integration
    - [ ] Document feed source, conditional-fetch behavior, and operational command usage in `docs/`.
    - [ ] Update `README.md` and `CLAUDE.md` only where user-facing capabilities or durable repo conventions changed.
    - [ ] Document any implementation deviations from this track spec/plan.
- [ ] Task: Prepare conductor artifacts for closeout
    - [ ] Reconcile final implementation notes into `spec.md` and `plan.md`.
    - [ ] Update `metadata.json`/registry status when ready for archive handoff.
- [ ] Task: Conductor - User Manual Verification 'Phase 9: Documentation Phase' (Protocol in workflow.md)
