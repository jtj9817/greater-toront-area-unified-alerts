# Fire Incidents Subsystem (Toronto Fire CAD)

This document describes the structure and data flow for the Toronto Fire "Active Incidents" subsystem: how incidents are fetched, persisted, and surfaced to both the public GTA Alerts feed and the authenticated admin dashboard.

## Surfaces

Fire incidents are exposed through two distinct surfaces:

| Surface | Route | Controller | Audience |
|---|---|---|---|
| **Unified GTA Alerts feed** | `GET /` and `GET /api/feed` | `GtaAlertsController` / `Api\FeedController` | Public (primary) |
| **Admin dashboard** | `GET /dashboard` | `DashboardController` | Authenticated users only |

The unified feed is the primary public-facing surface. It aggregates fire incidents alongside Police, TTC Transit, and GO Transit records via `FireAlertSelectProvider` and the `UNION ALL` query. The admin dashboard is a fire-only authenticated view retained from the original integration.

## High-level data flow

1. **Fetch**: `app/Services/TorontoFireFeedService.php` pulls `livecad.xml` and normalizes it into an array.
2. **Sync**: `app/Console/Commands/FetchFireIncidentsCommand.php` upserts the feed into `fire_incidents`, generates Scene Intel diffs, and deactivates incidents no longer present.
3. **Store**: `app/Models/FireIncident.php` represents persisted incidents. Active records are filtered via `FireIncident::active()`.
4. **Present (unified feed)**: `FireAlertSelectProvider` selects fire incidents into the unified `UNION ALL` query. `GtaAlertsController` / `Api\FeedController` deliver `UnifiedAlertResource[]` to the frontend.
5. **Present (admin dashboard)**: `DashboardController` queries `FireIncident` directly and delivers `FireIncidentResource[]` via Inertia props to `dashboard.tsx`.

## Backend structure

### Service: fetch + parse

- `app/Services/TorontoFireFeedService.php`
  - Performs the HTTP request to `https://www.toronto.ca/data/fire/livecad.xml`
  - Parses XML via `simplexml_load_string`
  - Returns:
    - `updated_at` (string from `update_from_db_time`)
    - `events` (list of normalized incident arrays)

### Command: upsert + deactivate + Scene Intel

- `app/Console/Commands/FetchFireIncidentsCommand.php`
  - Command: `fire:fetch-incidents`
  - Upserts by `event_num` (`updateOrCreate`)
  - Captures previous state and calls `SceneIntelProcessor::processIncidentUpdate()` after each upsert
  - Sets `is_active = false` for any previously active incidents missing from the latest feed snapshot
  - Generates `PHASE_CHANGE` Scene Intel entries for the deactivated set
  - Parses timestamps as America/Toronto and stores UTC
  - Scheduled every 5 minutes in `routes/console.php` via `FetchFireIncidentsJob`

### Model: query surface + factory

- `app/Models/FireIncident.php`
  - `scopeActive()` filters `is_active = true`
  - `hasMany` relationship to `IncidentUpdate` (Scene Intel)
  - Uses `HasFactory` for test factories

- `database/factories/FireIncidentFactory.php`
  - Factory for generating `fire_incidents` rows in tests

### Unified feed provider

- `app/Services/Alerts/Providers/FireAlertSelectProvider.php`
  - Implements `AlertSelectProvider` interface
  - Selects the unified row contract columns from `fire_incidents`
  - Embeds `intel_summary` (latest 3 Scene Intel entries) and `intel_last_updated` in `meta` for reduced blank-state latency
  - Handles cross-driver SQL for SQLite, MySQL, and PostgreSQL (FTS + `ILIKE` fallback)
  - Tagged in `AppServiceProvider` with `alerts.select-providers`

### Admin dashboard resource

- `app/Http/Resources/FireIncidentResource.php`
  - Defines the serialized shape for the authenticated dashboard (via Inertia props)
  - Date fields (`dispatch_time`, `feed_updated_at`) are emitted as ISO 8601 strings

### Controllers

**Public unified feed:**
- `app/Http/Controllers/GtaAlertsController.php` — Inertia page, serves `gta-alerts` view
- `app/Http/Controllers/Api/FeedController.php` — JSON batch endpoint (`/api/feed`) for infinite scroll

**Authenticated admin dashboard:**
- `app/Http/Controllers/DashboardController.php`
  - Renders `dashboard` via Inertia with:
    - `active_incidents`: active incidents ordered by newest dispatch time (limited to 100)
    - `active_incidents_count`: count of all active incidents
    - `active_counts_by_type`: grouped counts by `event_type`
    - `latest_feed_updated_at`: latest known `feed_updated_at` (ISO string)

### Route wiring

- `routes/web.php`
  - `GET /` → `GtaAlertsController` (public, unified feed)
  - `GET /dashboard` → `DashboardController` (auth + verified, fire-only admin view)
  - `GET /api/incidents/{eventNum}/intel` → `SceneIntelController::timeline()` (public read-only)

- `routes/settings.php`
  - `POST /api/incidents/{eventNum}/intel` → `SceneIntelController::store()` (auth + verified + Gate `scene-intel.create-manual-entry`)

### Scene Intel authorization

- Gate: `scene-intel.create-manual-entry` defined in `app/Providers/AppServiceProvider.php`
- Default: fail-closed (no one allowed)
- Enable via `SCENE_INTEL_ALLOWED_EMAILS` env var (comma-separated verified emails)

## Database structure

### Table

- `fire_incidents` created by `database/migrations/2026_01_31_185634_create_fire_incidents_table.php`

Key fields:

- `event_num` (unique) — feed incident identifier
- `event_type` — feed incident type/category
- `dispatch_time` — incident dispatch timestamp (stored UTC)
- `alarm_level` — numeric alarm level
- `units_dispatched` — text (widened from varchar(255) by `2026_03_06_120000_change_fire_incidents_units_dispatched_to_text.php`)
- `is_active` — whether the incident is in the current feed snapshot
- `feed_updated_at` — feed "update_from_db_time" (stored UTC)

## Frontend structure

### Unified GTA Alerts page (primary)

- `resources/js/pages/gta-alerts.tsx` — Inertia page; mounts the GTA Alerts feature module
- `resources/js/features/gta-alerts/components/FeedView.tsx` — renders the server-filtered feed with infinite scroll
- Fire incidents arrive as `DomainAlert` (kind: `'fire'`) via `fromResource(...)` at the typed domain boundary
- Presentation is derived by `mapDomainAlertToPresentation(...)` in `resources/js/features/gta-alerts/domain/alerts/view/`
- `AlertDetailsView.tsx` renders the `SceneIntelTimeline` component for fire incidents

### Authenticated admin dashboard

- `resources/js/pages/dashboard.tsx` — fire-only view; consumes `FireIncidentResource` props
- `resources/js/types/models/fire-incident.ts` — `FireIncident` and `FireIncidentTypeCount` types (admin dashboard only)

## Operational notes

Common Artisan commands:

```bash
php artisan fire:fetch-incidents   # Manually sync the fire feed
php artisan scheduler:status       # Check scheduler health
```

Test filters:

```bash
php artisan test --filter FetchFireIncidentsCommandTest
php artisan test --filter FireAlertSelectProviderTest
php artisan test --filter SceneIntelControllerTest
```

## Related documentation

- `docs/backend/scene-intel.md` — Scene Intel architecture, API, and frontend
- `docs/sources/toronto-fire.md` — CAD feed field specification
- `docs/backend/unified-alerts-system.md` — Unified feed query and provider pattern
- `docs/backend/database-schema.md` — Full schema reference
