# YRT Service Implementation Plan

> **Reference architecture only.** This document outlines how York Region Transit (YRT) service alerts will be integrated into GTA Alerts. It adapts the original RACC News reference plan to fit the GTA Alerts unified alerts architecture (Provider & Adapter pattern). Do not apply the original plan directly.

---

## Data Sources

YRT has two alert sources:

### 1. Service Advisories (HTML scraping)
- **URL:** `https://www.yrt.ca/modules/news/en/serviceadvisories`
- **Type:** HTML list + detail pages
- **Update frequency:** Every 5 minutes (recommended)

### 2. Service Changes (JSON API)
- **URL:** `https://www.yrt.ca/Modules/NewsModule/services/getServiceAdvisories.ashx?categories=b8f1acba-f043-ec11-9468-0050569c41bf&lang=en`
- **Type:** JSON API
- **Update frequency:** Every 5 minutes (recommended)
- **Notes:** Provides structured data without needing HTML parsing. Does not include `cause`/`effect` fields.

### Data Shape

Both sources contain:
- `title` — alert headline
- `posted_at` — publication timestamp
- `route_number` / `route_name` — affected route(s)
- `effect` — e.g., Detour, Delay (Service Changes only; cause not provided)

No coordinates are provided. Route/stop descriptions populate `location_name` in the unified schema.

---

## Architecture

### GTA Alerts Pattern (use this)

All new alert sources follow the **Provider & Adapter** pattern:

```
Source Model (YrtAlert)
    ↓
AlertSelectProvider (YrtAlertSelectProvider)
    ↓
UNION Query (UnifiedAlertsQuery)
    ↓
UnifiedAlert DTO (transport)
    ↓
DomainAlert (frontend)
    ↓
AlertPresentation (frontend view model)
```

**Key components to create:**
1. `YrtAlert` model + migration
2. `YrtAlertFeedService` — fetch + parse both sources
3. `FetchYrtAlertsCommand` — artisan command
4. `YrtAlertSelectProvider` — tagged `alerts.select-providers`
5. `AlertSource` enum update — add `Yrt` variant
6. Domain TypeScript types + mapper
7. `Schedule::call()` in `routes/console.php`

**Do NOT use:**
- `PublicTransitAlertsController` (RACC pattern)
- `LlmApiService` / `HtmlToMarkdownService`
- Laravel Scout

---

## Phase 1: Model and Migration

### Schema

Follow the pattern in `FireIncident` / `PoliceCall` migrations. The base unified columns are:

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | Auto |
| `external_id` | string unique | Source's own ID (upsert key) |
| `source` | string | Always `yrt` |
| `is_active` | boolean | Default true; cleared alerts set false |
| `timestamp` | timestamp | `posted_at` from source |
| `title` | string | Alert headline |
| `location_name` | string nullable | Route/stop description (from `route_number` + `route_name`) |
| `lat` | decimal nullable | Always null for transit |
| `lng` | decimal nullable | Always null for transit |
| `meta` | json | Source-specific: `alert_type` (`service_advisory` or `service_change`), `route_number`, `route_name`, `cause`, `effect`, `effective_start_date`, `effective_end_date`, `original_payload`, `payload_hash` |

```php
Schema::create('yrt_alerts', function (Blueprint $table) {
    $table->id();
    $table->string('external_id')->unique();
    $table->string('source')->default('yrt');
    $table->boolean('is_active')->default(true);
    $table->timestamp('timestamp');
    $table->string('title');
    $table->string('location_name')->nullable();
    $table->decimal('lat', 10, 7)->nullable();
    $table->decimal('lng', 10, 7)->nullable();
    $table->json('meta');
    $table->timestamps();

    $table->index(['is_active', 'timestamp']);
    $table->index('external_id');
});
```

### Model (`YrtAlert`)

```php
// app/Models/YrtAlert.php
class YrtAlert extends Model
{
    use HasFactory;

    protected $fillable = [
        'external_id',
        'source',
        'is_active',
        'timestamp',
        'title',
        'location_name',
        'lat',
        'lng',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'timestamp' => 'datetime',
            'meta' => 'array',
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
        ];
    }

    public function getAlertTypeAttribute(): ?string
    {
        return $this->meta['alert_type'] ?? null;
    }

    public function getRouteNumberAttribute(): ?string
    {
        return $this->meta['route_number'] ?? null;
    }

    public function getRouteNameAttribute(): ?string
    {
        return $this->meta['route_name'] ?? null;
    }

    public function getCauseAttribute(): ?string
    {
        return $this->meta['cause'] ?? null;
    }

    public function getEffectAttribute(): ?string
    {
        return $this->meta['effect'] ?? null;
    }
}
```

---

## Phase 2: Feed Service

### Feed Service (`YrtAlertFeedService`)

```php
// app/Services/Alerts/Providers/YrtAlertFeedService.php
class YrtAlertFeedService
{
    private const ADVISORIES_URL = 'https://www.yrt.ca/modules/news/en/serviceadvisories';
    private const SERVICE_CHANGES_URL = 'https://www.yrt.ca/Modules/NewsModule/services/getServiceAdvisories.ashx?categories=b8f1acba-f043-ec11-9468-0050569c41bf&lang=en';

    public function fetchAlerts(): array
    {
        // Fetch both sources and merge
        $advisories = $this->fetchServiceAdvisories();
        $serviceChanges = $this->fetchServiceChanges();
        return array_merge($advisories, $serviceChanges);
    }

    public function fetchServiceAdvisories(): array
    {
        // 1. Fetch HTML list page
        // 2. Parse div.blogItem entries
        // 3. For each item: fetch detail page, parse fields
        // 4. Return array of parsed alerts with alert_type = 'service_advisory'
    }

    public function fetchServiceChanges(): array
    {
        // 1. Fetch JSON from SERVICE_CHANGES_URL
        // 2. Parse each entry (no detail page needed)
        // 3. Derive external_id from basename of 'link' field
        // 4. Return array of parsed alerts with alert_type = 'service_change'
    }
}
```

### Parsing Notes — Service Advisories (HTML)

- **List page selector:** `div.blogItem` — extract `h2 > a[href]` for detail URL
- **Detail page:** Parse content for `route_number`, `route_name`, dates
- **external_id:** Derived from detail page URL slug (basename of URL path)
- **Change detection:** SHA1 hash of full detail HTML stored in `meta.original_payload`

### Parsing Notes — Service Changes (JSON)

- **Response:** Array of JSON objects with `title`, `description`, `postedDate`, `postedTime`, `link`
- **external_id:** `basename($change['link'])`
- **effective dates:** Not provided in this endpoint — leave null
- **cause:** Not provided — leave null
- **effect:** `description` field (trimmed)
- **Change detection:** SHA1 hash of full JSON object stored in `meta.original_payload`

### Alert Normalization to Unified Schema

```php
private function normalizeToUnified(array $parsed, string $alertType, array $originalPayload): array
{
    $routeDesc = trim(implode(' ', array_filter([
        $parsed['route_number'] ?? null,
        $parsed['route_name'] ? "- {$parsed['route_name']}" : null,
    ])));

    return [
        'external_id' => $parsed['external_id'],
        'source' => 'yrt',
        'is_active' => true,
        'timestamp' => Carbon::parse($parsed['posted_at']),
        'title' => $parsed['title'],
        'location_name' => $routeDesc ?: null,
        'lat' => null,
        'lng' => null,
        'meta' => [
            'alert_type' => $alertType,
            'route_number' => $parsed['route_number'] ?? null,
            'route_name' => $parsed['route_name'] ?? null,
            'cause' => $parsed['cause'] ?? null,
            'effect' => $parsed['effect'] ?? null,
            'effective_start_date' => $parsed['effective_start_date'] ?? null,
            'effective_end_date' => $parsed['effective_end_date'] ?? null,
            'original_payload' => $originalPayload,
            'payload_hash' => sha1(is_array($originalPayload) ? json_encode($originalPayload) : $originalPayload),
        ],
    ];
}
```

---

## Phase 3: Fetch Command

```php
// app/Console/Commands/FetchYrtAlerts.php
class FetchYrtAlerts extends Command
{
    protected $signature = 'yrt:fetch-alerts';
    protected $description = 'Fetch and sync YRT service alerts';

    public function handle(YrtAlertFeedService $feedService): int
    {
        $this->info('Fetching YRT alerts...');

        $alerts = $feedService->fetchAlerts();
        $count = count($alerts);

        foreach ($alerts as $alert) {
            YrtAlert::updateOrCreate(
                ['external_id' => $alert['external_id']],
                $alert
            );
        }

        $externalIds = collect($alerts)->pluck('external_id');
        YrtAlert::where('source', 'yrt')
            ->whereNotIn('external_id', $externalIds)
            ->update(['is_active' => false]);

        $this->info("YRT alerts synced: {$count} active");

        return Command::SUCCESS;
    }
}
```

---

## Phase 4: AlertSelectProvider

```php
// app/Services/Alerts/Providers/YrtAlertSelectProvider.php
class YrtAlertSelectProvider implements AlertSelectProvider
{
    public function tag(): string
    {
        return 'yrt';
    }

    public function select(int $limit, ?int $sinceTimestamp, ?string $source): array
    {
        // Uses DB::getDriverName() check for sqlite vs mysql concat
        // Returns: id, source, external_id, is_active, timestamp, title,
        //          location_name, lat, lng, meta
    }
}
```

See `FireAlertSelectProvider` or `GoTransitAlertSelectProvider` for the full implementation pattern.

---

## Phase 5: AlertSource Enum

```php
// app/Enums/AlertSource.php
enum AlertSource: string
{
    case Fire = 'fire';
    case Police = 'police';
    case Transit = 'transit';
    case GoTransit = 'go_transit';
    case Drt = 'drt';
    case Yrt = 'yrt';       // Add
    case MiWay = 'miway';   // Add (add all three in one PR)
}
```

---

## Phase 6: Scheduling

In `routes/console.php`, add:

```php
Schedule::call(function (ScheduledFetchJobDispatcher $dispatcher) {
    return $dispatcher->dispatch('yrt:fetch-alerts');
})->everyFiveMinutes()->name('yrt:fetch-alerts');
```

---

## Phase 7: Frontend Domain

### TypeScript Types

```typescript
// resources/js/features/gta-alerts/domain/alerts/sources/yrt.ts
export interface YrtAlertTransport {
  id: number;
  source: 'yrt';
  external_id: string;
  is_active: boolean;
  timestamp: string;
  title: string;
  location_name: string | null;
  lat: null;
  lng: null;
  meta: {
    alert_type: 'service_advisory' | 'service_change';
    route_number: string | null;
    route_name: string | null;
    cause: string | null;
    effect: string | null;
    effective_start_date: string | null;
    effective_end_date: string | null;
  };
}

export interface YrtDomainAlert extends DomainAlert {
  source: 'yrt';
  externalId: string;
  isActive: boolean;
  timestamp: Date;
  title: string;
  locationName: string | null;
  locationCoords: null;
  meta: {
    alertType: 'service_advisory' | 'service_change';
    routeNumber: string | null;
    routeName: string | null;
    cause: string | null;
    effect: string | null;
    effectiveStartDate: Date | null;
    effectiveEndDate: Date | null;
  };
}
```

### Mapper

```typescript
// resources/js/features/gta-alerts/domain/alerts/sources/yrt.ts
export function fromResourceYrt(resource: YrtAlertTransport): YrtDomainAlert {
  return {
    id: `yrt:${resource.external_id}`,
    source: 'yrt' as const,
    externalId: resource.external_id,
    isActive: resource.is_active,
    timestamp: new Date(resource.timestamp),
    title: resource.title,
    locationName: resource.location_name,
    locationCoords: null,
    meta: {
      alertType: resource.meta.alert_type,
      routeNumber: resource.meta.route_number ?? null,
      routeName: resource.meta.route_name ?? null,
      cause: resource.meta.cause ?? null,
      effect: resource.meta.effect ?? null,
      effectiveStartDate: resource.meta.effective_start_date
        ? new Date(resource.meta.effective_start_date)
        : null,
      effectiveEndDate: resource.meta.effective_end_date
        ? new Date(resource.meta.effective_end_date)
        : null,
    },
  };
}
```

### Presentation

In `mapDomainAlertToPresentation`, add:

```typescript
case 'yrt':
  return {
    id: alert.id,
    source: 'YRT',
    title: alert.title,
    location: alert.locationName ?? 'YRT Alert',
    severity: mapCauseToSeverity(alert.meta.cause),
    status: mapEffectToStatus(alert.meta.effect),
    timestamp: alert.timestamp,
    coordinates: null,
    raw: alert,
  };
```

---

## Verification

```bash
php artisan yrt:fetch-alerts
php artisan tinker --execute="YrtAlert::count(); YrtAlert::latest('timestamp')->first();"
```

Run the unified feed query:

```bash
php artisan tinker --execute="app(\App\Services\Alerts\UnifiedAlertsQuery::class)->cursorPaginate(\App\Services\Alerts\DTOs\UnifiedAlertsCriteria::fromRequest(['source' => 'yrt']))"
```
