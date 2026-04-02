# Implementation Plan: YRT Service Advisories Integration

## Phase 1: Database + Model

- [x] Task: Red - Write failing persistence and model tests for YRT (d57877b)
    - [x] Create `tests/Unit/Models/YrtAlertTest.php` with schema expectations for required columns.
    - [x] Add assertions for unique `external_id` and indexes on `posted_at`, `feed_updated_at`, and (`is_active`, `posted_at`).
    - [x] Add model behavior tests for `fillable`, casts, and `scopeActive()` filtering.
    - [x] Run focused tests and confirm red state before implementation.
- [x] Task: Green - Implement `yrt_alerts` migration and `YrtAlert` model (d57877b)
    - [x] Add migration for `yrt_alerts` with exact spec fields and index definitions.
    - [x] Add `app/Models/YrtAlert.php` with required fillable/casts/scope contracts.
    - [x] Add/update factory support if needed for deterministic tests.
    - [x] Re-run focused tests until green.
- [x] Task: Refactor - Normalize model and schema conventions (d57877b)
    - [x] Align naming/cast conventions with existing transit alert models.
    - [x] Remove duplicate setup code from tests using datasets/helpers.
    - [x] Re-run focused suite and verify no regression.
- [ ] Task: Conductor - User Manual Verification 'Phase 1: Database + Model' (Protocol in workflow.md)

## Phase 2: Feed Service (List JSON + Conditional Detail HTML)

- [x] Task: Red - Write failing service tests for normalization and resilience (baf2145)
    - [x] Create `tests/Feature/YrtServiceAdvisoriesFeedServiceTest.php` with JSON-list happy-path assertions.
    - [x] Add tests for slug extraction, Toronto-to-UTC timestamp parsing, and deterministic `list_hash` generation.
    - [x] Add detail-fetch decision tests (new alert, changed hash, missing body, stale `details_fetched_at`, and skip path).
    - [x] Add failure-mode tests for network errors, malformed JSON, malformed HTML, and empty payload behavior.
    - [x] Add tests for circuit-breaker success/failure recording behavior.
    - [x] Run focused tests and confirm red state.
- [x] Task: Green - Implement `YrtServiceAdvisoriesFeedService` (baf2145)
    - [x] Add `app/Services/YrtServiceAdvisoriesFeedService.php` with timeout/retry and feed-circuit-breaker integration.
    - [x] Implement list fetch + normalization into a deterministic alert array contract.
    - [x] Implement conditional detail fetch + HTML text extraction with defensive parsing.
    - [x] Return normalized shape: `updated_at` (UTC) and `alerts`.
    - [x] Re-run focused tests until green.
- [x] Task: Refactor - Extract parsing and decision helpers (baf2145)
    - [x] Extract helper methods for route parsing, text normalization, and detail fetch eligibility.
    - [x] Ensure branch coverage for skip/fetch decision logic remains intact.
    - [x] Re-run focused suite and verify no regression.
- [x] Task: Conductor - User Manual Verification 'Phase 2: Feed Service (List JSON + Conditional Detail HTML)' (Protocol in workflow.md) (c005c77)

## Phase 3: Fetch Command (Sync + Notifications)

- [x] Task: Red - Write failing command tests for sync lifecycle (d0e3faf)
    - [x] Create `tests/Feature/Commands/FetchYrtAlertsCommandTest.php` with upsert expectations for active advisories.
    - [x] Add stale deactivation tests (missing ids from latest feed become inactive).
    - [x] Add idempotency tests for repeated unchanged runs.
    - [x] Add tests for `AlertCreated` dispatch only on create/reactivate transitions.
    - [x] Run focused tests and confirm red state.
- [x] Task: Green - Implement `yrt:fetch-alerts` command path (d0e3faf)
    - [x] Add `app/Console/Commands/FetchYrtAlertsCommand.php` wired to `YrtServiceAdvisoriesFeedService`.
    - [x] Implement upsert/deactivation logic and UTC `feed_updated_at` persistence.
    - [x] Extend `NotificationAlertFactory` with `fromYrtAlert()` mapping if missing.
    - [x] Re-run focused tests until green.
- [x] Task: Refactor - Tighten command safety and readability (d0e3faf)
    - [x] Consolidate field assignment/update logic to avoid divergence between create/update paths.
    - [x] Improve command output/summary messaging for operational debugging.
    - [x] Re-run focused suite and verify no regression.
- [x] Task: Conductor - User Manual Verification 'Phase 3: Fetch Command (Sync + Notifications)' (Protocol in workflow.md) (8f75fd5)

## Phase 4: Queue Job Wrapper + Scheduler

- [x] Task: Red - Write failing job and scheduler tests (e75339c)
    - [x] Create `tests/Feature/Jobs/FetchYrtAlertsJobTest.php` for command invocation and non-zero exit failure behavior.
    - [x] Extend/cover `tests/Feature/Console/ScheduledFetchJobDispatcherTest.php` for `dispatchYrtAlerts()`.
    - [x] Add scheduler registration assertions for five-minute cadence and overlap protection.
    - [x] Add uniqueness/idempotency checks for job dispatch behavior.
    - [x] Run focused tests and confirm red state.
- [x] Task: Green - Implement YRT job and schedule wiring (e75339c)
    - [x] Add `app/Jobs/FetchYrtAlertsJob.php` implementing queue, unique, and overlap middleware contracts.
    - [x] Update `app/Services/ScheduledFetchJobDispatcher.php` with `dispatchYrtAlerts()`.
    - [x] Update `routes/console.php` schedule callback for the YRT dispatcher path.
    - [x] Re-run focused tests until green.
- [x] Task: Refactor - Align scheduler ergonomics with transit patterns (e75339c)
    - [x] Match job naming and middleware ordering with existing TTC/GO/MiWay wrappers.
    - [x] Remove duplicated scheduler configuration logic where possible.
    - [x] Re-run focused suite and verify no regression.
- [x] Task: Conductor - User Manual Verification 'Phase 4: Queue Job Wrapper + Scheduler' (Protocol in workflow.md) (d3b050e)

## Phase 5: Unified Alerts Provider

- [x] Task: Red - Write failing provider contract tests (d2236fa)
    - [x] Create `tests/Unit/Services/Alerts/Providers/YrtAlertSelectProviderTest.php` for unified column contract.
    - [x] Add tests for source-prefixed id expression across sqlite/pgsql/mysql query paths.
    - [x] Add tests for criteria filtering (`source`, `status`, `sinceCutoff`, `query`).
    - [x] Add tests asserting `meta` contains expected YRT keys and null-safe values.
    - [x] Run focused tests and confirm red state.
- [x] Task: Green - Implement and register `YrtAlertSelectProvider` (d2236fa)
    - [x] Add `app/Services/Alerts/Providers/YrtAlertSelectProvider.php` with driver-safe SQL expressions.
    - [x] Tag provider in `app/Providers/AppServiceProvider.php` under `alerts.select-providers`.
    - [x] Ensure timestamp and nullable location semantics match unified contract.
    - [x] Re-run focused tests until green.
- [x] Task: Refactor - Reduce query complexity and improve maintainability (d2236fa)
    - [x] Extract shared SQL expression building where local duplication appears.
    - [x] Keep provider-specific behavior isolated to YRT-only metadata logic.
    - [x] Re-run focused suite and verify no regression.
- [x] Task: Conductor - User Manual Verification 'Phase 5: Unified Alerts Provider' (Protocol in workflow.md)

## Phase 6: Source Enum + Backend Contract Plumbing

- [x] Task: Red - Write failing backend contract tests for `yrt` (4163978)
    - [x] Extend `tests/Unit/Enums/AlertSourceTest.php` to require `yrt` in enum values and validation paths.
    - [x] Add/extend feed API contract tests for `source=yrt` filtering and status behavior.
    - [x] Add regression tests proving existing sources remain unaffected.
    - [x] Run focused tests and confirm red state.
- [x] Task: Green - Implement source identity plumbing (4163978)
    - [x] Add `Yrt = 'yrt'` to `app/Enums/AlertSource.php`.
    - [x] Update backend mapper/resource boundaries that require explicit source allow-listing.
    - [x] Re-run focused tests until green.
- [x] Task: Refactor - Consolidate source handling consistency (4163978)
    - [x] Remove duplicated source checks where a shared enum/path can be reused.
    - [x] Re-run focused suite and verify no regression.
- [x] Task: Conductor - User Manual Verification 'Phase 6: Source Enum + Backend Contract Plumbing' (Protocol in workflow.md) (5f4e232)

## Phase 7: Frontend Domain + Presentation Integration

- [x] Task: Red - Write failing frontend tests for YRT mapping and presentation (ccd2524)
    - [x] Add `resources/js/features/gta-alerts/domain/alerts/transit/yrt/mapper.test.ts` for valid and invalid resource mapping.
    - [x] Add schema tests for required/optional YRT meta fields and fallback behavior.
    - [x] Add tests for `fromResource()` switch handling `source: 'yrt'`.
    - [x] Add presentation mapping tests ensuring YRT uses shared transit presentation logic.
    - [x] Run focused frontend tests and confirm red state.
- [x] Task: Green - Implement YRT frontend domain and wiring (ccd2524)
    - [x] Add `schema.ts` and `mapper.ts` under `resources/js/features/gta-alerts/domain/alerts/transit/yrt/`.
    - [x] Register `yrt` in `resource.ts`, domain unions (`types.ts`), and `fromResource.ts`.
    - [x] Update presentation mapping to include YRT without TTC-specific coupling.
    - [x] Re-run focused frontend tests until green.
- [x] Task: Refactor - Unify transit-domain ergonomics (ccd2524)
    - [x] Deduplicate shared transit metadata formatting where possible.
    - [x] Keep YRT-specific parsing isolated from shared rendering helpers.
    - [x] Re-run focused frontend suite and verify no regression.
- [x] Task: Conductor - User Manual Verification 'Phase 7: Frontend Domain + Presentation Integration' (Protocol in workflow.md) (8bd05a8)

## Phase 8: QA Phase [checkpoint: c1d077f]

- [x] Task: Red - Execute quality gates and capture all failures (00e2b68)
    - [x] Run targeted suites added in phases 1-7 and record failing tests first.
    - [x] Run full backend quality gates via Sail (`artisan test`, coverage threshold, audits).
    - [x] Run full frontend quality gates via Sail (`pnpm test`, `pnpm types`, `pnpm lint:check`, `pnpm format:check`).
    - [x] Capture and classify failures by root cause before fixing.
- [x] Task: Green - Resolve failures and reach releasable state (00e2b68)
    - [x] Fix backend/frontend regressions with minimal scope changes.
    - [x] Re-run only impacted suites after each fix, then re-run full gates.
    - [x] Confirm all mandatory gates pass, including coverage threshold.
- [x] Task: Conductor - User Manual Verification 'Phase 8: QA Phase' (Protocol in workflow.md) (c1d077f)

## Phase 9: Documentation Phase

- [ ] Task: Build a docs impact matrix from implemented YRT behavior
    - [ ] Verify which docs already mention source enums/provider coverage and require `yrt` expansion.
    - [ ] Classify each doc as `update existing` vs `create new` and record rationale directly in this plan/spec closeout notes.
    - [ ] Confirm no unrelated docs are changed.
- [ ] Task: Create new source documentation for YRT
    - [ ] Create `docs/sources/yrt.md` as the canonical YRT integration reference.
    - [ ] Document upstream endpoints (JSON list + detail page enrichment), normalization contract, conditional detail-fetch rules, sync semantics, and scheduler/job behavior.
    - [ ] Include operational usage commands (`yrt:fetch-alerts`, schedule visibility, active-record checks).
- [ ] Task: Update docs index and source catalog surfaces
    - [ ] Update `docs/README.md` documentation tree to include `sources/yrt.md`.
    - [ ] Update `docs/README.md` "Current System Scope", "Source Integration Docs", and "Implementation Status" to include YRT as implemented.
- [ ] Task: Update backend architecture and contract docs for YRT source parity
    - [ ] Update `docs/backend/unified-alerts-system.md` source coverage and feed-filter enum values to include `yrt`.
    - [ ] Update `docs/backend/enums.md` `AlertSource` snippets/value lists to include `Yrt = 'yrt'`.
    - [ ] Update `docs/backend/dtos.md` source allow-list references to include `yrt`.
    - [ ] Update `docs/backend/architecture-walkthrough.md` topology sections (feed service/command/job/provider/tagged provider list/scheduler examples) for YRT.
    - [ ] Update `docs/backend/unified-alerts-qa.md` source-set language and "add a new source" checklist to reflect YRT as now implemented.
- [ ] Task: Update backend persistence and operations docs for YRT
    - [ ] Update `docs/backend/database-schema.md` with `yrt_alerts` table schema, indexes, provider/timestamp notes, migration history entries, and related-doc links.
    - [ ] Update `docs/backend/production-scheduler.md` fetch cadence/examples where source lists are enumerated explicitly so YRT appears consistently.
    - [ ] Update `docs/backend/notification-system.md` transit-family subscription matching notes if YRT route metadata participates in subscription matching.
- [ ] Task: Update frontend docs for YRT domain/presentation coverage
    - [ ] Update `docs/frontend/alert-service.md` source-dispatch list to include `yrt`.
    - [ ] Update `docs/frontend/types.md` domain union and source-specific type sections to include YRT schema/mapper/meta expectations.
    - [ ] Update `docs/frontend/alert-location-map.md` source-coverage table for YRT no-coordinate behavior (unless implementation adds coordinates).
- [ ] Task: Update docs change log and cross-doc consistency
    - [ ] Add a dated `docs/CHANGELOG.md` entry summarizing all YRT documentation additions/updates.
    - [ ] Run a consistency sweep to ensure source sets align across docs (`fire`, `police`, `transit`, `go_transit`, `miway`, `yrt`) wherever applicable.
    - [ ] Validate internal doc links for any newly added `yrt` references.
- [ ] Task: Prepare conductor artifacts for closeout
    - [ ] Add final implementation notes and deviations to this track's `spec.md`/`plan.md`, including any docs deliberately deferred.
    - [ ] Update `metadata.json` (`updated_at`, status when appropriate) and prep archive handoff checklist.
- [ ] Task: Conductor - User Manual Verification 'Phase 9: Documentation Phase' (Protocol in workflow.md)
