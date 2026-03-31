# FEED-047: Review Follow-Up — TTC Empty-Feed Handling After Custom Bucket Filtering + GO SAAG Fixture Alignment

## Summary

After closing [FEED-046 — Feed Ingestion Data Quality Issues](FEED-046-feed-ingestion-data-quality-bugs.md), a review flagged two follow-ups:

1. The TTC change that filters out `siteWideCustom`/`generalCustom` records can cause a **successful** TTC fetch to return **zero alerts**, which currently throws when `ALLOW_EMPTY_FEEDS=false` (default), potentially failing ingestion/circuit-breaker depending on real upstream payload shapes.
2. `GoTransitFeedService` now correctly reads `ArrivalTimeDisplay` for SAAG alerts, but the canonical “valid json response” fixture still contains the old key (`ArrivalTimeTimeDisplay`), making the happy-path test non-representative and missing an assertion for the `Arrival:` line.

## Component

- Backend feed ingestion — `TtcAlertsFeedService` (`transit_alerts`)
- Backend feed ingestion — `GoTransitFeedService` (`go_transit_alerts`)
- Tests — `tests/Feature/Services/*`

## Linked Issues

- Follow-up to: [FEED-046 — Feed Ingestion Data Quality Issues](FEED-046-feed-ingestion-data-quality-bugs.md)

---

## Findings

### P1 — TTC can now “successfully” fetch zero alerts and throw (default config)

**Files:**

- `app/Services/TtcAlertsFeedService.php`
- `config/feeds.php`

**What changed:** `normalizeLiveApiAlert()` now returns `null` for `siteWideCustom` and `generalCustom` buckets.

**Current behavior:** `fetch()` merges `live-api`, `SXA`, and `static` alerts, then throws if the result is empty and `feeds.allow_empty_feeds` is `false`:

- `config/feeds.php` → `feeds.allow_empty_feeds = env('ALLOW_EMPTY_FEEDS', false)`
- `app/Services/TtcAlertsFeedService.php` → throws `RuntimeException('TTC alerts feed returned zero alerts')`

**Risk:** If the live TTC API returns only the custom CMS buckets (plus empty “real” buckets) and SXA/static also return no items, `fetch()` throws even though upstream returned a 200 with a valid `lastUpdated`. This can:

- mark the TTC feed as “failed” and trip the circuit breaker
- block ingestion updates and leave stale active alerts in the database/UI

**Open question / required decision:** Should “empty but successful” be treated as a valid state for TTC (at least under specific upstream shapes), or should it continue to be considered an error requiring operator attention?

**Potential approaches (choose one):**

- **Option A (TTC-specific empty-success):** Treat empty results as a success for `ttc_alerts` (i.e., do not throw on empty for TTC) while keeping empty-feed protection for other feeds.
- **Option B (shape-gated empty-success):** Treat empty results as success only when the upstream payload has a valid `lastUpdated` and all non-custom buckets (`routes`, `accessibility`, `stops`) are empty (or missing), but custom buckets are present.
- **Option C (per-feed configuration):** Extend `feeds` config to support per-feed empty behavior (e.g., `feeds.empty_feed_strategy.ttc_alerts = allow|throw|shape_gated`) and update callers accordingly.

---

### P3 — GO SAAG happy-path test fixture still uses the old arrival key

**Files:**

- `app/Services/GoTransitFeedService.php`
- `tests/Feature/Services/GoTransitFeedServiceTest.php`

**Current behavior:** `GoTransitFeedService` reads `ArrivalTimeDisplay` for SAAG alerts and appends `Arrival: …` to `message_body` when present.

**Issue:** The “valid json response” test fixture still uses `ArrivalTimeTimeDisplay` for SAAG alerts, so it no longer matches the production parsing contract. The test also only asserts `Departure:` and never asserts an `Arrival:` line on the happy path.

**Risk:** The canonical happy-path test becomes misleading for future maintenance and can allow regressions in arrival-time parsing to ship unnoticed.

**Fix:** Update the fixture key to `ArrivalTimeDisplay` and assert that the SAAG `message_body` contains an `Arrival: …` line.

---

## Acceptance Criteria

- [x] TTC ingestion does not fail/circuit-break solely due to a successful upstream response yielding zero “real” alerts after filtering `siteWideCustom`/`generalCustom` (per the chosen strategy).
- [x] A test covers the chosen TTC empty-feed strategy (and documents the intended behavior).
- [x] `tests/Feature/Services/GoTransitFeedServiceTest.php` “valid json response” fixture uses `ArrivalTimeDisplay` for SAAG notifications.
- [x] The GO SAAG happy-path test asserts that `message_body` includes both `Departure:` and `Arrival:` when the API provides both fields.

## Status

**CLOSED**
