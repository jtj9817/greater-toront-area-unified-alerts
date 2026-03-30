# MiWay Service Implementation Plan

> **Reference architecture only.** This document outlines how MiWay (Mississauga Transit) service alerts will be integrated into GTA Alerts. It adapts the original RACC News reference plan to fit the GTA Alerts unified alerts architecture (Provider & Adapter pattern). Do not apply the original plan directly.

---

## Data Source

- **Source type:** HTML scraping
- **URL:** `https://www.mississauga.ca/miway-transit/service-updates/`
- **Update frequency:** Every 5 minutes (recommended)
- **Authentication:** None

### Data Shape

MiWay service updates contain:
- `title` — alert headline (or derived from route + alert text)
- `posted_at` — publication timestamp
- `route_number` / `route_name` — affected route(s) (e.g., "1 Dundas")
- `effect` — e.g., Detour, Delay, Service Change
- `effective_start_date` / `effective_end_date` — validity window

No coordinates are provided. Route/stop descriptions populate `location_name` in the unified schema.

---

## Architecture

### GTA Alerts Pattern (use this)

All new alert sources follow the **Provider & Adapter** pattern:

```
Source Model (MiwayAlert)
    ↓
AlertSelectProvider (MiwayAlertSelectProvider)
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
1. `MiwayAlert` model + migration
2. `MiwayAlertFeedService` — fetch + parse
3. `FetchMiwayAlertsCommand` — artisan command
4. `MiwayAlertSelectProvider` — tagged `alerts.select-providers`
5. `AlertSource` enum update — add `Miway` variant
6. Domain TypeScript types + mapper
7. `Schedule::call()` in `routes/console.php`

**Do NOT use:**
- `PublicTransitAlertsController` (RACC pattern)
- `LlmApiService` / `HtmlToMarkdownService`
- Laravel Scout
- `--fix-records` maintenance mode (RACC-specific)
- Multi-phase LLM processing pipeline (RACC-specific)

---

## Phase 1: Model and Migration

### Schema

Follow the pattern in `FireIncident` / `PoliceCall` migrations. The base unified columns are:

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | Auto |
| `external_id` | string unique | Hash of route + alert content (upsert key) |
| `source` | string | Always `miway` |
| `is_active` | boolean | Default true; cleared alerts set false |
| `timestamp` | timestamp | `posted_at` from source |
| `title` | string | Alert headline |
| `location_name` | string nullable | Route description (route_number + route_name) |
| `lat` | decimal nullable | Always null for transit |
| `lng` | decimal nullable | Always null for transit |
| `meta` | json | Source-specific: `route_number`, `route_name`, `cause`, `effect`, `effective_start_date`, `effective_end_date`, `alert_text`, `original_payload`, `payload_hash` |

```php
Schema::create('miway_alerts', function (Blueprint $table) {
    $table->id();
    $table->string('external_id')->unique();
    $table->string('source')->default('miway');
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

### Model (`MiwayAlert`)

```php
// app/Models/MiwayAlert.php
class MiwayAlert extends Model
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

    public function getAlertTextAttribute(): ?string
    {
        return $this->meta['alert_text'] ?? null;
    }
}
```

---

## Phase 2: Feed Service

### Feed Service (`MiwayAlertFeedService`)

```php
// app/Services/Alerts/Providers/MiwayAlertFeedService.php
class MiwayAlertFeedService
{
    private const BASE_URL = 'https://www.mississauga.ca/miway-transit/service-updates/';

    public function fetchAlerts(): array
    {
        // 1. Fetch HTML page
        // 2. Parse div.accordion items
        // 3. For each accordion: extract route name from button.accordion-title
        // 4. For each li > .alert-text within accordion: parse alert text
        // 5. Return array of parsed alerts
    }
}
```

### Parsing Notes

- **Page selector:** `div.accordion`
- **Route name:** `button.accordion-title` text content (e.g., "1 Dundas")
- **Alert items:** `li > .alert-text` within each accordion content
- **external_id:** SHA1 hash of `route_name + alert_text` — provides stable ID for upsert
- **Change detection:** SHA1 hash of `route_name + alert_text` stored in `meta.payload_hash`
- **Title derivation:** If no explicit title, use `"MiWay Alert: {route_name}"`
- **effective dates:** Not provided on this page — leave null
- **cause:** Not provided — leave null
- **effect:** Derived from alert text keywords (e.g., "detour", "delay") — or use generic "Service Update"

### Alert Normalization to Unified Schema

```php
private function normalizeToUnified(array $parsed): array
{
    $routeDesc = trim($parsed['route_name'] ?? '');

    return [
        'external_id' => $parsed['external_id'],
        'source' => 'miway',
        'is_active' => true,
        'timestamp' => $parsed['posted_at'] ?? now(),
        'title' => $parsed['title'],
        'location_name' => $routeDesc ?: null,
        'lat' => null,
        'lng' => null,
        'meta' => [
            'route_number' => $parsed['route_number'] ?? null,
            'route_name' => $parsed['route_name'] ?? null,
            'cause' => null,
            'effect' => $parsed['effect'] ?? 'Service Update',
            'effective_start_date' => null,
            'effective_end_date' => null,
            'alert_text' => $parsed['alert_text'] ?? null,
            'original_payload' => $parsed['original_payload'],
            'payload_hash' => $parsed['payload_hash'],
        ],
    ];
}
```

---

## Phase 3: Fetch Command

```php
// app/Console/Commands/FetchMiwayAlerts.php
class FetchMiwayAlerts extends Command
{
    protected $signature = 'miway:fetch-alerts';
    protected $description = 'Fetch and sync MiWay service alerts';

    public function handle(MiwayAlertFeedService $feedService): int
    {
        $this->info('Fetching MiWay alerts...');

        $alerts = $feedService->fetchAlerts();
        $count = count($alerts);

        foreach ($alerts as $alert) {
            MiwayAlert::updateOrCreate(
                ['external_id' => $alert['external_id']],
                $alert
            );
        }

        $externalIds = collect($alerts)->pluck('external_id');
        MiwayAlert::where('source', 'miway')
            ->whereNotIn('external_id', $externalIds)
            ->update(['is_active' => false]);

        $this->info("MiWay alerts synced: {$count} active");

        return Command::SUCCESS;
    }
}
```

---

## Phase 4: AlertSelectProvider

```php
// app/Services/Alerts/Providers/MiwayAlertSelectProvider.php
class MiwayAlertSelectProvider implements AlertSelectProvider
{
    public function tag(): string
    {
        return 'miway';
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
    case Yrt = 'yrt';
    case Miway = 'miway';  // Add all three in one PR
}
```

---

## Phase 6: Scheduling

In `routes/console.php`, add:

```php
Schedule::call(function (ScheduledFetchJobDispatcher $dispatcher) {
    return $dispatcher->dispatch('miway:fetch-alerts');
})->everyFiveMinutes()->name('miway:fetch-alerts');
```

---

## Phase 7: Frontend Domain

### Architecture

Same pattern as DRT (see DRT plan Phase 7 for the full explanation):

```
UnifiedAlertResource (source: 'miway')
    → fromResource() switch case 'miway'
    → mapMiwayAlert() validates against MiwayTransitAlertSchema
    → MiwayTransitAlert (kind: 'miway')
```

### Schema

```typescript
// resources/js/features/gta-alerts/domain/alerts/transit/miway/schema.ts
import { z } from 'zod/v4';
import { BaseTransitAlertSchema, BaseTransitMetaSchema } from '../schema';

export const MiwayMetaSchema = BaseTransitMetaSchema.extend({
    route_number: z.nullable(z.string()),
    route_name: z.nullable(z.string()),
    cause: z.nullable(z.string()),
    effect: z.nullable(z.string()),
    effective_start_date: z.nullable(z.string()),
    effective_end_date: z.nullable(z.string()),
    alert_text: z.nullable(z.string()),
});

export type MiwayMeta = z.infer<typeof MiwayMetaSchema>;

export const MiwayTransitAlertSchema = BaseTransitAlertSchema.extend({
    kind: z.literal('miway'),
    meta: MiwayMetaSchema,
});

export type MiwayTransitAlert = z.infer<typeof MiwayTransitAlertSchema>;
```

### Mapper

```typescript
// resources/js/features/gta-alerts/domain/alerts/transit/miway/mapper.ts
import { buildBaseDomainInput } from '../../mapperUtils';
import type { UnifiedAlertResourceParsed } from '../../resource';
import { MiwayTransitAlertSchema } from './schema';
import type { MiwayTransitAlert } from './schema';

export function mapMiwayAlert(
    resource: UnifiedAlertResourceParsed,
): MiwayTransitAlert | null {
    if (resource.source !== 'miway') {
        console.warn(
            `[DomainAlert] MiWay mapper received non-miway resource (${resource.id}):`,
            resource.source,
        );
        return null;
    }

    const result = MiwayTransitAlertSchema.safeParse({
        ...buildBaseDomainInput(resource),
        kind: 'miway',
    });

    if (!result.success) {
        console.warn(
            `[DomainAlert] Invalid MiWay alert (${resource.id}):`,
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
case 'miway': {
    return mapMiwayAlert(validated);
}
```

### Update DomainAlert Union and UnifiedAlertResourceSchema

Same as DRT plan — add `MiwayTransitAlert` to the `DomainAlert` union and `'miway'` to the `source` enum in `UnifiedAlertResourceSchema`.

### Presentation

In `mapDomainAlertToPresentation.ts`, add:

```typescript
case 'miway': {
    type = 'transit';
    severity = deriveTtcSeverity(alert.meta);
    details = buildTtcDescriptionAndMetadata(alert);
    break;
}
```

---

## Verification

```bash
php artisan miway:fetch-alerts
php artisan tinker --execute="MiwayAlert::count(); MiwayAlert::latest('timestamp')->first();"
```

Run the unified feed query:

```bash
php artisan tinker --execute="app(\App\Services\Alerts\UnifiedAlertsQuery::class)->cursorPaginate(\App\Services\Alerts\DTOs\UnifiedAlertsCriteria::fromRequest(['source' => 'miway']))"
```
