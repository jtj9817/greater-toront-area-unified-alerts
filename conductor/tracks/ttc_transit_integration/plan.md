# Implementation Plan: TTC Transit Integration

**Created**: 2026-02-05  
**Completed**: In Progress  
**Status**: In Progress (Phases 1-3 Complete)  
**Purpose**: Replace the transit placeholder path with full TTC ingestion and unified dashboard integration aligned with current backend/frontend architecture.

---

## Problem Statement
The repository already includes transit as a first-class source enum and provider registration, but transit remains non-functional end-to-end.

1. `TransitAlertSelectProvider` is intentionally stubbed (`WHERE 1 = 0`), so unified alerts never include transit rows.
2. No `transit_alerts` storage or TTC ingestion pipeline exists yet (service, command, job, model, migration, factory).
3. `GtaAlertsController::latestFeedUpdatedAt()` only compares fire and police timestamps.
4. Frontend transit rendering currently uses generic rules; it does not map TTC route-type/effect metadata to richer icon/severity behavior.

Without resolving these gaps, the transit track cannot contribute real data to the GTA alerts experience.

---

## Design Decisions

| Decision | Choice |
| :--- | :--- |
| Feed strategy | Composite service with 3 sources (`alerts.ttc.ca` API + SXA + static page) |
| Source criticality | Source 1 required; Sources 2/3 best-effort with warnings |
| Persistence model | Single `transit_alerts` table with `source_feed` discriminator |
| ID strategy | Prefixed deterministic IDs (`api:`, `sxa:`, `static:`) in `external_id` |
| Provider integration | Keep existing tagged provider architecture; replace transit stub only |
| Frontend strategy | Use provider `meta` for transit severity/icon/description mapping |
| Polling cadence | `transit:fetch-alerts` every 5 minutes with `withoutOverlapping()` |

---

## Solution Architecture

### Overview
```text
TTC Sources
  |- alerts.ttc.ca live-alerts (primary)
  |- Sitecore SXA search endpoints (secondary)
  `- Static advisory page (secondary)
          v
App\Services\TtcAlertsFeedService
  - fetch/normalize/merge
  - return updated_at + alerts[]
          v
App\Console\Commands\FetchTransitAlertsCommand
  - upsert transit_alerts
  - mark stale rows inactive
          v
transit_alerts table
          v
App\Services\Alerts\Providers\TransitAlertSelectProvider
  - map into unified select contract
          v
App\Services\Alerts\UnifiedAlertsQuery (already existing)
          v
GtaAlertsController + AlertService.ts rendering
```

---

## Implementation Tasks

### Phase 1: Persistence Layer

#### [x] Task 1.1: Create `transit_alerts` migration (Completed 2026-02-05)
**File**: `database/migrations/*_create_transit_alerts_table.php`

Requirements:
- Add lifecycle columns (`is_active`, `feed_updated_at`, timestamps).
- Add alert metadata columns from `docs/sources/ttc-transit.md`.
- Use `mediumText` for `description`.
- Add required indexes:
  - unique `external_id`
  - (`is_active`, `active_period_start`)
  - `feed_updated_at`
  - `source_feed`
  - `route_type`

#### [x] Task 1.2: Add `TransitAlert` model (Completed 2026-02-05)
**File**: `app/Models/TransitAlert.php`

Requirements:
- Add `HasFactory`.
- Define fillable columns matching migration.
- Cast datetime/boolean fields consistently with `FireIncident` and `PoliceCall`.
- Add `scopeActive()` convenience scope.

#### [x] Task 1.3: Add `TransitAlertFactory` (Completed 2026-02-05)
**File**: `database/factories/TransitAlertFactory.php`

Requirements:
- Provide sensible defaults for required fields.
- Add states used by tests (`inactive`, `subway`, `elevator`, `sxa`).

#### [x] Task 1.4: Add model tests (Completed 2026-02-05)
**File**: `tests/Unit/Models/TransitAlertTest.php`

Assertions:
- Fillable list.
- Cast behavior.
- `active()` scope behavior.

---

### Phase 2: TTC Feed Ingestion

#### [x] Task 2.1: Implement composite feed service (Completed 2026-02-05)
**File**: `app/Services/TtcAlertsFeedService.php`

Requirements:
- Implement `fetch(): array{updated_at: \Carbon\CarbonInterface, alerts: array}`.
- Pull and normalize all 3 TTC sources.
- Treat Source 1 failure as fatal (`RuntimeException`).
- Continue on Source 2/3 parser failures with logs.
- Use shared HTTP client with timeout/retry + browser-like headers.

Normalization requirements:
- Normalize timestamps to UTC Carbon instances.
- Convert sentinel end time (`0001-01-01T00:00:00Z`) to `null`.
- Strip/sanitize description HTML before storage.
- Produce deterministic prefixed external IDs.

#### [x] Task 2.2: Implement sync command (Completed 2026-02-05)
**File**: `app/Console/Commands/FetchTransitAlertsCommand.php`

Requirements:
- Signature: `transit:fetch-alerts`.
- Upsert by `external_id`.
- Set `is_active = true` for current rows.
- Mark previously active-but-absent rows inactive.
- Persist `feed_updated_at` for synced rows.
- Emit concise success/failure command output (pattern-matched to existing command tests).

#### [x] Task 2.3: Implement queue job wrapper (Completed 2026-02-05)
**File**: `app/Jobs/FetchTransitAlertsJob.php`

Requirements:
- Match retry/backoff pattern (`tries=3`, `backoff=30`).
- Call `Artisan::call('transit:fetch-alerts')`.

#### [x] Task 2.4: Add ingestion tests (Completed 2026-02-05)
**Files**:
- `tests/Feature/Services/TtcAlertsFeedServiceTest.php`
- `tests/Feature/Commands/FetchTransitAlertsCommandTest.php`
- `tests/Feature/Jobs/FetchTransitAlertsJobTest.php`

Coverage goals:
- successful parse and normalization.
- primary source failure path.
- stale-row deactivation behavior.
- job command invocation + retry config.

---

### Phase 3: Unified Backend Integration

#### [x] Task 3.1: Replace transit provider stub (Completed 2026-02-06)
**File**: `app/Services/Alerts/Providers/TransitAlertSelectProvider.php`

Requirements:
- Select from `TransitAlert` model (or query builder) with unified shape:
  - `id`, `source`, `external_id`, `is_active`, `timestamp`, `title`, `location_name`, `lat`, `lng`, `meta`.
- Implement database-driver-safe SQL for string concat + JSON object (mirror fire/police provider style).
- Build `location_name` from route/stop data.
- Leave lat/lng null for route-based alerts.

#### [x] Task 3.2: Update transit provider unit tests (Completed 2026-02-06)
**File**: `tests/Unit/Services/Alerts/Providers/TransitAlertSelectProviderTest.php`

Requirements:
- Replace placeholder-empty assertion with real mapping assertions.
- Verify non-empty rows when transit fixtures exist.
- Assert `source='transit'`, non-empty `external_id`, and JSON `meta` keys.

#### [x] Task 3.3: Include transit in feed freshness aggregation (Completed 2026-02-06)
**File**: `app/Http/Controllers/GtaAlertsController.php`

Requirements:
- Query latest `feed_updated_at` from `TransitAlert`.
- Return max of fire/police/transit.
- Preserve current return contract and existing `filters`/`alerts` payload shape.

#### [x] Task 3.4: Add schedule registration (Completed 2026-02-06)
**File**: `routes/console.php`

Requirement:
- Add `Schedule::command('transit:fetch-alerts')->everyFiveMinutes()->withoutOverlapping();`.

---

### Phase 4: Unified Data + Frontend Behavior

#### Task 4.1: Seed transit fixtures for mixed-feed tests
**File**: `database/seeders/UnifiedAlertsTestSeeder.php`

Requirements:
- Insert active and cleared transit rows with deterministic timestamps.
- Ensure ordering assertions remain deterministic with existing fire/police fixtures.

#### Task 4.2: Expand unified query tests
**File**: `tests/Feature/UnifiedAlerts/UnifiedAlertsQueryTest.php`

Assertions:
- mixed feed includes transit IDs.
- status filtering handles transit active/cleared rows.
- deterministic ordering still holds with 3 providers.

#### Task 4.3: Extend controller feature tests
**File**: `tests/Feature/GtaAlertsTest.php`

Assertions:
- `alerts.data` includes transit records.
- `latest_feed_updated_at` reflects newest value across all 3 sources.

#### Task 4.4: Improve transit UI mapping logic
**Files**:
- `resources/js/features/gta-alerts/services/AlertService.ts`
- `resources/js/features/gta-alerts/services/AlertService.test.ts`

Requirements:
- Derive transit severity from TTC `meta.severity` + `meta.effect`.
- Choose icon from `meta.route_type` (`directions_subway`, `directions_bus`, `tram`, `elevator`).
- Build richer description from transit metadata rather than only `title`.
- Update Vitest expectations to match new mapping.

---

## File Summary

| File | Action | Status |
| :--- | :--- | :--- |
| `database/migrations/*_create_transit_alerts_table.php` | Create | DONE |
| `app/Models/TransitAlert.php` | Create | DONE |
| `database/factories/TransitAlertFactory.php` | Create | DONE |
| `tests/Unit/Models/TransitAlertTest.php` | Create | DONE |
| `tests/Feature/TransitAlertMigrationTest.php` | Create | DONE |
| `app/Services/TtcAlertsFeedService.php` | Create | DONE |
| `app/Console/Commands/FetchTransitAlertsCommand.php` | Create | DONE |
| `app/Jobs/FetchTransitAlertsJob.php` | Create | DONE |
| `tests/Feature/Services/TtcAlertsFeedServiceTest.php` | Create | DONE |
| `tests/Feature/Commands/FetchTransitAlertsCommandTest.php` | Create | DONE |
| `tests/Feature/Jobs/FetchTransitAlertsJobTest.php` | Create | DONE |
| `app/Services/Alerts/Providers/TransitAlertSelectProvider.php` | Modify | DONE |
| `tests/Unit/Services/Alerts/Providers/TransitAlertSelectProviderTest.php` | Modify | DONE |
| `app/Http/Controllers/GtaAlertsController.php` | Modify | DONE |
| `routes/console.php` | Modify | DONE |
| `database/seeders/UnifiedAlertsTestSeeder.php` | Modify | TODO |
| `tests/Feature/UnifiedAlerts/UnifiedAlertsQueryTest.php` | Modify | TODO |
| `tests/Feature/GtaAlertsTest.php` | Modify | IN PROGRESS |
| `resources/js/features/gta-alerts/services/AlertService.ts` | Modify | TODO |
| `resources/js/features/gta-alerts/services/AlertService.test.ts` | Modify | TODO |

---

## Execution Order
1. Build persistence primitives first (migration/model/factory/tests) to establish stable schema contracts.
2. Implement ingestion service + command/job + tests to populate `transit_alerts`.
3. Replace provider stub and wire backend integration (controller freshness + scheduler).
4. Update seeders/tests and frontend mapping to complete user-visible transit behavior.
5. Run targeted test subsets, then full backend/frontend suites.

---

## Edge Cases to Handle
1. Primary source unavailable: command fails cleanly and does not deactivate all existing rows.
2. Source schema drift (SXA/static parser returns zero results): warning logs without crashing the full sync.
3. TTC indefinite end-time sentinel: persisted as `null` end date.
4. Duplicate alerts across sources: prevented by prefixed `external_id`.
5. WAF/403 from TTC endpoints: mitigated with browser-like headers and retries.

---

## Rollback Plan
1. Disable schedule line for `transit:fetch-alerts` in `routes/console.php`.
2. Revert provider to placeholder behavior if unified query regressions are found.
3. Roll back transit migration to remove `transit_alerts` if needed.

---

## Success Criteria
- [ ] Transit ingestion command syncs records and deactivates stale ones correctly.
- [ ] Unified alerts query includes transit rows with valid IDs and timestamps.
- [ ] `latest_feed_updated_at` includes transit feed updates.
- [ ] Frontend transit cards show TTC-aware severity and icon behavior.
- [ ] Added tests pass under Pest and Vitest.
