# Fire Incidents Subsystem (Toronto Fire CAD)

This document describes the current structure and data flow for the Toronto Fire “Active Incidents” subsystem: how incidents are fetched, persisted, and surfaced to the UI.

## High-level data flow

1. **Fetch**: `app/Services/TorontoFireFeedService.php` pulls `livecad.xml` and normalizes it into an array.
2. **Sync**: `app/Console/Commands/FetchFireIncidentsCommand.php` upserts the feed into `fire_incidents` and deactivates incidents no longer present.
3. **Store**: `app/Models/FireIncident.php` represents persisted incidents. Active records are filtered via `FireIncident::active()`.
4. **Present**: `app/Http/Controllers/DashboardController.php` queries active incidents and passes them to Inertia using `app/Http/Resources/FireIncidentResource.php`.
5. **Render**: `resources/js/pages/dashboard.tsx` consumes real props from Inertia and renders the active incident feed.

## Backend structure

### Service: fetch + parse

- `app/Services/TorontoFireFeedService.php`
  - Performs the HTTP request to `https://www.toronto.ca/data/fire/livecad.xml`
  - Parses XML via `simplexml_load_string`
  - Returns:
    - `updated_at` (string from `update_from_db_time`)
    - `events` (list of normalized incident arrays)

### Command: upsert + deactivate

- `app/Console/Commands/FetchFireIncidentsCommand.php`
  - Command: `fire:fetch-incidents`
  - Upserts by `event_num` (`updateOrCreate`)
  - Sets `is_active = false` for any previously active incidents missing from the latest feed snapshot
  - Parses timestamps as America/Toronto and stores UTC

### Model: query surface + factory

- `app/Models/FireIncident.php`
  - `scopeActive()` filters `is_active = true`
  - Uses `HasFactory` for test factories

- `database/factories/FireIncidentFactory.php`
  - Factory for generating `fire_incidents` rows in tests

### Resource: API/props contract

- `app/Http/Resources/FireIncidentResource.php`
  - Defines the serialized shape delivered to the frontend (via Inertia props)
  - Date fields (`dispatch_time`, `feed_updated_at`) are emitted as ISO 8601 strings

### Controller: dashboard projection

- `app/Http/Controllers/DashboardController.php`
  - Renders `dashboard` via Inertia with:
    - `active_incidents`: active incidents ordered by newest dispatch time (limited to 100)
    - `active_incidents_count`: count of all active incidents
    - `active_counts_by_type`: grouped counts by `event_type`
    - `latest_feed_updated_at`: latest known `feed_updated_at` (ISO string)

### Route wiring

- `routes/web.php`
  - `GET /dashboard` now routes to `DashboardController::class` (auth + verified middleware)
  - `GET /api/incidents/{eventNum}/intel` exposes a public read-only Scene Intel timeline for the dashboard

- `routes/settings.php`
  - `POST /api/incidents/{eventNum}/intel` allows manual Scene Intel notes behind `auth` + `verified` and Gate ability `scene-intel.create-manual-entry`

### Scene Intel manual-entry authorization

- Gate definition: `scene-intel.create-manual-entry` in `app/Providers/AppServiceProvider.php`
- Default rule: no one is allowed (fail closed)
- Allowlist rule: set `SCENE_INTEL_MANUAL_ENTRY_ALLOWED_EMAILS` (comma-separated). Only those verified emails are allowed to post manual notes.

## Database structure

### Table

- `fire_incidents` created by `database/migrations/2026_01_31_185634_create_fire_incidents_table.php`

Key fields:

- `event_num` (unique) — feed incident identifier
- `event_type` — feed incident type/category
- `dispatch_time` — incident dispatch timestamp (stored UTC)
- `alarm_level` — numeric alarm level
- `is_active` — whether the incident is in the current feed snapshot
- `feed_updated_at` — feed “update_from_db_time” (stored UTC)

## Frontend structure

### Types (frontend contract)

- `resources/js/types/models/fire-incident.ts`
  - `FireIncident` matches the serialized `FireIncidentResource` payload
  - `FireIncidentTypeCount` matches `active_counts_by_type`

### Dashboard page

- `resources/js/pages/dashboard.tsx`
  - Reads data via `usePage<DashboardProps>().props`
  - Renders:
    - overall active incident count
    - latest feed update time (formatted in America/Toronto)
    - counts by event type
    - a list of active incidents (event type, event number, location, dispatch time, units)

## Operational notes (Sail)

Common commands (Laravel Sail):

```bash
./vendor/bin/sail artisan fire:fetch-incidents
./vendor/bin/sail artisan schedule:list
./vendor/bin/sail artisan schedule:work
```

To verify the dashboard integration:

```bash
./vendor/bin/sail artisan test --filter DashboardTest
```
