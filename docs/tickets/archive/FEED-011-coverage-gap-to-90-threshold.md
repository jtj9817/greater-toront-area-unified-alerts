---
ticket_id: FEED-011
title: "[Quality] Coverage Gate Failing at 89.1% — Close Branch Gaps to Restore >=90% Suite Coverage"
status: Ready for Closure
priority: Critical
assignee: Unassigned
created_at: 2026-02-25
tags: [quality, testing, coverage, backend, phpunit, pest]
related_files:
  - test_coverage_results.log
  - 20260224_feed-011-coverage-gap-to-90-threshold-audit.md
  - app/Console/Commands/ImportAlertDataSql.php
  - app/Console/Commands/ExportAlertDataSql.php
  - app/Providers/QueueEnqueueDebugServiceProvider.php
  - app/Console/Commands/SailWrapper.php
  - app/Rules/UnifiedAlertsCursorRule.php
  - app/Console/Commands/ImportTorontoGeospatialDataCommand.php
  - app/Jobs/DeliverAlertNotificationJob.php
  - app/Services/Notifications/NotificationMatcher.php
  - tests/Feature/ImportAlertDataSqlTest.php
  - tests/Feature/ExportAlertDataSqlTest.php
  - tests/Feature/Console/ImportTorontoGeospatialDataCommandTest.php
  - tests/Feature/Commands/SailWrapperCommandTest.php
  - tests/Feature/Notifications/DeliverAlertNotificationJobTest.php
  - tests/Feature/Notifications/AlertCreatedMatchingTest.php
  - tests/Feature/Commands/VerifyProductionSeedCommandTest.php
  - tests/Feature/Commands/ExportProductionDataCommandTest.php
  - tests/Unit/Models/NotificationLogTest.php
  - tests/Unit/Rules/UnifiedAlertsCursorRuleTest.php
  - tests/Unit/Providers/QueueEnqueueDebugServiceProviderTest.php
  - tests/Unit/Models/UserTest.php
  - tests/Unit/Models/GoTransitAlertTest.php
  - tests/Unit/Services/Alerts/Providers/TransitAlertSelectProviderTest.php
  - tests/Unit/Services/Alerts/Providers/FireAlertSelectProviderTest.php
---

## Summary

This ticket started from a failing coverage gate at `89.1%` and targeted high-gap
branches across commands, validation, providers, and model contracts.

Latest recorded coverage artifact in repo (`test_coverage_results.log`, timestamp
`2026-02-24 21:16:43 -0500`) shows:

- `Total: 92.0 %`
- No coverage threshold failure message in the log output.

Implementation work for Priority 1, Priority 2, and Priority 3 test expansion is
now complete, including follow-up assertion hardening and edge-case coverage.

## Evidence and Findings (from `test_coverage_results.log`)

Historical baseline under-covered modules (from initial failure context):

| Module | Current Coverage |
|---|---|
| `Providers/QueueEnqueueDebugServiceProvider` | 3.8% |
| `Console/Commands/SailWrapper` | 25.0% |
| `Rules/UnifiedAlertsCursorRule` | 54.5% |
| `Console/Commands/ImportAlertDataSql` | 69.8% |
| `Services/Alerts/Providers/TransitAlertSelectProvider` | 73.9% |
| `Models/User` | 75.0% |
| `Console/Commands/ImportTorontoGeospatialDataCommand` | 79.8% |
| `Console/Commands/ExportProductionData` | 80.3% |
| `Services/Alerts/Providers/FireAlertSelectProvider` | 80.8% |
| `Console/Commands/ExportAlertDataSql` | 81.6% |
| `Jobs/DeliverAlertNotificationJob` | 82.7% |
| `Console/Commands/VerifyProductionSeed` | 86.7% |
| `Services/Notifications/NotificationMatcher` | 88.2% |

Latest recorded run result in the same artifact:

- `Total: 92.0 %`

## Test Files to Expand (Selected)

### Priority 1 (largest likely coverage lift)

1. [x] `tests/Feature/ImportAlertDataSqlTest.php`
2. [x] `tests/Feature/ExportAlertDataSqlTest.php`
3. [x] `tests/Feature/Console/ImportTorontoGeospatialDataCommandTest.php`
4. [x] `tests/Feature/Commands/SailWrapperCommandTest.php`
5. [x] `tests/Feature/Notifications/DeliverAlertNotificationJobTest.php`
6. [x] `tests/Feature/Notifications/AlertCreatedMatchingTest.php`

### Priority 2 (small files with severe branch gaps / cheap wins)

1. [x] `tests/Feature/Commands/VerifyProductionSeedCommandTest.php`
2. [x] `tests/Feature/Commands/ExportProductionDataCommandTest.php`
3. [x] `tests/Unit/Models/NotificationLogTest.php`

### Priority 3 (add missing targeted files where no direct branch coverage exists)

1. [x] `tests/Unit/Rules/UnifiedAlertsCursorRuleTest.php` (new)
2. [x] `tests/Unit/Providers/QueueEnqueueDebugServiceProviderTest.php` (new)
3. [x] `tests/Unit/Models/UserTest.php` (new)
4. [x] `tests/Unit/Models/GoTransitAlertTest.php` (new)
5. [x] `tests/Unit/Services/Alerts/Providers/TransitAlertSelectProviderTest.php` (expand)
6. [x] `tests/Unit/Services/Alerts/Providers/FireAlertSelectProviderTest.php` (expand)

## Comprehensive Plan by Test File

### 1) `tests/Feature/ImportAlertDataSqlTest.php`
- Add tests for all unexercised failure paths in `ImportAlertDataSql`:
- `--file` missing/blank
- unresolved DB connection (`database.default` invalid)
- missing `host/port/username/database` connection keys
- missing file / unreadable file / empty file
- dry-run with only comments/whitespace (`no executable statements`)
- dry-run allowing `SELECT setval(...)`
- dry-run rejection for unsupported statements (e.g. `DELETE`, `UPDATE`)
- process non-zero exit with empty stderr fallback to stdout
- throwable path where exception is not “psql missing”
- psql missing detection variants (`not recognized`, `executable file not found`)

### 2) `tests/Feature/ExportAlertDataSqlTest.php`
- Add branch tests for `ExportAlertDataSql`:
- invalid `--tables` values rejection
- empty tables option after normalization
- invalid `--chunk` fallback warning path
- `--compress` with explicit `.gz` vs implicit extension append
- missing table in schema error path
- table with no `id` column error path
- boolean literal normalization matrix (`0/1`, `yes/no`, `on/off`, string edge cases)
- output path directory creation path and failure branch
- writer open failure branch for plain and gzip modes

### 3) `tests/Feature/Console/ImportTorontoGeospatialDataCommandTest.php`
- Expand edge cases currently not covered:
- neither `--addresses` nor `--pois` provided
- CSV file missing header row
- `array_combine` mismatch row length (skip behavior)
- JSON file over 50MB guard path (simulate by mocking filesize boundary)
- GeoJSON `features` payload with geometry coordinates projection
- JSON object (non-list, non-features) import path
- row mapping skips invalid lat/long/name street values
- verify chunk flush path at `>=1000` records for both addresses and POIs

### 4) `tests/Feature/Commands/SailWrapperCommandTest.php`
- Expand from single usage test to full command behavior:
- missing `vendor/bin/sail` path returns error code 1
- validates “running sail command” output when args exist
- verifies non-interactive execution path returns child process exit code
- if needed, refactor command to inject process factory for testability (so tests do not shell out to real Docker/Sail)

### 5) `tests/Feature/Notifications/DeliverAlertNotificationJobTest.php`
- Add tests for uncovered early returns and failure handling:
- missing preference record => no log/event
- `push_enabled = false` => no log/event
- invalid payload (blank alert ID/source) => no delivery
- claim update returns 0 (already processing) => exits safely
- event dispatch throws => status reset from `processing` to `sent`
- ensure metadata shape on first create includes occurred_at/routes/source/severity

### 6) `tests/Feature/Notifications/AlertCreatedMatchingTest.php`
- Expand matcher branch coverage:
- `alert_type = accessibility` matching for `ttc_accessibility`
- accessibility keyword detection in transit/go metadata/summary
- subscription normalization (`"501"` -> `route:501`, case/trim, duplicates)
- behavior when transit alert has subscriptions configured but no extracted URNs
- saved-place cache behavior consistency across same user in one matching run

### 7) `tests/Feature/Commands/VerifyProductionSeedCommandTest.php`
- Add missing branch/error-path tests:
- default path resolution branch
- main seeder file missing
- split class referenced but part file missing
- missing `created_at` and `updated_at` sentinel checks
- unreadable file handling branch (where supported by FS permissions)

### 8) `tests/Feature/Commands/ExportProductionDataCommandTest.php`
- Cover remaining command branches:
- invalid `--chunk` and `--max-bytes` fallback warnings
- output directory creation failure
- split rename/class replace failure paths
- no active file guard branches (`closeSeederFile`, `writeBlock`) via controlled invocation/refactor

### 9) `tests/Unit/Rules/UnifiedAlertsCursorRuleTest.php` (new)
- Dedicated unit tests for `UnifiedAlertsCursorRule`:
- `null` accepted
- non-string rejected with attribute-specific message
- blank string accepted
- invalid cursor rejected
- valid encoded cursor accepted

### 10) `tests/Unit/Providers/QueueEnqueueDebugServiceProviderTest.php` (new)
- Add focused unit tests for provider internals and boot behavior:
- debug disabled => no listener registration
- default matcher set used when env var empty
- wildcard matcher (`*`) path
- exact and partial matcher behavior
- payload extraction failure logs warning
- stack inclusion toggle path and compact stack filtering

### 11) `tests/Unit/Models/UserTest.php` (new)
- Cover model branches currently untested:
- cast map includes `two_factor_confirmed_at`
- relationship methods: `notificationPreference`, `notificationLogs`, `savedPlaces`
- hidden/fillable expectations in one dedicated model test

### 12) `tests/Unit/Models/GoTransitAlertTest.php` (new or merge into existing model suite)
- Cover uncovered scope line:
- `scopeActive()` returns only active rows
- cast assertions (`posted_at`, `feed_updated_at`, `is_active`)
- fillable assertions for alert fields

### 13) `tests/Unit/Models/NotificationLogTest.php` (expand existing)
- Add missing relationship coverage:
- `user()` belongsTo relation assertion
- optional: scope composition test (`unread()->undismissed()`)

### 14) `tests/Unit/Services/Alerts/Providers/TransitAlertSelectProviderTest.php` (expand)
- Add tests for:
- source mismatch path (`whereRaw('1 = 0')`)
- `status=cleared` branch
- MySQL query branch for text search fallback conditions
- since-cutoff branch with `active_period_start` null fallback to `created_at`

### 15) `tests/Unit/Services/Alerts/Providers/FireAlertSelectProviderTest.php` (expand)
- Add tests for:
- source mismatch path (`whereRaw('1 = 0')`)
- `status=cleared` branch
- MySQL query branch for fulltext + LIKE fallback path
- meta projection when no incident updates and when unexpected update_type appears

## Execution Strategy

1. Implement Priority 1 files first, then run:
   - `php artisan test --coverage --min=90`
2. If still below threshold, execute Priority 2.
3. Use Priority 3 only as needed to close the final gap and harden long-term branch coverage.

## Acceptance Criteria

- [x] Coverage run from repo artifact passes threshold: `Total: 92.0 %` in `test_coverage_results.log`
- [x] Added tests are deterministic and avoid external network/process dependencies
- [x] Production code changes were constrained to command hardening/testability seams with no breaking changes documented
- [x] New tests document previously untested edge/error paths for import/export pipeline commands
- [x] Ticket references are updated to include final touched test files

## Assertion Quality Review (2026-02-25)

Follow-up review of the newly added FEED-011 unit tests found no incorrect
logic in current assertions, but identified quality and resilience gaps.

### Findings Addressed

- SQL literal coupling in source-mismatch tests:
  `FireAlertSelectProviderTest` and `TransitAlertSelectProviderTest` assert
  `toSql()` contains `1 = 0`. This proves implementation detail, not behavior.
  Add result-level assertions that the query returns no rows for mismatched
  source criteria.
- Incomplete cast coverage in `UserTest`:
  Test name says "fillable hidden and casts" but only datetime casts are
  asserted. Add assertion that password assignment is hashed.
- Relationship tests only prove inclusion:
  Add second-user records and assert those records are excluded from current
  user's relationship collections to validate FK isolation.
- High brittleness from exact SQL/fillable/hidden arrays:
  Keep core exact assertions where contract matters, but add behavior-level
  assertions so harmless SQL formatting or array-order refactors do not cause
  noisy failures.

### Edge Cases Covered in Follow-up

- [x] Cursor rule accepts valid encoded cursor with surrounding whitespace.
- [x] Queue debug provider `compactStack` enforces limit truncation.
- [x] Queue debug provider matcher parsing trims and drops empty comma entries.
- [x] Transit `since` cutoff includes rows exactly at the cutoff boundary (`>=`).

### Immediate Follow-Up Tasks

- [x] Update provider source-mismatch tests to assert empty result sets.
- [x] Expand `UserTest` to assert password hash cast behavior.
- [x] Expand `UserTest` relationships to assert cross-user exclusion.
- [x] Add cursor whitespace-acceptance test.
- [x] Add queue matcher parsing and compact-stack limit tests.
- [x] Add transit since-cutoff boundary equality test.

## Implementation Evidence Sync (2026-02-25)

Resolved assertion-quality findings are implemented in code and commits:

- SQL literal coupling reduced with behavior assertions:
  - `tests/Unit/Services/Alerts/Providers/FireAlertSelectProviderTest.php`
  - `tests/Unit/Services/Alerts/Providers/TransitAlertSelectProviderTest.php`
  - Commit: `62e8277` (`test(coverage): harden FEED-011 assertions`)
- `UserTest` cast coverage expanded to verify password hashing:
  - `tests/Unit/Models/UserTest.php`
  - Commit: `62e8277`
- `UserTest` relationship isolation expanded to verify cross-user exclusion:
  - `tests/Unit/Models/UserTest.php`
  - Commit: `62e8277`
- Cursor whitespace acceptance added:
  - `tests/Unit/Rules/UnifiedAlertsCursorRuleTest.php`
  - Commit: `62e8277`
- Queue matcher parsing and stack-limit edge cases added:
  - `tests/Unit/Providers/QueueEnqueueDebugServiceProviderTest.php`
  - Commit: `62e8277`
- Transit since-cutoff boundary equality added:
  - `tests/Unit/Services/Alerts/Providers/TransitAlertSelectProviderTest.php`
  - Commit: `62e8277`

Priority implementation milestones reflected in commit history:

- `64cccda` — Priority 1 closure + targeted command hardening
- `417f2ec` / `6ad57d3` — Phase 2 deflake + SailWrapper TTY guard
- `04106c8` — Priority 3 coverage gap closure
- `62e8277` — Assertion hardening and missing edge-case completion
- `dc9d635` — ticket documentation updates for findings
