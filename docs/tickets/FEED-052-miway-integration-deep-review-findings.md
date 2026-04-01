# FEED-052: MiWay GTFS-RT Integration Deep Review Findings

## Meta

- **Issue Type:** Bug
- **Priority:** Mixed (`P1`/`P2`/`P3`)
- **Status:** Closed
- **Labels:** `alerts`, `miway`, `review`, `backend`, `frontend`, `documentation`
- **Source:** Deep implementation review against `conductor/archive/miway_service_scraping_20260331/{plan,spec}.md`

## Summary

The MiWay integration is mostly complete and wired through ingest, persistence, unified query, API resource mapping, and frontend domain mapping.  
This review found seven gaps where behavior is incomplete, inaccurate, or out of sync with the track plan/spec and repository contracts.

## Findings (Priority Order)

### P1 — MiWay cannot be selected from UI source filters

**Affected files:**

- `resources/js/features/gta-alerts/components/FeedView.tsx:144`
- `resources/js/features/gta-alerts/components/FeedView.test.tsx:142`
- `conductor/archive/miway_service_scraping_20260331/plan.md:87`

**Problem:**

- The plan requires MiWay to be independently filterable from TTC/GO.
- Feed category controls do not include a `miway` option.
- Users cannot select MiWay source from the UI filter bar.

**Impact:**

- Functional spec miss on frontend source filtering.

**Required fix direction:**

- Add a `MiWay` source category in `FeedView` categories and ensure it sets `source=miway`.
- Add/extend `FeedView` tests to assert MiWay category presence and query wiring.

---

### P2 — Home freshness timestamp excludes MiWay updates

**Affected file:**

- `app/Http/Controllers/GtaAlertsController.php:111`

**Problem:**

- `latest_feed_updated_at` currently aggregates Fire/Police/TTC/GO only.
- MiWay `feed_updated_at` is not considered.

**Impact:**

- Freshness indicator can be stale/inaccurate when MiWay is the latest updated source.

**Required fix direction:**

- Include `MiwayAlert` in `latestFeedUpdatedAt()` aggregation.
- Add focused feature coverage in `tests/Feature/GtaAlertsTest.php`.

---

### P2 — GTFS-RT active-period normalization only uses first period

**Affected files:**

- `app/Services/MiwayGtfsRtAlertsFeedService.php:177`
- `docs/plans/MiWay_Service_Scraping_Implementation_Plan.md:63`

**Problem:**

- Parser reads only `active_period[0]`.
- The plan’s normalization contract calls for min-start/max-end across periods.

**Impact:**

- `starts_at`/`ends_at` may be incorrect for multi-period alerts.

**Required fix direction:**

- Aggregate all periods:
  - `starts_at = min(non-zero starts)`
  - `ends_at = max(non-zero ends)`
- Add a feed-service test with multiple `TimeRange` entries.

---

### P2 — Conditional GET ETag path is not wired in command flow

**Affected files:**

- `app/Console/Commands/FetchMiwayAlertsCommand.php:27`
- `app/Services/MiwayGtfsRtAlertsFeedService.php:35`
- `docs/sources/miway.md:52`

**Problem:**

- Command always calls `fetch(null, $lastModified)`.
- `If-None-Match` is implemented in service but never used by production command flow.
- Docs state both conditional headers are sent.

**Impact:**

- Runtime behavior does not match documented conditional-fetch contract.

**Required fix direction:**

- Either:
  - persist/load ETag and pass it through command flow, or
  - explicitly document that current implementation is Last-Modified-only.
- Add/adjust command-level tests to match final behavior.

---

### P3 — Frontend contract fixture excludes MiWay source

**Affected file:**

- `tests/Feature/UnifiedAlerts/UnifiedAlertsFrontendContractFixtureTest.php:225`

**Problem:**

- Fixture source assertion expects only `['fire', 'go_transit', 'police', 'transit']`.
- MiWay is not seeded or asserted in the backend contract fixture.

**Impact:**

- Contract drift for frontend boundary can go undetected for MiWay.

**Required fix direction:**

- Seed MiWay fixture rows and assert `miway` in expected source set.
- Regenerate `resources/js/features/gta-alerts/domain/alerts/__fixtures__/backend-unified-alerts.json`.

---

### P3 — Backend docs still describe pre-MiWay source set

**Affected files:**

- `docs/backend/unified-alerts-system.md:5`
- `docs/backend/unified-alerts-system.md:38`
- `docs/backend/enums.md:10`
- `docs/backend/enums.md:20`
- `docs/backend/enums.md:78`

**Problem:**

- Source coverage/enums docs still list only Fire/Police/TTC/GO in several canonical sections.

**Impact:**

- Documentation inconsistency increases maintenance and onboarding risk.

**Required fix direction:**

- Update source coverage and enum docs to include `miway` consistently.

---

### P3 — Archived track plan has incomplete manual-verification checkbox

**Affected file:**

- `conductor/archive/miway_service_scraping_20260331/plan.md:88`

**Problem:**

- Track is archived as complete, but Phase 7 manual verification remains unchecked.

**Impact:**

- Archive artifacts are inconsistent with completion status.

**Required fix direction:**

- Reconcile archived plan checklist with actual completed state.

## Verification Notes

- Runtime test execution was attempted via Sail for targeted backend/frontend suites, but this environment returned: `Docker is not running.`
- Findings above are based on static code review + test and artifact inspection.

## Acceptance Criteria

- [x] MiWay is selectable from UI source filters and query string emits `source=miway`.
- [x] `latest_feed_updated_at` includes MiWay timestamps.
- [x] MiWay active period normalization uses min-start/max-end across all GTFS-RT periods.
- [x] Conditional-fetch behavior and docs are aligned (ETag+Last-Modified or explicitly Last-Modified-only).
- [x] Frontend contract fixture includes MiWay source coverage.
- [x] Unified-alerts/enums backend docs include MiWay consistently.
- [x] Archived MiWay track artifacts are internally consistent.

