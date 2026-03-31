# FEED-046: Bug — Feed Ingestion Data Quality Issues (TTC SiteWide Junk Records + GO Transit SAAG Malformed Subjects)

## Summary

Two data quality bugs were discovered during a database inspection of `transit_alerts` and `go_transit_alerts`. TTC's live API injects website CMS banner records into the alerts feed, and GO Transit SAAG alerts produce malformed `message_subject` values with a leading ` - ` when the train corridor `Name` field is absent from the API response. A secondary typo also silently drops arrival time data from all SAAG alerts.

## Component

- Backend feed ingestion — `TtcAlertsFeedService` (`transit_alerts`)
- Backend feed ingestion — `GoTransitFeedService` (`go_transit_alerts`)

## Linked Issues

- Relates to: [FEED-006 — GO Transit HTML Sanitization](FEED-006-go-transit-html-sanitization.md)

---

## Reproduction

### P1 — TTC junk records

Run the TTC fetch command against the live API (or use the existing `Http::fake()` pattern in the test suite — see below) and query:

```sql
SELECT * FROM transit_alerts WHERE route = '9999' OR alert_type = 'SiteWide';
```

### P2 + P3 — GO Transit SAAG

Run the GO Transit fetch command against the live API (or construct a fixture with a `Train` entry that omits `Name` and `Code`) and query:

```sql
SELECT external_id, message_subject, message_body
FROM go_transit_alerts
WHERE alert_type = 'saag'
ORDER BY created_at DESC
LIMIT 20;
```

`message_subject` values will start with ` - ` for affected records. `message_body` will never contain an `Arrival:` line for any SAAG alert.

---

## Findings

### P1 — TTC `siteWideCustom`/`generalCustom` bucket records ingested as real alerts

**File:** `app/Services/TtcAlertsFeedService.php:95–113, 202–237`

**Issue:** `fetchLiveApiAlerts()` iterates over five API buckets:

```php
$buckets = ['routes', 'accessibility', 'siteWideCustom', 'generalCustom', 'stops'];
```

`normalizeLiveApiAlert()` branches only on `'accessibility'`; records from `siteWideCustom` and `generalCustom` fall through to the generic normalization path. The only guard is a null-check on `id` and `title` (line 211). CMS banner records from these buckets carry a valid `id` and `title`, so they pass through and are upserted into `transit_alerts` as if they were service alerts.

**Example record in DB:**

```
id=2171, alert_type="SiteWide", route_type="General", route="9999",
title="WEBSITE", active_period_end="2027-03-29" (1-year window)
```

**Risk:** Junk records surface in the unified feed and are visible to users. The `route: "9999"` value also poisons any route-based filtering or frontend presentation logic.

**Fix:** Insert an early-return guard in `normalizeLiveApiAlert()` immediately **after** the `accessibility` branch (line 206), so the `accessibility` path is unaffected:

```php
if ($bucket === 'accessibility') {
    return $this->normalizeAccessibilityAlert($record);
}

// Add this guard:
if ($bucket === 'siteWideCustom' || $bucket === 'generalCustom') {
    return null;
}
```

**DB cleanup:** Existing dirty records will be marked `is_active = false` automatically on the next successful fetch (the command deactivates any record not present in the latest API response). No migration is needed.

**Test file:** `tests/Feature/Services/TtcAlertsFeedServiceTest.php`

The existing test uses `Http::fake()` to supply a synthetic API response (see the `routes` and `accessibility` bucket fixtures at the top of the file). Add a new test case that includes a `siteWideCustom` entry in the faked response and asserts that no `transit_alert` record is created for it. Follow the `Http::fake()` + `->fetch()` pattern already established in that file.

---

### P2 — GO Transit SAAG `message_subject` has leading ` - ` when train `Name` is absent

**File:** `app/Services/GoTransitFeedService.php:110–111, 256–258, 285`

**Issue:** `$name` is resolved from `$train['Name']` at line 111. When the Metrolinx API omits the `Name` field for a train, `$name` is an empty string. The subject is then constructed as:

```php
$subject = $headSign !== ''
    ? "{$name} - {$headSign} delayed"   // "" . " - " . $headSign → " - Union Station to… delayed"
    : "{$name} train delayed";
```

The same empty `$name` causes `corridor_or_route` to be stored as `""` on line 285 (`$name ?: $code` — when `$code` is also absent, both collapse to `""`). This is confirmed by `external_id` values in the database of the form `saag::6933` (double colon) rather than `saag:{code}:{tripNumber}`.

**Example records in DB:**

```
external_id="saag::6933", corridor_or_route="",
message_subject=" - Union Station to Allandale Waterfront GO delayed (00:05:28)"

external_id="saag::1025", corridor_or_route="",
message_subject=" - Union Station to Aldershot GO delayed (00:05:58)"
```

**Risk:** Malformed subjects display awkwardly in the feed UI and break any subject-based search or display logic. `corridor_or_route` being empty means these alerts have no corridor attribution.

**Fix:** Introduce a `$label` fallback used for both `message_subject` and `corridor_or_route`:

```php
$label = $name ?: $code ?: 'GO Train';

$subject = $headSign !== ''
    ? "{$label} - {$headSign} delayed"
    : "{$label} train delayed";
```

Update `corridor_or_route` on line 285 to use the same fallback:

```php
'corridor_or_route' => $label,
```

**Test file:** `tests/Feature/Services/GoTransitFeedServiceTest.php`

The existing test supplies a `Train` fixture with `Code: 'LW'` and `Name: 'Lakeshore West'` (happy path only). Add a test case with a `Train` entry where both `Name` and `Code` are omitted, and assert that `message_subject` does not start with whitespace or ` - `, and that `corridor_or_route` is `'GO Train'` rather than `""`.

---

### P3 — GO Transit SAAG `ArrivalTimeDisplay` field key typo silently drops arrival times

**File:** `app/Services/GoTransitFeedService.php:253`

**Issue:** The field key used to extract arrival time from a SAAG notification is misspelled:

```php
$arrivalTime = trim((string) ($saag['ArrivalTimeTimeDisplay'] ?? ''));
//                                            ^^^^^^^^^^^^
// Double "Time" — correct key is 'ArrivalTimeDisplay'
```

Because the key never matches, `$arrivalTime` is always `''`, meaning the `Arrival:` line is never appended to `message_body` for any SAAG alert.

**Note:** The existing test fixture in `GoTransitFeedServiceTest.php` also uses the wrong key (`ArrivalTimeTimeDisplay`), which is why this bug was never caught. The fixture must be corrected to `ArrivalTimeDisplay` alongside the service fix, and the test should assert that the `Arrival:` line appears in `message_body`.

**Risk:** Low — arrival times are supplemental display data — but the data has been silently missing for all SAAG alerts since the feature was introduced.

**Fix:** Correct the key at line 253:

```php
$arrivalTime = trim((string) ($saag['ArrivalTimeDisplay'] ?? ''));
```

**Files:**

- `app/Services/GoTransitFeedService.php`
- `tests/Feature/Services/GoTransitFeedServiceTest.php` (fix the fixture key and add assertion)

---

## Acceptance Criteria

- [ ] Records from `siteWideCustom` and `generalCustom` TTC API buckets are not persisted to `transit_alerts`.
- [ ] No `transit_alerts` record with `route = '9999'` or `alert_type = 'SiteWide'` is created after a fresh fetch (existing dirty records deactivate naturally on next fetch).
- [ ] All GO Transit SAAG `message_subject` values begin with a non-whitespace character.
- [ ] `corridor_or_route` is non-empty for SAAG alerts even when both `$name` and `$code` are absent from the API response.
- [ ] `message_body` for SAAG alerts includes an `Arrival:` line when the API provides an arrival time.
- [ ] `GoTransitFeedServiceTest` fixture corrected to use `ArrivalTimeDisplay` and asserts the `Arrival:` line.
- [ ] All existing tests continue to pass.

## Status

**OPEN**
