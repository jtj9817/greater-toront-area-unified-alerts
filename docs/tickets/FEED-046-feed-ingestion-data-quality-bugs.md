# FEED-046: Bug — Feed Ingestion Data Quality Issues (TTC SiteWide Junk Records + GO Transit SAAG Malformed Subjects)

## Summary

Two data quality bugs were discovered during a database inspection of `transit_alerts` and `go_transit_alerts`. TTC's live API injects website CMS banner records into the alerts feed, and GO Transit SAAG alerts produce malformed `message_subject` values with a leading ` - ` when the train corridor `Name` field is absent from the API response. A secondary typo also silently drops arrival time data from all SAAG alerts.

## Component

- Backend feed ingestion — `TtcAlertsFeedService` (`transit_alerts`)
- Backend feed ingestion — `GoTransitFeedService` (`go_transit_alerts`)

## Linked Issues

- Relates to: [FEED-006 — GO Transit HTML Sanitization](FEED-006-go-transit-html-sanitization.md)

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

**Fix:** Return `null` early from `normalizeLiveApiAlert()` for `siteWideCustom` and `generalCustom` buckets before the generic path, discarding all records from those buckets regardless of their content:

```php
if ($bucket === 'siteWideCustom' || $bucket === 'generalCustom') {
    return null;
}
```

**Files:**

- `app/Services/TtcAlertsFeedService.php`
- `tests/Feature/` or `tests/Unit/` — add a test asserting `siteWideCustom`/`generalCustom` records are filtered out

---

### P2 — GO Transit SAAG `message_subject` has leading ` - ` when train `Name` is absent

**File:** `app/Services/GoTransitFeedService.php:110–111, 256–258`

**Issue:** `$name` is resolved from `$train['Name']` at line 111. When the Metrolinx API omits the `Name` field for a train, `$name` is an empty string. The subject is then constructed as:

```php
$subject = $headSign !== ''
    ? "{$name} - {$headSign} delayed"   // "" . " - " . $headSign → " - Union Station to… delayed"
    : "{$name} train delayed";
```

This produces a leading ` - ` in every affected record's `message_subject`. The same empty `$name` also causes `corridor_or_route` to be stored as `""` (both `$name` and `$code` are empty, confirmed by `external_id` values of the form `saag::6933` — double colon — in the database).

**Example records in DB:**

```
external_id="saag::6933", message_subject=" - Union Station to Allandale Waterfront GO delayed (00:05:28)"
external_id="saag::1025", message_subject=" - Union Station to Aldershot GO delayed (00:05:58)"
```

**Risk:** Malformed subjects display awkwardly in the feed UI and break any subject-based search or display logic.

**Fix:** Guard the subject construction against an empty `$name`, falling back to `$code` then to a generic label:

```php
$label = $name ?: $code ?: 'GO Train';

$subject = $headSign !== ''
    ? "{$label} - {$headSign} delayed"
    : "{$label} train delayed";
```

**Files:**

- `app/Services/GoTransitFeedService.php`
- `tests/` — assert `message_subject` never starts with whitespace or ` - `

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

**Risk:** Low — arrival times are supplemental display data, but the data is always silently missing and the bug will persist unless corrected.

**Fix:** Rename the key:

```php
$arrivalTime = trim((string) ($saag['ArrivalTimeDisplay'] ?? ''));
```

**Files:**

- `app/Services/GoTransitFeedService.php`

---

## Acceptance Criteria

- [ ] Records from `siteWideCustom` and `generalCustom` TTC API buckets are not persisted to `transit_alerts`.
- [ ] No `transit_alerts` record with `route = '9999'` or `alert_type = 'SiteWide'` exists after a fresh fetch.
- [ ] All GO Transit SAAG `message_subject` values begin with a non-whitespace character.
- [ ] `corridor_or_route` is populated for SAAG alerts where `$code` is available even when `$name` is empty.
- [ ] `message_body` for SAAG alerts includes the `Arrival:` line when the API provides an arrival time.
- [ ] All existing tests continue to pass.

## Status

**OPEN**
