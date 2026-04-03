# Implementation Plan: DRT Service Alerts Scraping Integration

## Phase 0: Source Re-Validation + Fixture Capture (Pre-Implementation)

- [x] Task: Confirm upstream source shape and endpoints (2026-04-03 baseline) (42df34f)
    - [x] Confirm list URL and pagination: `.../ServiceAlertsandDetours?page=N`.
    - [x] Confirm list entry fields and labels are present: `Posted on`, `When:`, `Route:` / `Routes:`, `Read more`.
    - [x] Confirm detail page contains the canonical full text and stable content boundaries (`Back to Search` and `Subscribe`).
    - [x] Inspect network requests to verify whether any JSON endpoint serves the Service Alerts/Detours list:
        - [x] If a stable unauthenticated JSON/RSS feed is found, record it here and update Phase 2 to prefer it.
        - [x] Otherwise, explicitly proceed with HTML list + detail scraping.
    - [x] Record the current behavior of `GET /Modules/NewsModule/services/getAlertBannerFeeds.ashx` (banner feed only; must not be used as the list source).
    - Re-validation outcome (captured 2026-04-03):
        - HTML source remains canonical: `GET /Modules/News/en/ServiceAlertsandDetours?page=N`.
        - Page 1 and page 2 both expose `Posted on`, `When:`, `Route:` / `Routes:`, and `Read more` entries.
        - Detail pages still include `Back to Search` + `Subscribe` boundaries around the canonical content block.
        - `GET /Modules/NewsModule/services/getAlertBannerFeeds.ashx` currently returns a 200 JSON banner payload (`Content-Type: application/javascript`, body length 684); this is banner-only and not the Service Alerts list source.
        - No stable unauthenticated JSON/RSS endpoint for Service Alerts + Detours was confirmed:
            - `GET /Modules/NewsModule/services/getTopFiveNews.ashx?limit=5&lang=en` returns generic news JSON (not scoped to active Service Alerts list).
            - `GET /Modules/NewsModule/services/getTopFiveNews.ashx?...&categories=Service%20Alerts%20and%20Detours` returned an empty body on 2026-04-03.
            - Proceed with HTML list + detail scraping in Phase 2.
- [x] Task: Create HTML fixtures used by tests (resilience to site refactors) (42df34f)
    - [x] Save list page fixtures for `page=1` and `page=2`.
    - [x] Save at least two detail page fixtures:
        - [x] one with `Route:` (singular)
        - [x] one with `Routes:` and bullet/stop lists
    - [x] Ensure fixtures cover common edge cases observed in the wild:
        - [x] missing/odd whitespace (e.g. `Routes: 920and 921`)
        - [x] non-breaking spaces and bullet lists
    - Fixture files captured:
        - `tests/fixtures/drt/list-page-1.html`
        - `tests/fixtures/drt/list-page-2.html`
        - `tests/fixtures/drt/detail-route-singular-conlin-grandview.html`
        - `tests/fixtures/drt/detail-routes-bullets-odd-whitespace.html`

## Phase 1: Database + Model [checkpoint: 15e6ae9]

- [x] Task: Red - Write failing persistence and model tests for DRT (124a8bd)
    - [x] Create `tests/Unit/Models/DrtAlertTest.php` with schema expectations for required columns.
    - [x] Add assertions for unique `external_id` and indexes on `posted_at` and (`is_active`, `posted_at`).
    - [x] Add model behavior tests for `fillable`, casts, and `scopeActive()` filtering.
    - [x] Run focused tests and confirm red state before implementation.
- [x] Task: Green - Implement `drt_alerts` migration and `DrtAlert` model (124a8bd)
    - [x] Add migration for `drt_alerts` with exact spec fields and index definitions.
    - [x] Add `app/Models/DrtAlert.php` with required fillable/casts/scope contracts.
    - [x] Add/update factory support if needed for deterministic tests.
    - [x] Re-run focused tests until green.
- [x] Task: Refactor - Normalize model and schema conventions (124a8bd)
    - [x] Align naming/cast conventions with existing transit alert models.
    - [x] Remove duplicate setup code from tests using datasets/helpers.
    - [x] Re-run focused suite and verify no regression.
- [x] Task: Conductor - User Manual Verification 'Phase 1: Database + Model' (Protocol in workflow.md) (15e6ae9)

## Phase 2: Feed Service (HTML List + Conditional Detail HTML) [checkpoint: e6c4204]

- [x] Task: Red - Write failing feed-service tests for normalization and resilience (1b81508)
    - [x] Create `tests/Feature/DrtServiceAlertsFeedServiceTest.php` covering:
        - [x] list-page parsing (title, details_url, posted_at, when_text, route_text, excerpt text).
        - [x] slug extraction into `external_id`.
        - [x] Toronto-to-UTC timestamp parsing.
        - [x] label and whitespace normalization:
            - [x] tolerate `Route:` vs `Routes:`.
            - [x] tolerate non-breaking spaces and odd spacing around colons/values.
        - [x] deterministic `list_hash` generation based on list signals (include `details_url`) with stable separators and deterministic handling when optional fields are missing.
        - [x] pagination behavior + max page cap.
        - [x] URL normalization:
            - [x] relative links become absolute.
            - [x] non-canonical hostnames (if present) normalize to `www.durhamregiontransit.com` for persisted `details_url`.
        - [x] DOM refactor resistance:
            - [x] tests must pass when CSS classes are removed/renamed in fixtures (parser relies on URL patterns + label text, not classes).
    - [x] Add conditional detail-fetch decision tests (new alert, changed hash, missing body, stale `details_fetched_at`, and skip path).
    - [x] Add failure-mode tests for network errors, malformed HTML, and empty list behavior (respecting `feeds.allow_empty_feeds`).
    - [x] Add circuit-breaker success/failure recording behavior tests.
    - [x] Run focused tests and confirm red state.
- [x] Task: Green - Implement `DrtServiceAlertsFeedService` (1b81508)
    - [x] Add `app/Services/DrtServiceAlertsFeedService.php` with timeout/retry and browser-ish headers.
    - [x] Implement list fetch + DOM parsing into deterministic alert array contract without coupling to fragile CSS classes.
    - [x] Implement pagination traversal with a hard max page guard.
    - [x] Implement conditional detail fetch + full content-block text extraction with defensive parsing:
        - [x] `body_text` must include `When:` and `Route(s):` and any bullet/stop lists (not just the list excerpt).
        - [x] `body_text` must exclude navigation/footer noise when possible (use stable content boundaries; fall back safely).
    - [x] Return normalized shape: `updated_at` (UTC) and `alerts`.
    - [x] Re-run focused tests until green.
- [x] Task: Refactor - Extract parsing and decision helpers (1b81508)
    - [x] Extract helper methods for timestamp parsing, text normalization, `list_hash`, and detail fetch eligibility.
    - [x] Ensure skip/fetch decision logic remains fully covered.
    - [x] Re-run focused suite and verify no regression.
- [x] Task: Conductor - User Manual Verification 'Phase 2: Feed Service (HTML List + Conditional Detail HTML)' (Protocol in workflow.md) (e6c4204)

## Phase 3: Fetch Command (Sync + Notifications) [checkpoint: babaad7]

- [x] Task: Red - Write failing command tests for sync lifecycle (b6112ac)
    - [x] Create `tests/Feature/Commands/FetchDrtAlertsCommandTest.php` with upsert expectations for active alerts.
    - [x] Add stale deactivation tests (missing ids from latest scrape become inactive).
    - [x] Add idempotency tests for repeated unchanged runs.
    - [x] Add tests for `AlertCreated` dispatch only on create/reactivate transitions.
    - [x] Run focused tests and confirm red state.
- [x] Task: Green - Implement `drt:fetch-alerts` command path (b6112ac)
    - [x] Add `app/Console/Commands/FetchDrtAlertsCommand.php` wired to `DrtServiceAlertsFeedService`.
    - [x] Implement upsert/deactivation logic and UTC `feed_updated_at` persistence.
    - [x] Extend `NotificationAlertFactory` with `fromDrtAlert()` mapping if missing.
    - [x] Re-run focused tests until green.
- [x] Task: Refactor - Tighten command safety and readability (b6112ac)
    - [x] Consolidate field assignment/update logic to avoid divergence between create/update paths.
    - [x] Improve command output/summary messaging for operational debugging.
    - [x] Re-run focused suite and verify no regression.
- [x] Task: Conductor - User Manual Verification 'Phase 3: Fetch Command (Sync + Notifications)' (Protocol in workflow.md) (babaad7)

## Phase 4: Queue Job Wrapper + Scheduler [checkpoint: f679c79]

- [x] Task: Red - Write failing job and scheduler tests (e2d7c65)
    - [x] Create `tests/Feature/Jobs/FetchDrtAlertsJobTest.php` for command invocation and non-zero exit failure behavior.
    - [x] Extend/cover `tests/Feature/Console/ScheduledFetchJobDispatcherTest.php` for `dispatchDrtAlerts()`.
    - [x] Add scheduler registration assertions for five-minute cadence and overlap protection.
    - [x] Run focused tests and confirm red state.
- [x] Task: Green - Implement DRT job and schedule wiring (e2d7c65)
    - [x] Add `app/Jobs/FetchDrtAlertsJob.php` implementing queue, unique, and overlap middleware contracts.
    - [x] Update `app/Services/ScheduledFetchJobDispatcher.php` with `dispatchDrtAlerts()`.
    - [x] Update `routes/console.php` schedule callback for the DRT dispatcher path.
    - [x] Re-run focused tests until green.
- [x] Task: Refactor - Align scheduler ergonomics with transit patterns (e2d7c65)
    - [x] Match job naming and middleware ordering with existing transit wrappers.
    - [x] Remove duplicated scheduler configuration logic where possible.
    - [x] Re-run focused suite and verify no regression.
- [x] Task: Conductor - User Manual Verification 'Phase 4: Queue Job Wrapper + Scheduler' (Protocol in workflow.md) (f679c79)

## Phase 5: Unified Alerts Provider [checkpoint: b15d8b4]

- [x] Task: Red - Write failing provider/query contract tests for DRT (b15d8b4)
    - [x] Add tests for `DrtAlertSelectProvider` unified select columns and `id` composition.
    - [x] Add tests for criteria filters (`source`, `status`, `sinceCutoff`, `query`) matching existing provider semantics.
    - [x] Add tests validating `meta` payload fields expected by downstream resources.
    - [x] Run focused tests and confirm red state.
- [x] Task: Green - Implement `DrtAlertSelectProvider` and provider registration (b15d8b4)
    - [x] Create `app/Services/Alerts/Providers/DrtAlertSelectProvider.php`.
    - [x] Implement driver-safe `id` concat and unified timestamp selection.
    - [x] Tag provider in `app/Providers/AppServiceProvider.php` under `alerts.select-providers`.
    - [x] Re-run focused tests until green.
- [x] Task: Refactor - Keep provider parity with other sources (b15d8b4)
    - [x] Ensure meta JSON shape and criteria semantics match existing providers.
    - [x] Re-run focused suite and verify no regression.
- [x] Task: Conductor - User Manual Verification 'Phase 5: Unified Alerts Provider' (Protocol in workflow.md) (b15d8b4)

## Phase 6: Alert Source + Backend Contract Plumbing [checkpoint: 5b527a0]

- [x] Task: Red - Write failing backend contract tests for DRT source identity (62349d8)
    - [x] Add tests ensuring unified resources expose source as `drt`.
    - [x] Add tests ensuring DRT records pass through existing unified endpoint/resource flow.
    - [x] Confirm no regressions for existing source enum values.
    - [x] Run focused tests and confirm red state.
- [x] Task: Green - Implement enum and backend source plumbing (62349d8)
    - [x] Add `Drt` case to `app/Enums/AlertSource.php`.
    - [x] Update any backend switch/validation paths that require explicit source registration.
    - [x] Re-run focused tests until green.
- [x] Task: Refactor - Reduce duplication in source-registration paths (62349d8)
    - [x] Deduplicate any repeated allow-list logic where safe and already patterned.
    - [x] Re-run focused suite and verify no regression.
- [x] Task: Conductor - User Manual Verification 'Phase 6: Alert Source + Backend Contract Plumbing' (Protocol in workflow.md) (5b527a0)

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
