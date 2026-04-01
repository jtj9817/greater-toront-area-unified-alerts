# Implementation Plan: YRT Service Advisories Integration

## Phase 1: Database + Model

- [ ] Task: Red - Write failing persistence and model tests for YRT
    - [ ] Create `tests/Unit/Models/YrtAlertTest.php` with schema expectations for required columns.
    - [ ] Add assertions for unique `external_id` and indexes on `posted_at`, `feed_updated_at`, and (`is_active`, `posted_at`).
    - [ ] Add model behavior tests for `fillable`, casts, and `scopeActive()` filtering.
    - [ ] Run focused tests and confirm red state before implementation.
- [ ] Task: Green - Implement `yrt_alerts` migration and `YrtAlert` model
    - [ ] Add migration for `yrt_alerts` with exact spec fields and index definitions.
    - [ ] Add `app/Models/YrtAlert.php` with required fillable/casts/scope contracts.
    - [ ] Add/update factory support if needed for deterministic tests.
    - [ ] Re-run focused tests until green.
- [ ] Task: Refactor - Normalize model and schema conventions
    - [ ] Align naming/cast conventions with existing transit alert models.
    - [ ] Remove duplicate setup code from tests using datasets/helpers.
    - [ ] Re-run focused suite and verify no regression.
- [ ] Task: Conductor - User Manual Verification 'Phase 1: Database + Model' (Protocol in workflow.md)

## Phase 2: Feed Service (List JSON + Conditional Detail HTML)

- [ ] Task: Red - Write failing service tests for normalization and resilience
    - [ ] Create `tests/Feature/YrtServiceAdvisoriesFeedServiceTest.php` with JSON-list happy-path assertions.
    - [ ] Add tests for slug extraction, Toronto-to-UTC timestamp parsing, and deterministic `list_hash` generation.
    - [ ] Add detail-fetch decision tests (new alert, changed hash, missing body, stale `details_fetched_at`, and skip path).
    - [ ] Add failure-mode tests for network errors, malformed JSON, malformed HTML, and empty payload behavior.
    - [ ] Add tests for circuit-breaker success/failure recording behavior.
    - [ ] Run focused tests and confirm red state.
- [ ] Task: Green - Implement `YrtServiceAdvisoriesFeedService`
    - [ ] Add `app/Services/YrtServiceAdvisoriesFeedService.php` with timeout/retry and feed-circuit-breaker integration.
    - [ ] Implement list fetch + normalization into a deterministic alert array contract.
    - [ ] Implement conditional detail fetch + HTML text extraction with defensive parsing.
    - [ ] Return normalized shape: `updated_at` (UTC) and `alerts`.
    - [ ] Re-run focused tests until green.
- [ ] Task: Refactor - Extract parsing and decision helpers
    - [ ] Extract helper methods for route parsing, text normalization, and detail fetch eligibility.
    - [ ] Ensure branch coverage for skip/fetch decision logic remains intact.
    - [ ] Re-run focused suite and verify no regression.
- [ ] Task: Conductor - User Manual Verification 'Phase 2: Feed Service (List JSON + Conditional Detail HTML)' (Protocol in workflow.md)

## Phase 3: Fetch Command (Sync + Notifications)

- [ ] Task: Red - Write failing command tests for sync lifecycle
    - [ ] Create `tests/Feature/Commands/FetchYrtAlertsCommandTest.php` with upsert expectations for active advisories.
    - [ ] Add stale deactivation tests (missing ids from latest feed become inactive).
    - [ ] Add idempotency tests for repeated unchanged runs.
    - [ ] Add tests for `AlertCreated` dispatch only on create/reactivate transitions.
    - [ ] Run focused tests and confirm red state.
- [ ] Task: Green - Implement `yrt:fetch-alerts` command path
    - [ ] Add `app/Console/Commands/FetchYrtAlertsCommand.php` wired to `YrtServiceAdvisoriesFeedService`.
    - [ ] Implement upsert/deactivation logic and UTC `feed_updated_at` persistence.
    - [ ] Extend `NotificationAlertFactory` with `fromYrtAlert()` mapping if missing.
    - [ ] Re-run focused tests until green.
- [ ] Task: Refactor - Tighten command safety and readability
    - [ ] Consolidate field assignment/update logic to avoid divergence between create/update paths.
    - [ ] Improve command output/summary messaging for operational debugging.
    - [ ] Re-run focused suite and verify no regression.
- [ ] Task: Conductor - User Manual Verification 'Phase 3: Fetch Command (Sync + Notifications)' (Protocol in workflow.md)

## Phase 4: Queue Job Wrapper + Scheduler

- [ ] Task: Red - Write failing job and scheduler tests
    - [ ] Create `tests/Feature/Jobs/FetchYrtAlertsJobTest.php` for command invocation and non-zero exit failure behavior.
    - [ ] Extend/cover `tests/Feature/Console/ScheduledFetchJobDispatcherTest.php` for `dispatchYrtAlerts()`.
    - [ ] Add scheduler registration assertions for five-minute cadence and overlap protection.
    - [ ] Add uniqueness/idempotency checks for job dispatch behavior.
    - [ ] Run focused tests and confirm red state.
- [ ] Task: Green - Implement YRT job and schedule wiring
    - [ ] Add `app/Jobs/FetchYrtAlertsJob.php` implementing queue, unique, and overlap middleware contracts.
    - [ ] Update `app/Services/ScheduledFetchJobDispatcher.php` with `dispatchYrtAlerts()`.
    - [ ] Update `routes/console.php` schedule callback for the YRT dispatcher path.
    - [ ] Re-run focused tests until green.
- [ ] Task: Refactor - Align scheduler ergonomics with transit patterns
    - [ ] Match job naming and middleware ordering with existing TTC/GO/MiWay wrappers.
    - [ ] Remove duplicated scheduler configuration logic where possible.
    - [ ] Re-run focused suite and verify no regression.
- [ ] Task: Conductor - User Manual Verification 'Phase 4: Queue Job Wrapper + Scheduler' (Protocol in workflow.md)

## Phase 5: Unified Alerts Provider

- [ ] Task: Red - Write failing provider contract tests
    - [ ] Create `tests/Unit/Services/Alerts/Providers/YrtAlertSelectProviderTest.php` for unified column contract.
    - [ ] Add tests for source-prefixed id expression across sqlite/pgsql/mysql query paths.
    - [ ] Add tests for criteria filtering (`source`, `status`, `sinceCutoff`, `query`).
    - [ ] Add tests asserting `meta` contains expected YRT keys and null-safe values.
    - [ ] Run focused tests and confirm red state.
- [ ] Task: Green - Implement and register `YrtAlertSelectProvider`
    - [ ] Add `app/Services/Alerts/Providers/YrtAlertSelectProvider.php` with driver-safe SQL expressions.
    - [ ] Tag provider in `app/Providers/AppServiceProvider.php` under `alerts.select-providers`.
    - [ ] Ensure timestamp and nullable location semantics match unified contract.
    - [ ] Re-run focused tests until green.
- [ ] Task: Refactor - Reduce query complexity and improve maintainability
    - [ ] Extract shared SQL expression building where local duplication appears.
    - [ ] Keep provider-specific behavior isolated to YRT-only metadata logic.
    - [ ] Re-run focused suite and verify no regression.
- [ ] Task: Conductor - User Manual Verification 'Phase 5: Unified Alerts Provider' (Protocol in workflow.md)

## Phase 6: Source Enum + Backend Contract Plumbing

- [ ] Task: Red - Write failing backend contract tests for `yrt`
    - [ ] Extend `tests/Unit/Enums/AlertSourceTest.php` to require `yrt` in enum values and validation paths.
    - [ ] Add/extend feed API contract tests for `source=yrt` filtering and status behavior.
    - [ ] Add regression tests proving existing sources remain unaffected.
    - [ ] Run focused tests and confirm red state.
- [ ] Task: Green - Implement source identity plumbing
    - [ ] Add `Yrt = 'yrt'` to `app/Enums/AlertSource.php`.
    - [ ] Update backend mapper/resource boundaries that require explicit source allow-listing.
    - [ ] Re-run focused tests until green.
- [ ] Task: Refactor - Consolidate source handling consistency
    - [ ] Remove duplicated source checks where a shared enum/path can be reused.
    - [ ] Re-run focused suite and verify no regression.
- [ ] Task: Conductor - User Manual Verification 'Phase 6: Source Enum + Backend Contract Plumbing' (Protocol in workflow.md)

## Phase 7: Frontend Domain + Presentation Integration

- [ ] Task: Red - Write failing frontend tests for YRT mapping and presentation
    - [ ] Add `resources/js/features/gta-alerts/domain/alerts/transit/yrt/mapper.test.ts` for valid and invalid resource mapping.
    - [ ] Add schema tests for required/optional YRT meta fields and fallback behavior.
    - [ ] Add tests for `fromResource()` switch handling `source: 'yrt'`.
    - [ ] Add presentation mapping tests ensuring YRT uses shared transit presentation logic.
    - [ ] Run focused frontend tests and confirm red state.
- [ ] Task: Green - Implement YRT frontend domain and wiring
    - [ ] Add `schema.ts` and `mapper.ts` under `resources/js/features/gta-alerts/domain/alerts/transit/yrt/`.
    - [ ] Register `yrt` in `resource.ts`, domain unions (`types.ts`), and `fromResource.ts`.
    - [ ] Update presentation mapping to include YRT without TTC-specific coupling.
    - [ ] Re-run focused frontend tests until green.
- [ ] Task: Refactor - Unify transit-domain ergonomics
    - [ ] Deduplicate shared transit metadata formatting where possible.
    - [ ] Keep YRT-specific parsing isolated from shared rendering helpers.
    - [ ] Re-run focused frontend suite and verify no regression.
- [ ] Task: Conductor - User Manual Verification 'Phase 7: Frontend Domain + Presentation Integration' (Protocol in workflow.md)

## Phase 8: QA, Coverage, and Documentation Closeout

- [ ] Task: Red - Execute quality gates and capture all failures
    - [ ] Run targeted suites added in phases 1-7 and record failing tests first.
    - [ ] Run full backend quality gates via Sail (`artisan test`, coverage threshold, audits).
    - [ ] Run full frontend quality gates via Sail (`pnpm test`, `pnpm types`, `pnpm lint:check`, `pnpm format:check`).
    - [ ] Capture and classify failures by root cause before fixing.
- [ ] Task: Green - Resolve failures and reach releasable state
    - [ ] Fix backend/frontend regressions with minimal scope changes.
    - [ ] Re-run only impacted suites after each fix, then re-run full gates.
    - [ ] Confirm all mandatory gates pass, including coverage threshold.
- [ ] Task: Refactor - Final docs and conductor artifact reconciliation
    - [ ] Update durable docs where behavior/ops usage changed (`docs/`, `README.md`, `CLAUDE.md`) and avoid unnecessary churn.
    - [ ] Add final implementation notes and deviations to this track's `spec.md`/`plan.md`.
    - [ ] Update `metadata.json` (`updated_at`, status when appropriate) and prep archive handoff checklist.
- [ ] Task: Conductor - User Manual Verification 'Phase 8: QA, Coverage, and Documentation Closeout' (Protocol in workflow.md)
