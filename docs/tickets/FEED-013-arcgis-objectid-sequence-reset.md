---
ticket_id: FEED-013
title: "[Bug] ArcGIS OBJECTID Sequence Reset Causes Silent Data Corruption in police_calls"
status: Closed
priority: High
assignee: Unassigned
created_at: 2026-03-04
tags: [bug, backend, police, arcgis, data-integrity, upsert, notifications]
related_files:
  - app/Services/TorontoPoliceFeedService.php
  - app/Console/Commands/FetchPoliceCallsCommand.php
  - app/Models/PoliceCall.php
---

## Summary

The Toronto Police Service ArcGIS FeatureServer (`C4S_Public_NoGO/FeatureServer/0`) was rebuilt
by TPS at some point around 2026-02-25, resetting the `OBJECTID` auto-increment sequence back to
1. Because `FetchPoliceCallsCommand` uses `updateOrCreate(['object_id' => ...])` as its upsert
key, incoming calls with recycled OBJECTIDs silently overwrite existing DB rows instead of
creating new records. The result is:

- `created_at` is frozen at the original insertion date for each recycled ID (no new records appear)
- Call content (call type, location, `occurrence_time`) reflects the current live call, but belongs
  to a row that may have been created months ago with completely different incident data
- `AlertCreated` events fire only when `is_active` flips from false → true on an overwritten row;
  genuinely new calls whose recycled ID was already active generate no notification event
- Feed freshness metrics (`latestFeedUpdatedAt`) and dashboard "last updated" indicators remain
  accurate (`updated_at` is refreshed each cycle), masking the underlying data corruption

## Discovery

Investigated on 2026-03-04 after noticing that `police_calls.created_at` had not advanced since
2026-02-25 despite the fetcher running successfully every 10 minutes (confirmed by
`updated_at: 2026-03-04 00:00:05`).

Querying the DB revealed the signature of an OBJECTID reset:

```
Active IDs range: 1 – 111
All IDs in table: 1, 2, 3, 4, 5, ..., 126   (perfectly sequential, no gaps)
Max object_id ever recorded: 126
Total records: 126
Active: 111 | Inactive: 15
```

Before the reset, `OBJECTID` values from the TPS ArcGIS layer were much larger (non-sequential,
reflecting months of call history). The current 1–126 range with perfect sequential ordering is
conclusive evidence of a layer rebuild with sequence restart.

## Root Cause

ArcGIS `OBJECTID` is an auto-increment integer managed by the geodatabase engine. It is guaranteed
unique **within the lifetime of a single feature layer**, but is **not stable across layer
rebuilds**. When TPS recreates or republishes the `C4S_Public_NoGO` FeatureServer layer — which
can happen as part of schema changes, service migrations, or maintenance — `OBJECTID` restarts
from 1.

Our upsert key treats `object_id` as a stable, globally-unique identifier. This assumption breaks
silently on any layer rebuild.

### Impact Chain

```
TPS rebuilds ArcGIS FeatureServer layer
  → OBJECTID sequence resets to 1
    → FetchPoliceCallsCommand runs: updateOrCreate(['object_id' => 1..N])
      → DB rows 1..N updated in-place (no new rows created)
        → created_at frozen at original insertion dates
          → wasRecentlyCreated = false for all rows
            → AlertCreated event suppressed for active→active "new" calls
              → Notification system misses new incidents
                → Data integrity compromised silently
```

### Why It Goes Unnoticed

The system continues to appear healthy:

- `updated_at` refreshes every cycle → "last updated" timestamp looks correct
- `is_active` flags still toggle correctly → deactivation logic unaffected
- The fetcher exits with `SUCCESS` → no errors in logs
- Active call count stays plausible (~111)
- No monitoring currently compares `max(object_id)` in feed vs. DB

## Affected Code

**`FetchPoliceCallsCommand.php:73-79`** — upsert key assumes OBJECTID is globally stable:

```php
$policeCall = PoliceCall::updateOrCreate(
    ['object_id' => $callData['object_id']],   // ← fragile: resets silently on layer rebuild
    array_merge($callData, [
        'is_active' => true,
        'feed_updated_at' => $feedUpdatedAt,
    ])
);
```

**`FetchPoliceCallsCommand.php:81-85`** — `AlertCreated` event suppressed for recycled-ID calls:

```php
if ($policeCall->wasRecentlyCreated || ($policeCall->wasChanged('is_active') && $policeCall->is_active)) {
    event(new AlertCreated(...));
}
// ↑ A recycled OBJECTID that was already active produces wasRecentlyCreated=false
//   and wasChanged('is_active')=false → no event fired
```

**`TorontoPoliceFeedService.php`** — no reset detection before returning features:

```php
return $allFeatures;  // ← no check that incoming OBJECTIDs are plausible given DB state
```

## Proposed Solutions

### Option A — Reset Detection via OBJECTID Ceiling Comparison (Recommended)

Before upserting, compare the highest `OBJECTID` in the incoming feed against the highest
`object_id` currently in the DB. If the feed's max is drastically lower than the DB's historic
max, a reset has likely occurred.

**Detection heuristic:**

```php
$feedMaxId   = max(array_column($calls, 'object_id'));
$dbMaxId     = PoliceCall::max('object_id') ?? 0;
$resetRatio  = $dbMaxId > 0 ? ($feedMaxId / $dbMaxId) : 1.0;

if ($resetRatio < config('feeds.police.reset_detection_threshold', 0.1)) {
    Log::warning('Toronto Police ArcGIS OBJECTID sequence reset detected', [
        'db_max_object_id'  => $dbMaxId,
        'feed_max_object_id' => $feedMaxId,
        'reset_ratio'       => $resetRatio,
    ]);
    PoliceCall::query()->delete();   // or truncate; wipe stale data before re-seeding
}
```

**Pros:** Simple, requires no schema change, directly addresses root cause.
**Cons:** Ratio threshold needs tuning; a genuine day with very low OBJECTID activity could
trigger a false positive (mitigated by keeping threshold low, e.g. 0.1).

---

### Option B — Composite Upsert Key: `(object_id, occurrence_time)`

Change the upsert key to include `occurrence_time`. Two calls sharing an `object_id` but with
different `occurrence_time` are definitively different incidents.

**Migration:**

```php
$table->unique(['object_id', 'occurrence_time']);
$table->dropUnique(['object_id']);
```

**Command change:**

```php
PoliceCall::updateOrCreate(
    ['object_id' => $callData['object_id'], 'occurrence_time' => $callData['occurrence_time']],
    ...
);
```

**Pros:** Formally correct — incidents with recycled IDs and different times are treated as new.
**Cons:** A recycled OBJECTID with the *same* `occurrence_time` as an old call (possible for
in-progress calls that span a reset) would still silently overwrite. Also complicates cleanup of
inactive records (multiple rows per `object_id`).

---

### Option C — Store Layer Metadata Fingerprint

Fetch ArcGIS layer metadata (`/FeatureServer/0?f=json`) before each poll and compare
`editingInfo.lastEditDate` or `editingInfo.dataLastEditDate` against a cached value. A layer
rebuild changes these values.

**Pros:** Catches resets proactively, even before a mismatch is detectable in OBJECTIDs.
**Cons:** Extra HTTP request per cycle; requires ArcGIS metadata to reliably expose rebuild events
(not guaranteed by the spec); adds complexity to the fetch pipeline.

---

### Recommended Approach

Implement **Option A** as the primary guard, with a structured log warning that triggers an
alert (or at minimum appears in the scheduler report). Pair it with a one-time data cleanup
command to recover from the current corrupted state:

```bash
php artisan police:reset-and-reseed
```

This command would:
1. Truncate `police_calls`
2. Immediately run `police:fetch-calls` to repopulate with current live data
3. Log the reset event

Add an `object_id_epoch` or `layer_reset_at` column to `police_calls` to track which ArcGIS
generation each row belongs to (useful for post-incident forensics).

## Current State / Immediate Recovery

The DB currently contains 126 rows with OBJECTIDs 1–126. Their `created_at` values are stale
(anchored to 2026-02-25 at the latest) but `call_type`, `cross_streets`, `occurrence_time`,
and `is_active` reflect the live feed correctly as of the last successful fetch.

**Short-term recovery (manual):**

```bash
# Wipe corrupted rows and repopulate from live feed
./vendor/bin/sail artisan tinker --execute="\App\Models\PoliceCall::query()->delete();"
./vendor/bin/sail artisan police:fetch-calls
```

This restores correct `created_at` values for all currently-active calls at the cost of losing
historical inactive records (which were already corrupted).

## Acceptance Criteria

- [ ] Reset detection logic implemented in `TorontoPoliceFeedService` or `FetchPoliceCallsCommand`
- [ ] On detected reset: stale rows cleared, feed re-fetched, log warning emitted
- [ ] `AlertCreated` event correctly fires for all calls inserted after a reset
- [ ] Unit test: mock feed with max OBJECTID < 10% of DB max triggers reset path
- [ ] Unit test: normal feed (incremental OBJECTIDs) does not trigger reset path
- [ ] `composer run test` passes clean
- [ ] DB recovered to clean state (no corrupted `created_at` rows)
- [ ] Scheduler report / health check surfaces reset events if they recur
