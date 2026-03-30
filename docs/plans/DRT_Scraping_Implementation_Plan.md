# DRT Service Implementation Plan

> **Reference architecture only.** This document outlines how Durham Region Transit (DRT) service alerts will be integrated into GTA Alerts. It adapts the original RACC News reference plan to fit the GTA Alerts unified alerts architecture (Provider & Adapter pattern). Do not apply the original plan directly.

---

## Data Source

- **Source type:** HTML scraping (list page + detail pages)
- **List URL:** `/Modules/News/en?feedid=f3f5ff28-b7b8-45ab-8d28-fdf53f51d6bf`
- **Base URL:** Requires research — not yet identified
- **Update frequency:** Every 5 minutes (recommended, aligned with TTC/GO Transit)
- **Authentication:** None expected

### Data Shape

DRT advisories contain:
- `title` — alert headline
- `posted_at` — publication timestamp
- `route_number` / `route_name` — affected route(s)
- `cause` — e.g., Construction, Accident
- `effect` — e.g., Delay, Detour, Service Reduction
- `effective_start_date` / `effective_end_date` — validity window

No coordinates are provided. Route/stop descriptions populate `location_name` in the unified schema.

---

## Architecture

### GTA Alerts Pattern (use this)

All new alert sources follow the **Provider & Adapter** pattern:

```
Source Model (DrtAlert)
    ↓
AlertSelectProvider (DrtAlertSelectProvider)
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
1. `DrtAlert` model + migration
2. `DrtAlertFeedService` — fetch + parse
3. `FetchDrtAlertsCommand` — artisan command
4. `DrtAlertSelectProvider` — tagged `alerts.select-providers`
5. `AlertSource` enum update — add `Drt` variant
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
| `source` | string | Always `drt` |
| `is_active` | boolean | Default true; cleared alerts set false |
| `timestamp` | timestamp | `posted_at` from source |
| `title` | string | Alert headline |
| `location_name` | string nullable | Route/stop description (from `route_number` + `route_name`) |
| `lat` | decimal nullable | Always null for transit |
| `lng` | decimal nullable | Always null for transit |
| `meta` | json | Source-specific fields: `route_number`, `route_name`, `cause`, `effect`, `effective_start_date`, `effective_end_date`, `original_payload` |

```php
Schema::create('drt_alerts', function (Blueprint $table) {
    $table->id();
    $table->string('external_id')->unique();
    $table->string('source')->default('drt');
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

### Model (`DrtAlert`)

```php
// app/Models/DrtAlert.php
class DrtAlert extends Model
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

    // Accessors for source-specific fields stored in meta
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

    public function getEffectiveStartDateAttribute(): ?Carbon
    {
        return isset($this->meta['effective_start_date'])
            ? Carbon::parse($this->meta['effective_start_date'])
            : null;
    }

    public function getEffectiveEndDateAttribute(): ?Carbon
    {
        return isset($this->meta['effective_end_date'])
            ? Carbon::parse($this->meta['effective_end_date'])
            : null;
    }
}
```

---

## Phase 2: Feed Service

### Feed Service (`DrtAlertFeedService`)

```php
// app/Services/Alerts/Providers/DrtAlertFeedService.php
class DrtAlertFeedService
{
    private const BASE_URL = 'https://www.drt.ca'; // RESEARCH REQUIRED

    public function fetchAlerts(): array
    {
        // 1. Fetch list page
        // 2. Parse div.blogItem entries -> extract detail page URLs
        // 3. For each detail page: fetch HTML, parse fields
        // 4. Return array of parsed alert arrays
    }

    public function parseListPage(string $html): array
    {
        // Use Symfony\Component\DomCrawler\Crawler
        // Extract: external_id, title, posted_at, detail_page_url
    }

    public function parseDetailPage(string $html, string $listItemData): array
    {
        // Extract: route_number, route_name, cause, effect,
        //          effective_start_date, effective_end_date, original_html
    }
}
```

### Parsing Notes

- **List page selector:** `div.blogItem` — extract `h2 > a[href]` for detail URL
- **Detail page:** Parse `#blogContentContainer` for full advisory content
- **external_id:** Derived from the detail page URL slug (basename of URL path)
- **Change detection:** SHA1 hash of full detail HTML stored in `meta.original_payload`
- **Pagination:** Check for `.PagedList-skipToNext a` — dispatch chain if found

### Alert Normalization to Unified Schema

```php
private function normalizeToUnified(array $parsed, string $html): array
{
    $routeDesc = trim(implode(' ', array_filter([
        $parsed['route_number'] ?? null,
        $parsed['route_name'] ? "- {$parsed['route_name']}" : null,
    ])));

    return [
        'external_id' => $parsed['external_id'],
        'source' => 'drt',
        'is_active' => true,
        'timestamp' => Carbon::parse($parsed['posted_at']),
        'title' => $parsed['title'],
        'location_name' => $routeDesc ?: null,
        'lat' => null,
        'lng' => null,
        'meta' => [
            'route_number' => $parsed['route_number'] ?? null,
            'route_name' => $parsed['route_name'] ?? null,
            'cause' => $parsed['cause'] ?? null,
            'effect' => $parsed['effect'] ?? null,
            'effective_start_date' => $parsed['effective_start_date'] ?? null,
            'effective_end_date' => $parsed['effective_end_date'] ?? null,
            'original_payload' => $html,
            'payload_hash' => sha1($html),
        ],
    ];
}
```

---

## Phase 3: Fetch Command

```php
// app/Console/Commands/FetchDrtAlerts.php
class FetchDrtAlerts extends Command
{
    protected $signature = 'drt:fetch-alerts';
    protected $description = 'Fetch and sync DRT service alerts';

    public function handle(DrtAlertFeedService $feedService): int
    {
        $this->info('Fetching DRT alerts...');

        $alerts = $feedService->fetchAlerts();
        $count = count($alerts);

        // Upsert
        foreach ($alerts as $alert) {
            DrtAlert::updateOrCreate(
                ['external_id' => $alert['external_id']],
                $alert
            );
        }

        // Mark missing as inactive
        $externalIds = collect($alerts)->pluck('external_id');
        DrtAlert::where('source', 'drt')
            ->whereNotIn('external_id', $externalIds)
            ->update(['is_active' => false]);

        $this->info("DRT alerts synced: {$count} active");

        return Command::SUCCESS;
    }
}
```

---

## Phase 4: AlertSelectProvider

```php
// app/Services/Alerts/Providers/DrtAlertSelectProvider.php
class DrtAlertSelectProvider implements AlertSelectProvider
{
    public function tag(): string
    {
        return 'drt';
    }

    public function select(int $limit, ?int $sinceTimestamp, ?string $source): array
    {
        // Uses DB::getDriverName() check for sqlite vs mysql concat
        // Returns: id, source, external_id, is_active, timestamp, title,
        //          location_name, lat, lng, meta
    }
}
```

See `FireAlertSelectProvider` or `GoTransitAlertSelectProvider` for the full implementation pattern including dual-driver SQL expressions.

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
    case Drt = 'drt';       // Add
    case Yrt = 'yrt';       // Add
    case MiWay = 'miway';   // Add
}
```

---

## Phase 6: Scheduling

In `routes/console.php`, add:

```php
Schedule::call(function (ScheduledFetchJobDispatcher $dispatcher) {
    return $dispatcher->dispatch('drt:fetch-alerts');
})->everyFiveMinutes()->name('drt:fetch-alerts');
```

---

## Phase 7: Frontend Domain

### Architecture

The frontend uses a **single `fromResource()` entry point** that:
1. Validates the transport envelope via `UnifiedAlertResourceSchema` (Zod)
2. Dispatches to a source-specific mapper via `switch (validated.source)`
3. The mapper validates against a source-specific Zod schema

New transit sources follow the same pattern as TTC (`transit`) and GO (`go_transit`):

```
UnifiedAlertResource (source: 'drt')
    → fromResource() switch case 'drt'
    → mapDrtAlert() validates against DrtTransitAlertSchema
    → DrtTransitAlert (kind: 'drt')
```

### Schema

```typescript
// resources/js/features/gta-alerts/domain/alerts/transit/drt/schema.ts
import { z } from 'zod/v4';
import { BaseTransitAlertSchema, BaseTransitMetaSchema } from '../schema';

export const DrtMetaSchema = BaseTransitMetaSchema.extend({
    route_number: z.nullable(z.string()),
    route_name: z.nullable(z.string()),
    cause: z.nullable(z.string()),
    effect: z.nullable(z.string()),
    effective_start_date: z.nullable(z.string()),
    effective_end_date: z.nullable(z.string()),
});

export type DrtMeta = z.infer<typeof DrtMetaSchema>;

export const DrtTransitAlertSchema = BaseTransitAlertSchema.extend({
    kind: z.literal('drt'),
    meta: DrtMetaSchema,
});

export type DrtTransitAlert = z.infer<typeof DrtTransitAlertSchema>;
```

### Mapper

```typescript
// resources/js/features/gta-alerts/domain/alerts/transit/drt/mapper.ts
import { buildBaseDomainInput } from '../../mapperUtils';
import type { UnifiedAlertResourceParsed } from '../../resource';
import { DrtTransitAlertSchema } from './schema';
import type { DrtTransitAlert } from './schema';

export function mapDrtAlert(
    resource: UnifiedAlertResourceParsed,
): DrtTransitAlert | null {
    if (resource.source !== 'drt') {
        console.warn(
            `[DomainAlert] DRT mapper received non-drt resource (${resource.id}):`,
            resource.source,
        );
        return null;
    }

    const result = DrtTransitAlertSchema.safeParse({
        ...buildBaseDomainInput(resource),
        kind: 'drt',
    });

    if (!result.success) {
        console.warn(
            `[DomainAlert] Invalid DRT alert (${resource.id}):`,
            result.error.issues,
        );
        return null;
    }

    return result.data;
}
```

### Register in `fromResource.ts`

```typescript
// resources/js/features/gta-alerts/domain/alerts/fromResource.ts
// Add to the switch:
case 'drt': {
    return mapDrtAlert(validated);
}
```

### Update DomainAlert Union

```typescript
// resources/js/features/gta-alerts/domain/alerts/types.ts
export type DomainAlert =
    | FireAlert
    | PoliceAlert
    | TtcTransitAlert
    | GoTransitAlert
    | DrtTransitAlert   // Add
    | YrtTransitAlert   // Add
    | MiwayTransitAlert; // Add
```

### Update UnifiedAlertResourceSchema Source Enum

```typescript
// resources/js/features/gta-alerts/domain/alerts/resource.ts
export const UnifiedAlertResourceSchema = z.object({
    // ...
    source: z.enum(['fire', 'police', 'transit', 'go_transit', 'drt', 'yrt', 'miway']),
    // ...
});
```

### Presentation

In `mapDomainAlertToPresentation.ts`, add a `case 'drt':` branch. Since transit alerts all use the same structure (`type: 'transit'`), the existing TTC/GO presentation logic can be reused — add a severity derivation for DRT-specific cause values if needed. Location coordinates are already `null` for all transit sources, so no change needed there.

```typescript
case 'drt': {
    type = 'transit';
    severity = deriveTtcSeverity(alert.meta); // Reuse TTC severity logic
    details = buildTtcDescriptionAndMetadata(alert);
    break;
}
```

### Tests

```typescript
// resources/js/features/gta-alerts/domain/alerts/transit/drt/mapper.test.ts
import { describe, it, expect, vi } from 'vitest';
import type { UnifiedAlertResourceParsed } from '../../resource';
import { mapDrtAlert } from './mapper';

describe('mapDrtAlert', () => {
    const timestamp = new Date('2026-02-03T12:00:00Z').toISOString();

    it('maps a valid DRT resource to a DrtTransitAlert', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
        const resource: UnifiedAlertResourceParsed = {
            id: 'drt:123',
            source: 'drt',
            external_id: '123',
            is_active: true,
            timestamp,
            title: 'Route 92 Delay',
            location: { name: '92 Woodrobin', lat: null, lng: null },
            meta: {
                route_number: '92',
                route_name: 'Woodrobin',
                cause: 'Construction',
                effect: 'Delay',
                alert_type: null,
                direction: null,
                effective_start_date: null,
                effective_end_date: null,
            },
        };
        const alert = mapDrtAlert(resource);
        expect(alert).not.toBeNull();
        expect(alert?.kind).toBe('drt');
        expect(alert?.meta.route_number).toBe('92');
        warn.mockRestore();
    });

    it('returns null (and warns) for non-drt source', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
        const resource = { ... } as UnifiedAlertResourceParsed;
        expect(mapDrtAlert({ ...resource, source: 'transit' })).toBeNull();
        expect(warn).toHaveBeenCalled();
        warn.mockRestore();
    });
});
```

---

## Research Required

- [ ] **DRT base URL** — The full base URL for the DRT service advisories feed is not yet identified. The feed path is `/Modules/News/en?feedid=f3f5ff28-b7b8-45ab-8d28-fdf53f51d6bf`. Research `drt.ca` or `durhamregiontransit.com` to confirm the correct base URL.

---

## Verification

```bash
php artisan drt:fetch-alerts
php artisan tinker --execute="DrtAlert::count(); DrtAlert::latest('timestamp')->first();"
```

Run the unified feed query to confirm DRT appears:
```bash
php artisan tinker --execute="app(\App\Services\Alerts\UnifiedAlertsQuery::class)->cursorPaginate(\App\Services\Alerts\DTOs\UnifiedAlertsCriteria::fromRequest(['source' => 'drt']))"
```
