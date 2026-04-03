# Implementation Plan: DRT Service Alerts Scraping Integration

## Phase 1: Database + Model

- [ ] Task: Red - Write failing persistence and model tests for DRT
  - [ ] Create `tests/Unit/Models/DrtAlertTest.php` with schema expectations for required columns.
  - [ ] Add assertions for unique `external_id` and indexes on `posted_at` and (`is_active`, `posted_at`).
  - [ ] Add model behavior tests for `fillable`, casts, and `scopeActive()` filtering.
  - [ ] Run focused tests and confirm red state before implementation.
- [ ] Task: Green - Implement `drt_alerts` migration and `DrtAlert` model
  - [ ] Add migration for `drt_alerts` with exact spec fields and index definitions.
  - [ ] Add `app/Models/DrtAlert.php` with required fillable/casts/scope contracts.
  - [ ] Add/update factory support if needed for deterministic tests.
  - [ ] Re-run focused tests until green.
- [ ] Task: Refactor - Normalize model and schema conventions
  - [ ] Align naming/cast conventions with existing transit alert models.
  - [ ] Remove duplicate setup code from tests using datasets/helpers.
  - [ ] Re-run focused suite and verify no regression.
- [ ] Task: Conductor - User Manual Verification 'Phase 1: Database + Model' (Protocol in workflow.md)

## Phase 2: Feed Service (HTML List + Conditional Detail HTML)

- [ ] Task: Red - Write failing feed-service tests for normalization and resilience
  - [ ] Create `tests/Feature/DrtServiceAlertsFeedServiceTest.php` covering:
    - [ ] list-page parsing (title, details_url, posted_at, when_text, route_text).
    - [ ] slug extraction into `external_id`.
    - [ ] Toronto-to-UTC timestamp parsing.
    - [ ] deterministic `list_hash` generation based on list signals.
    - [ ] pagination behavior + max page cap.
  - [ ] Add conditional detail-fetch decision tests (new alert, changed hash, missing body, stale `details_fetched_at`, and skip path).
  - [ ] Add failure-mode tests for network errors, malformed HTML, and empty list behavior (respecting `feeds.allow_empty_feeds`).
  - [ ] Add circuit-breaker success/failure recording behavior tests.
  - [ ] Run focused tests and confirm red state.
- [ ] Task: Green - Implement `DrtServiceAlertsFeedService`
  - [ ] Add `app/Services/DrtServiceAlertsFeedService.php` with timeout/retry and browser-ish headers.
  - [ ] Implement list fetch + DOM parsing into deterministic alert array contract.
  - [ ] Implement pagination traversal with a hard max page guard.
  - [ ] Implement conditional detail fetch + content-block text extraction with defensive parsing.
  - [ ] Return normalized shape: `updated_at` (UTC) and `alerts`.
  - [ ] Re-run focused tests until green.
- [ ] Task: Refactor - Extract parsing and decision helpers
  - [ ] Extract helper methods for timestamp parsing, text normalization, `list_hash`, and detail fetch eligibility.
  - [ ] Ensure skip/fetch decision logic remains fully covered.
  - [ ] Re-run focused suite and verify no regression.
- [ ] Task: Conductor - User Manual Verification 'Phase 2: Feed Service (HTML List + Conditional Detail HTML)' (Protocol in workflow.md)

## Phase 3: Fetch Command (Sync + Notifications)

- [ ] Task: Red - Write failing command tests for sync lifecycle
  - [ ] Create `tests/Feature/Commands/FetchDrtAlertsCommandTest.php` with upsert expectations for active alerts.
  - [ ] Add stale deactivation tests (missing ids from latest scrape become inactive).
  - [ ] Add idempotency tests for repeated unchanged runs.
  - [ ] Add tests for `AlertCreated` dispatch only on create/reactivate transitions.
  - [ ] Run focused tests and confirm red state.
- [ ] Task: Green - Implement `drt:fetch-alerts` command path
  - [ ] Add `app/Console/Commands/FetchDrtAlertsCommand.php` wired to `DrtServiceAlertsFeedService`.
  - [ ] Implement upsert/deactivation logic and UTC `feed_updated_at` persistence.
  - [ ] Extend `NotificationAlertFactory` with `fromDrtAlert()` mapping if missing.
  - [ ] Re-run focused tests until green.
- [ ] Task: Refactor - Tighten command safety and readability
  - [ ] Consolidate field assignment/update logic to avoid divergence between create/update paths.
  - [ ] Improve command output/summary messaging for operational debugging.
  - [ ] Re-run focused suite and verify no regression.
- [ ] Task: Conductor - User Manual Verification 'Phase 3: Fetch Command (Sync + Notifications)' (Protocol in workflow.md)

## Phase 4: Queue Job Wrapper + Scheduler

- [ ] Task: Red - Write failing job and scheduler tests
  - [ ] Create `tests/Feature/Jobs/FetchDrtAlertsJobTest.php` for command invocation and non-zero exit failure behavior.
  - [ ] Extend/cover `tests/Feature/Console/ScheduledFetchJobDispatcherTest.php` for `dispatchDrtAlerts()`.
  - [ ] Add scheduler registration assertions for five-minute cadence and overlap protection.
  - [ ] Run focused tests and confirm red state.
- [ ] Task: Green - Implement DRT job and schedule wiring
  - [ ] Add `app/Jobs/FetchDrtAlertsJob.php` implementing queue, unique, and overlap middleware contracts.
  - [ ] Update `app/Services/ScheduledFetchJobDispatcher.php` with `dispatchDrtAlerts()`.
  - [ ] Update `routes/console.php` schedule callback for the DRT dispatcher path.
  - [ ] Re-run focused tests until green.
- [ ] Task: Refactor - Align scheduler ergonomics with transit patterns
  - [ ] Match job naming and middleware ordering with existing transit wrappers.
  - [ ] Remove duplicated scheduler configuration logic where possible.
  - [ ] Re-run focused suite and verify no regression.
- [ ] Task: Conductor - User Manual Verification 'Phase 4: Queue Job Wrapper + Scheduler' (Protocol in workflow.md)

## Phase 5: Unified Alerts Provider

- [ ] Task: Red - Write failing provider/query contract tests for DRT
  - [ ] Add tests for `DrtAlertSelectProvider` unified select columns and `id` composition.
  - [ ] Add tests for criteria filters (`source`, `status`, `sinceCutoff`, `query`) matching existing provider semantics.
  - [ ] Add tests validating `meta` payload fields expected by downstream resources.
  - [ ] Run focused tests and confirm red state.
- [ ] Task: Green - Implement `DrtAlertSelectProvider` and provider registration
  - [ ] Create `app/Services/Alerts/Providers/DrtAlertSelectProvider.php`.
  - [ ] Implement driver-safe `id` concat and unified timestamp selection.
  - [ ] Tag provider in `app/Providers/AppServiceProvider.php` under `alerts.select-providers`.
  - [ ] Re-run focused tests until green.
- [ ] Task: Refactor - Keep provider parity with other sources
  - [ ] Ensure meta JSON shape and criteria semantics match existing providers.
  - [ ] Re-run focused suite and verify no regression.
- [ ] Task: Conductor - User Manual Verification 'Phase 5: Unified Alerts Provider' (Protocol in workflow.md)

## Phase 6: Alert Source + Backend Contract Plumbing

- [ ] Task: Red - Write failing backend contract tests for DRT source identity
  - [ ] Add tests ensuring unified resources expose source as `drt`.
  - [ ] Add tests ensuring DRT records pass through existing unified endpoint/resource flow.
  - [ ] Confirm no regressions for existing source enum values.
  - [ ] Run focused tests and confirm red state.
- [ ] Task: Green - Implement enum and backend source plumbing
  - [ ] Add `Drt` case to `app/Enums/AlertSource.php`.
  - [ ] Update any backend switch/validation paths that require explicit source registration.
  - [ ] Re-run focused tests until green.
- [ ] Task: Refactor - Reduce duplication in source-registration paths
  - [ ] Deduplicate any repeated allow-list logic where safe and already patterned.
  - [ ] Re-run focused suite and verify no regression.
- [ ] Task: Conductor - User Manual Verification 'Phase 6: Alert Source + Backend Contract Plumbing' (Protocol in workflow.md)

## Phase 7: Frontend Domain + Presentation Integration

- [ ] Task: Red - Write failing frontend domain tests for DRT mapping
  - [ ] Add mapper tests ensuring `fromResource()` handles `source: 'drt'`.
  - [ ] Add tests for `mapDrtAlert()` output shape and metadata fallbacks.
  - [ ] Add tests ensuring existing source mappers remain unchanged.
  - [ ] Run focused frontend tests and confirm red state.
- [ ] Task: Green - Implement DRT frontend domain and wiring
  - [ ] Add `schema.ts` and `mapper.ts` under `resources/js/features/gta-alerts/domain/alerts/transit/drt/`.
  - [ ] Register `drt` in `resource.ts`, domain unions (`types.ts`), and `fromResource.ts`.
  - [ ] Update presentation mapping to include DRT using shared transit presentation logic.
  - [ ] Re-run focused frontend tests until green.
- [ ] Task: Refactor - Unify transit-domain ergonomics
  - [ ] Keep DRT-specific parsing isolated from shared rendering helpers.
  - [ ] Re-run focused frontend suite and verify no regression.
- [ ] Task: Conductor - User Manual Verification 'Phase 7: Frontend Domain + Presentation Integration' (Protocol in workflow.md)

## Phase 8: QA Phase

- [ ] Task: Execute targeted automated test gates first
  - [ ] Run focused Pest suites added in phases 1-7 via Sail.
  - [ ] Run focused frontend/Vitest suites for DRT mapper integration.
  - [ ] Resolve regressions before broad-suite execution.
- [ ] Task: Execute full project quality gates
  - [ ] Run `vendor/bin/sail artisan test --compact`.
  - [ ] Run `vendor/bin/sail artisan test --coverage --min=90`.
  - [ ] Run `vendor/bin/sail pnpm typecheck`, `vendor/bin/sail pnpm lint`, and `vendor/bin/sail pnpm format:check`.
  - [ ] Run `vendor/bin/sail composer audit` and `vendor/bin/sail pnpm audit`.
- [ ] Task: Conductor - User Manual Verification 'Phase 8: QA Phase' (Protocol in workflow.md)

## Phase 9: Documentation Phase (If Required)

- [ ] Task: Create/update source documentation for DRT
  - [ ] Document upstream endpoints (HTML list + detail), normalization contract, and conditional detail-fetch rules.
  - [ ] Document operational usage commands (`drt:fetch-alerts`, schedule visibility, active-record checks).
- [ ] Task: Update docs indexes/scope tables if source lists are enumerated explicitly
  - [ ] Update `docs/README.md` (or other source catalog surfaces) to include `drt` once implemented.
- [ ] Task: Prepare conductor artifacts for closeout
  - [ ] Add final implementation notes and deviations to this track's `spec.md`/`plan.md`.
  - [ ] Update `metadata.json` fields (`updated_at`, status when appropriate) and prep archive handoff checklist.
- [ ] Task: Conductor - User Manual Verification 'Phase 9: Documentation Phase' (Protocol in workflow.md)

