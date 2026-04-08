---
ticket_id: FEED-048
title: "[Review] Validate MiWay GTFS-RT Protobuf Ingestion Findings (P0–P3)"
status: Closed
priority: High
assignee: Unassigned
created_at: 2026-03-31
closed_at: 2026-03-31
tags: [feed, miway, gtfs-rt, protobuf, pint, review]
related_files:
  - app/Services/MiwayGtfsRtAlertsFeedService.php
  - app/Protobuf/Google/Transit/Realtime/FeedMessage.php
  - app/Protobuf/Google/Transit/Realtime/Alert/Cause.php
  - app/Protobuf/Google/Transit/Realtime/Alert/Effect.php
  - composer.json
---

## Overview

This ticket validates and resolves the remaining review items (P0–P3) for the MiWay GTFS-Realtime (protobuf) alerts ingestion.

## Validation Summary

- **P0 (Pint / generated sources): Valid.** `pint --test` fails and explicitly reports multiple violations in `app/Protobuf/**` generated files.
- **P1 (unknown enum values): Valid.** Generated enum helpers throw `UnexpectedValueException` on unknown integer values, which can break ingestion.
- **P2 (UTC consistency): Valid.** `updated_at` is returned in local timezone for HTTP 304 and (at least one) empty-feed path, but in UTC for successful decode paths.
- **P3 (`empty()` on body): Valid.** `empty($body)` can misclassify the string `'0'` as empty; strict byte-length checks are safer for binary payloads.

## Findings

### P0 — Pint fails on generated protobuf sources

**Claim:** Generated PHP sources under `app/Protobuf/**` do not match Pint rules; `composer test` runs `pint --test`, so CI will fail unless generated code is excluded or formatted.

**Verification:**

Command:
```bash
vendor/bin/sail bin pint --test
```

Result: **FAIL**. Pint output includes (non-exhaustive) generated files such as:

- `app/Protobuf/GPBMetadata/GtfsRealtime.php`
- `app/Protobuf/Google/Transit/Realtime/FeedMessage.php`
- `app/Protobuf/Google/Transit/Realtime/Alert/Cause.php`
- `app/Protobuf/Google/Transit/Realtime/Alert/Effect.php`

**Note:** Pint failures also included non-protobuf files (e.g. the MiWay feed service/tests and manual verification scripts). Those were resolved after excluding generated protobuf sources.

### P1 — Unknown GTFS-RT enum values can crash normalization

**Claim:** `Alert\Cause::name()` / `Alert\Effect::name()` throw `UnexpectedValueException` for unknown values, which can error the whole fetch instead of degrading to `UNKNOWN_*`.

**Verification:** In `app/Protobuf/Google/Transit/Realtime/Alert/Cause.php`, `Cause::name($value)` throws when the value key is not defined in the generated map (same pattern in `Effect`).

Impact surface (pre-fix):
- `App\Services\MiwayGtfsRtAlertsFeedService::normalizeAlert()` called `Alert\Cause::name($alert->getCause())` and `Alert\Effect::name($alert->getEffect())` without a guard.

### P2 — `updated_at` timezone semantics differ on 304 responses

**Claim:** On HTTP 304, `fetch()` returns `updated_at` in local timezone (`Carbon::now()`), while the successful decode path returns UTC (`->utc()`).

**Verification (pre-fix):** In `app/Services/MiwayGtfsRtAlertsFeedService.php`, the 304 early return used `Carbon::now()` (no `->utc()`), while the decoded-feed path used `Carbon::createFromTimestamp(...)->utc()` / `Carbon::now()->utc()`.

### P3 — `empty()` is not a safe “no bytes returned” check for binary payloads

**Claim:** `empty($body)` treats `'0'` as empty; strict checks like `$body === ''` (or `strlen($body) === 0`) avoid misclassification.

**Verification (pre-fix):** In `app/Services/MiwayGtfsRtAlertsFeedService.php`, `fetch()` used `empty($body)` to detect an empty payload.

## Recommendations (No Timeline)

- **P0:** Exclude generated protobuf sources from Pint (preferred) *or* enforce formatting of generated outputs (fragile). If excluding, add a Pint exclude rule for `app/Protobuf/**` so `composer test` is stable.
- **P1:** Introduce a safe enum-to-name helper (e.g., `try/catch UnexpectedValueException` or a `isset()` check against the generated mapping) to fall back to `UNKNOWN_CAUSE` / `UNKNOWN_EFFECT` rather than throwing.
- **P2:** Normalize `updated_at` to UTC on all early returns (304 and empty-feed paths) for consistent semantics.
- **P3:** Replace `empty($body)` with a strict byte-length check (`$body === ''`) before attempting protobuf decoding.

## Acceptance Criteria

- `vendor/bin/sail bin pint --test` passes in CI for this change set (either by excluding generated sources or ensuring they comply).
- Unknown enum values from the feed do not throw during normalization; the alert is still processed with `UNKNOWN_*` cause/effect values.
- `fetch()` returns `updated_at` in UTC regardless of HTTP status (304 vs 200) or empty-feed scenarios.
- Payload “empty” detection uses a strict byte-length check suitable for binary protobuf bodies.

## Resolution

All validated findings (P0–P3) are fixed:

- **P0:** `pint.json` excludes `app/Protobuf` so generated protobuf sources no longer break `pint --test`.
- **P1:** `MiwayGtfsRtAlertsFeedService::normalizeAlert()` now guards enum name decoding and falls back to `UNKNOWN_CAUSE` / `UNKNOWN_EFFECT` on unknown values.
- **P2:** `MiwayGtfsRtAlertsFeedService::fetch()` now returns `updated_at` in UTC for HTTP 304 and empty-feed early returns.
- **P3:** Empty-payload detection now uses a strict byte check (`$body === ''`) so a `'0'` payload is not misclassified as empty.

## Verification

Commands run:

```bash
vendor/bin/sail artisan test --compact tests/Feature/MiwayGtfsRtAlertsFeedServiceTest.php tests/Feature/Commands/FetchMiwayAlertsCommandTest.php
vendor/bin/sail composer test
vendor/bin/sail composer lint
vendor/bin/sail pnpm run lint
vendor/bin/sail pnpm run format
vendor/bin/sail pnpm run types
```
