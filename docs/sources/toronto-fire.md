# Toronto Fire Active Incidents - Data Extraction

## Context

The City of Toronto publishes real-time fire incident data on their [Active Incidents page](https://www.toronto.ca/community-people/public-safety-alerts/alerts-notifications/toronto-fire-active-incidents/). The HTML table on that page is populated client-side from a public XML feed served by the city's CAD (Computer Aided Dispatch) system. Rather than scraping the rendered HTML (which would require a headless browser and be fragile to layout changes), this implementation fetches the underlying XML feed directly — the same endpoint the page itself uses.

The feed was discovered via network monitoring during browser automation of the page. The "Refresh Active Incident Listing" button on the page simply re-requests this same endpoint with a randomized cache-busting query parameter.

---

## Data Source

**Endpoint:** `https://www.toronto.ca/data/fire/livecad.xml`

- Public, unauthenticated GET request
- Returns XML (~2KB typical payload)
- Updates every 5 minutes from the CAD system
- The page appends a random 6-character query param (e.g. `?r6ds4e`) as a cache-buster; this is optional but replicated in our implementation
- No rate limiting observed; the 5-minute poll interval matches the feed's own update cycle

### XML Schema

```xml
<tfs_active_incidents>
  <update_from_db_time>2026-01-31 13:45:01</update_from_db_time>
  <event>
    <prime_street>WILSON AVE, NY</prime_street>
    <cross_streets>AGATE RD / JULIAN RD</cross_streets>
    <dispatch_time>2026-01-31T13:15:32</dispatch_time>
    <event_num>F26015952</event_num>
    <event_type>Rescue - Elevator</event_type>
    <alarm_lev>0</alarm_lev>
    <beat>144</beat>
    <units_disp>P144, S143</units_disp>
  </event>
</tfs_active_incidents>
```

### Field Reference

| XML Element | DB Column | Type | Description |
|---|---|---|---|
| `event_num` | `event_num` | `string` (unique) | Incident ID. Format: `F` + 2-digit year + sequence (e.g. `F26015952`) |
| `event_type` | `event_type` | `string` | Incident category. Known values: `Vehicle Fire`, `MEDICAL`, `Alarm Single Source`, `Fire Alarm - Check Call`, `Rescue - Elevator`, and others |
| `prime_street` | `prime_street` | `string` (nullable) | Primary street or postal code prefix (e.g. `M5J`). May include directional suffix like `, NY` (near), `, TT` (at), `, ET` (east of), `, SC` (south of) |
| `cross_streets` | `cross_streets` | `string` (nullable) | Intersecting streets separated by ` / `. Empty for postal-code-only entries (MEDICAL calls) |
| `dispatch_time` | `dispatch_time` | `datetime` | ISO 8601 timestamp of initial dispatch |
| `alarm_lev` | `alarm_level` | `unsignedTinyInteger` | 0-6 scale. 0 = initial response, 1 = support fire response, 2-6 = escalating vehicle counts (10-32 vehicles) |
| `beat` | `beat` | `string` (nullable) | Nearest fire station number |
| `units_disp` | `units_dispatched` | `string` (nullable) | Comma-separated unit codes. Can be empty for just-dispatched incidents |
| `update_from_db_time` | `feed_updated_at` | `timestamp` | When the CAD system last refreshed the feed |

### Unit Code Prefixes

| Code | Type | Code | Type |
|---|---|---|---|
| `P` | Pumper | `HR` | Highrise |
| `R` | Rescue | `HZ` | HazMat |
| `A` | Aerial | `FB` | Fireboat |
| `T` | Tower | `CMD` | Command Vehicle |
| `PL` | Platform | `C` | Chief |
| `S` | Squad | `LA` | Air Light |
| `MP` | Mini Pumper | `WT` | Water Tanker |
| `TRS` | Trench Rescue Support | `DE` | Decon |
| `FI` | Fire Investigator | `HS` | Haz Support |

### Alarm Level Scale

| Level | Meaning |
|---|---|
| 0 | Initial response (any event) |
| 1 | Support fire response |
| 2 | 10-14 emergency vehicles |
| 3 | 15-18 emergency vehicles |
| 4 | 19-22 emergency vehicles |
| 5 | 23-28 emergency vehicles |
| 6 | 29-32 emergency vehicles |

---

## Implementation

### Architecture

```
app/
├── Services/
│   └── TorontoFireFeedService.php      # HTTP fetch + XML parse
├── Console/Commands/
│   └── FetchFireIncidentsCommand.php   # fire:fetch-incidents artisan command
├── Jobs/
│   └── FetchFireIncidentsJob.php       # ShouldQueue wrapper for async dispatch
├── Http/
│   ├── Controllers/
│   │   └── DashboardController.php      # Dashboard query + Inertia props
│   └── Resources/
│       └── FireIncidentResource.php    # Frontend data contract
├── Models/
│   └── FireIncident.php                # Eloquent model with active scope
database/migrations/
│   └── 2026_01_31_185634_create_fire_incidents_table.php
database/factories/
│   └── FireIncidentFactory.php         # Test factory for incidents
routes/
│   └── console.php                     # Schedule: every 5 minutes
resources/js/pages/
│   └── dashboard.tsx                   # Renders live incidents from DB
resources/js/types/models/
│   └── fire-incident.ts                # TS types matching backend resource
```

### Database Schema

Table: `fire_incidents`

```sql
id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
event_num          VARCHAR(255) UNIQUE
event_type         VARCHAR(255)
prime_street       VARCHAR(255) NULLABLE
cross_streets      VARCHAR(255) NULLABLE
dispatch_time      DATETIME
alarm_level        TINYINT UNSIGNED DEFAULT 0
beat               VARCHAR(255) NULLABLE
units_dispatched   VARCHAR(255) NULLABLE
is_active          BOOLEAN DEFAULT TRUE
feed_updated_at    TIMESTAMP NULLABLE
created_at         TIMESTAMP
updated_at         TIMESTAMP

INDEX (is_active, dispatch_time)
INDEX (event_type)
```

### Service: `TorontoFireFeedService`

**File:** `app/Services/TorontoFireFeedService.php`

Single method `fetch()` that:

1. Sends a GET request to the XML feed with a 15-second timeout
2. Appends a random 6-character cache-busting query parameter
3. Parses the response body with `simplexml_load_string()`
4. Returns a structured array with `updated_at` (string) and `events` (list of associative arrays)
5. Throws `RuntimeException` on HTTP failures and parse failures

Empty or whitespace-only string fields are normalized to `null`.

### Command: `fire:fetch-incidents`

**File:** `app/Console/Commands/FetchFireIncidentsCommand.php`

Sync logic:

1. Calls `TorontoFireFeedService::fetch()` to get current feed data
2. Iterates events and performs `updateOrCreate` keyed on `event_num` — this upserts existing incidents (updating fields like `alarm_level` or `units_dispatched` that change during an incident's lifetime) and inserts new ones
3. Marks any previously-active incidents that are **no longer present in the feed** as `is_active = false`
4. Outputs a summary: count of active incidents synced, count deactivated, feed timestamp

Returns `Command::SUCCESS` on completion, `Command::FAILURE` if the feed fetch throws.

### Job: `FetchFireIncidentsJob`

**File:** `app/Jobs/FetchFireIncidentsJob.php`

- Implements `ShouldQueue` for async dispatch via the database queue driver
- 3 retries with 30-second backoff between attempts
- Delegates to `Artisan::call('fire:fetch-incidents')`

Dispatch from application code:

```php
use App\Jobs\FetchFireIncidentsJob;

FetchFireIncidentsJob::dispatch();
```

### Schedule

**File:** `routes/console.php`

```php
Schedule::command('fire:fetch-incidents')->everyFiveMinutes();
```

Requires the Laravel scheduler to be running:

```bash
# Development (Sail)
sail artisan schedule:work

# Production (crontab entry)
* * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
```

### Production (Docker scheduler container)

This repo includes a production-friendly scheduler image that bakes in cron and logs scheduler output to Laravel logs:

- Dockerfile: `docker/scheduler/Dockerfile`
- Cron entry: `docker/scheduler/laravel-scheduler` (runs `php artisan scheduler:run-and-log` every minute)
- Startup report + readiness checks: `docker/scheduler/entrypoint.sh`
- Health check (stale/missing heartbeat): `docker/scheduler/healthcheck.sh` (calls `php artisan scheduler:status`)

Scheduler logs land in the normal Laravel log output (typically `storage/logs/laravel.log` in file-based logging).

---

## Dashboard Integration (Inertia + React)

The dashboard now renders **live** incident data from the database rather than hard-coded placeholder content.

### Route

**File:** `routes/web.php`

- `GET /dashboard` routes to `DashboardController::class` and requires `auth` + `verified`

### Controller

**File:** `app/Http/Controllers/DashboardController.php`

Inertia props provided to `resources/js/pages/dashboard.tsx`:

- `active_incidents`: newest-first active incidents (limited to 100)
- `active_incidents_count`: count of all active incidents
- `active_counts_by_type`: grouped active counts by `event_type`
- `latest_feed_updated_at`: latest known `feed_updated_at` timestamp

### Resource (data contract)

**File:** `app/Http/Resources/FireIncidentResource.php`

Defines the serialized shape passed to the frontend. Date fields are returned as ISO 8601 strings.

### Frontend types

**File:** `resources/js/types/models/fire-incident.ts`

Defines `FireIncident` and `FireIncidentTypeCount` to match the backend props.

## Usage

```bash
# Run manually
sail artisan fire:fetch-incidents

# Dispatch to queue
sail artisan tinker --execute="App\Jobs\FetchFireIncidentsJob::dispatch();"

# Query active incidents
sail artisan tinker --execute="echo App\Models\FireIncident::active()->count();"

# Check schedule registration
sail artisan schedule:list
```

### Querying the Model

```php
use App\Models\FireIncident;

// All currently active incidents
FireIncident::active()->get();

// Active incidents ordered by dispatch time
FireIncident::active()->orderByDesc('dispatch_time')->get();

// Filter by incident type
FireIncident::active()->where('event_type', 'Vehicle Fire')->get();

// High alarm level incidents (any that escalated beyond initial response)
FireIncident::active()->where('alarm_level', '>', 0)->get();

// Incidents in a specific fire station area
FireIncident::active()->where('beat', '313')->get();

// Historical: all incidents ever recorded, including resolved
FireIncident::orderByDesc('dispatch_time')->paginate(50);
```

---

## Incident Lifecycle

The feed is a **snapshot** of currently active incidents. The sync logic handles the lifecycle as follows:

```
Feed contains event F26015952     →  upsert with is_active = true
Feed still contains F26015952     →  update fields (alarm_level, units, etc.)
Feed no longer contains F26015952 →  set is_active = false
```

Incidents are never deleted. The `is_active` flag and `created_at`/`updated_at` timestamps provide a full audit trail. The `feed_updated_at` column records the CAD system's own reported update time for each sync cycle.

---

## Observations from Network Analysis

- The HTML page at the public URL loads the XML feed via an AJAX request on page load and on "Refresh" button click
- The page re-renders the entire table from XML on each refresh (no incremental DOM updates)
- The random query parameter (e.g. `?r6ds4e`, `?sefeqk`) is generated client-side purely for cache-busting — the server ignores it
- The page also loads `reCAPTCHA` but this protects form submissions on the page, not the XML feed endpoint
- No cookies, tokens, or authentication headers are required for the XML feed
- The XML feed returns `Content-Type: text/xml` with no CORS restrictions
