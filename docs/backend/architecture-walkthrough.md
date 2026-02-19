# Backend Architecture Walkthrough

A comprehensive guide to the GTA Alerts backend architecture, dependency injection patterns, and service relationships.

## Overview

The backend follows a **layered architecture** with clear separation of concerns:

1. **External API Layer** - Data sources (Fire, Police, TTC, GO Transit)
2. **Feed Services** - Stateless fetchers that pull from external APIs
3. **Console Commands** - Orchestrators that persist data to the database
4. **Queue Jobs** - Background processing wrappers
5. **Database Layer** - Source-specific tables for raw data
6. **Provider Layer** - SQL adapters that transform data to a unified schema
7. **Query Layer** - Aggregator that unions all providers
8. **Resource Layer** - API transformation for frontend consumption
9. **Controller Layer** - HTTP entry points

---

## Architectural Topology

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           LAYER 1: EXTERNAL APIs                             │
├─────────────────────────────────────────────────────────────────────────────┤
│  Toronto Fire CAD    Toronto Police ArcGIS    TTC Alerts (JSON + SXA)       │
│  (livecad.xml)       (FeatureServer)          GO Transit (Metrolinx API)    │
└─────────────────────────────────────────────────────────────────────────────┘
                                       │
                                       ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                        LAYER 2: FEED SERVICES (Stateless)                    │
├─────────────────────────────────────────────────────────────────────────────┤
│  TorontoFireFeedService         HTTP Client → Fetches & parses XML           │
│  TorontoPoliceFeedService       HTTP Client → Fetches ArcGIS JSON            │
│  TtcAlertsFeedService           Multi-source aggregator                      │
│  GoTransitFeedService           HTTP Client → Fetches Metrolinx API          │
│                                                                              │
│  • No DI dependencies - use Http facade directly                             │
│  • Return arrays of raw data                                                 │
│  • Handle API-specific parsing (XML, JSON, etc.)                             │
└─────────────────────────────────────────────────────────────────────────────┘
                                       │
                                       ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                      LAYER 3: CONSOLE COMMANDS (Orchestrators)               │
│                    Uses Constructor Injection (Laravel Container)            │
├─────────────────────────────────────────────────────────────────────────────┤
│  FetchFireIncidentsCommand        DI: TorontoFireFeedService                 │
│  FetchPoliceCallsCommand          DI: TorontoPoliceFeedService               │
│  FetchTransitAlertsCommand        DI: TtcAlertsFeedService                   │
│  FetchGoTransitAlertsCommand      DI: GoTransitFeedService                   │
│                                                                              │
│  Pattern:                                                                    │
│  1. Call $service->fetch()                                                   │
│  2. Transform raw data to model attributes                                   │
│  3. Persist via updateOrCreate()                                             │
│  4. Mark stale records inactive                                              │
└─────────────────────────────────────────────────────────────────────────────┘
                                       │
                                       ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                         LAYER 4: QUEUE JOBS (Background)                     │
├─────────────────────────────────────────────────────────────────────────────┤
│  FetchFireIncidentsJob          $tries=3, $backoff=30, $timeout=120          │
│  FetchPoliceCallsJob            WithoutOverlapping middleware (30s release)  │
│  FetchTransitAlertsJob          Dispatched by scheduler via Schedule::job()  │
│  FetchGoTransitAlertsJob        Calls Artisan::call() on underlying command  │
└─────────────────────────────────────────────────────────────────────────────┘
                                       │
                                       ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                            LAYER 5: DATABASE                                 │
├─────────────────────────────────────────────────────────────────────────────┤
│  fire_incidents          police_calls         transit_alerts                 │
│  ─────────────           ────────────         ──────────────                 │
│  event_num (PK)          object_id (PK)       external_id (PK)               │
│  event_type              call_type            title, description             │
│  dispatch_time           occurrence_time      route, severity                │
│  is_active               is_active            is_active                      │
│  feed_updated_at         feed_updated_at      feed_updated_at                │
│                                                                              │
│  go_transit_alerts                                                           │
│  ─────────────────                                                           │
│  external_id (PK)                                                            │
│  message_subject, corridor_or_route                                          │
│  posted_at, is_active                                                        │
└─────────────────────────────────────────────────────────────────────────────┘
                                       │
                                       ▼ (Query via Providers)
┌─────────────────────────────────────────────────────────────────────────────┐
│                         LAYER 6: PROVIDERS (Adapter Pattern)                 │
│                   Transforms source data → Unified schema via SQL            │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│   Interface: AlertSelectProvider                                             │
│   ─────────────────────────────                                              │
│   + select(): Builder                                                        │
│        △                                                                     │
│        │ implements                                                          │
│   ┌────┴────┬────────────┬───────────────┬────────────────┐                  │
│   │         │            │               │                │                  │
│   ▼         ▼            ▼               ▼                ▼                  │
│ FireAlert  PoliceAlert  TransitAlert    GoTransitAlertSelectProvider         │
│ SelectProvider         SelectProvider                                        │
│                                                                              │
│ Each provider:                                                               │
│ • Returns Query Builder with selectRaw()                                     │
│ • Maps columns to unified schema (id, source, external_id, is_active, etc.)  │
│ • Serializes source-specific fields to JSON 'meta' column                    │
│ • Handles SQLite vs MySQL dialect differences                                │
│                                                                              │
│ Unified Schema Columns:                                                      │
│ - id: {source}:{external_id}                                                 │
│ - source: 'fire' | 'police' | 'transit' | 'go_transit'                       │
│ - external_id: Source-specific primary key                                   │
│ - is_active: Boolean status                                                  │
│ - timestamp: Event occurrence time                                           │
│ - title: Human-readable event title                                          │
│ - location_name: String location description                                 │
│ - lat/lng: Coordinates (if available)                                        │
│ - meta: JSON object with source-specific fields                              │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
                                       │
                                       ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                        LAYER 7: UNIFIED ALERTS QUERY                         │
│                    DI: Tagged Providers + Mapper                             │
├─────────────────────────────────────────────────────────────────────────────┤
│  UnifiedAlertsQuery                                                          │
│  ─────────────────                                                           │
│                                                                              │
│  #[Tag('alerts.select-providers')]                                           │
│  private readonly iterable $providers  ← All 4 providers injected            │
│                                                                              │
│  private readonly UnifiedAlertMapper $mapper  ← Mapper injected              │
│                                                                              │
│  paginate(criteria): LengthAwarePaginator                                    │
│  ───────────────────────────────────────                                     │
│    1. unionSelect() → UNION ALL across all providers                         │
│    2. Apply status filter (active/cleared/all)                               │
│    3. Order by timestamp DESC, source, external_id                           │
│    4. Paginate results via Laravel Paginator                                 │
│    5. Map rows → UnifiedAlert DTOs via mapper                                │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
                                       │
                                       ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                           LAYER 8: MAPPERS                                   │
├─────────────────────────────────────────────────────────────────────────────┤
│  UnifiedAlertMapper                                                          │
│  ─────────────────                                                           │
│  fromRow(object $row): UnifiedAlert                                          │
│                                                                              │
│  • Transforms DB query result (stdClass) → UnifiedAlert DTO                  │
│  • Validates required fields (source, external_id, title, timestamp)         │
│  • Parses JSON meta column                                                   │
│  • Creates AlertLocation value object                                        │
│  • Uses AlertId for composite key generation                                 │
└─────────────────────────────────────────────────────────────────────────────┘
                                       │
                                       ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                        LAYER 9: HTTP RESOURCES                               │
├─────────────────────────────────────────────────────────────────────────────┤
│  UnifiedAlertResource (extends JsonResource)                                 │
│  ───────────────────────────────────────────                                 │
│  @mixin UnifiedAlert (DTO)                                                   │
│                                                                              │
│  toArray(): array                                                            │
│    - Transforms DTO → JSON for Inertia.js transport                          │
│    - Formats timestamp as ISO8601                                            │
│    - Unwraps location object                                                 │
│    - Includes meta array                                                     │
└─────────────────────────────────────────────────────────────────────────────┘
                                       │
                                       ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                        LAYER 10: CONTROLLERS                                 │
│                    DI: UnifiedAlertsQuery (Method Injection)                 │
├─────────────────────────────────────────────────────────────────────────────┤
│  GtaAlertsController                                                         │
│  ──────────────────                                                          │
│                                                                              │
│  public function __invoke(                                                   │
│      Request $request,           ← Auto-injected                             │
│      UnifiedAlertsQuery $alerts  ← Auto-resolved from container              │
│  ): Response {                                                               │
│                                                                              │
│      $criteria = new UnifiedAlertsCriteria(status, perPage, page);           │
│      $paginator = $alerts->paginate($criteria);                              │
│                                                                              │
│      return Inertia::render('gta-alerts', [                                  │
│          'alerts' => UnifiedAlertResource::collection($paginator),           │
│          'filters' => [...],                                                 │
│          'latest_feed_updated_at' => ...                                     │
│      ]);                                                                     │
│  }                                                                           │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## Dependency Injection Patterns

### 1. Tagged Services (Provider Pattern)

**Registration** (`AppServiceProvider::register()`):

```php
$this->app->tag([
    FireAlertSelectProvider::class,
    PoliceAlertSelectProvider::class,
    TransitAlertSelectProvider::class,
    GoTransitAlertSelectProvider::class,
], 'alerts.select-providers');
```

**Injection** (`UnifiedAlertsQuery::__construct()`):

```php
public function __construct(
    #[Tag('alerts.select-providers')]
    private readonly iterable $providers,
    private readonly UnifiedAlertMapper $mapper,
) {}
```

The `#[Tag]` attribute (PHP 8+) tells Laravel's container to inject all services tagged with `'alerts.select-providers'` as an iterable array.

### 2. Method Injection (Controllers)

```php
class GtaAlertsController extends Controller
{
    public function __invoke(
        Request $request,
        UnifiedAlertsQuery $alerts
    ): Response {
        // Laravel auto-resolves both parameters
    }
}
```

### 3. Constructor Injection (Console Commands)

```php
class FetchFireIncidentsCommand extends Command
{
    public function handle(TorontoFireFeedService $service): int
    {
        // Service auto-resolved by type-hint
        $data = $service->fetch();
    }
}
```

### 4. No DI (Stateless Services)

Feed services don't use DI - they directly use the `Http` facade:

```php
class TorontoFireFeedService
{
    public function fetch(): array
    {
        $response = Http::timeout(15)
            ->retry(2, 200)
            ->get(self::FEED_URL);

        // Parse and return...
    }
}
```

---

## Data Transfer Objects (DTOs)

All DTOs are **immutable** PHP 8.2+ `readonly` classes:

### UnifiedAlert

```php
readonly class UnifiedAlert
{
    public function __construct(
        public string $id,           // Composite: "{source}:{external_id}"
        public string $source,       // 'fire' | 'police' | 'transit' | 'go_transit'
        public string $externalId,   // Source-specific ID
        public bool $isActive,
        public CarbonImmutable $timestamp,
        public string $title,
        public ?AlertLocation $location,
        public array $meta = [],
    ) {}
}
```

### UnifiedAlertsCriteria

```php
readonly class UnifiedAlertsCriteria
{
    public const DEFAULT_PER_PAGE = 50;
    public const MAX_PER_PAGE = 200;

    public function __construct(
        string $status = AlertStatus::All->value,
        int $perPage = self::DEFAULT_PER_PAGE,
        ?int $page = null,
    ) {
        // Validates and normalizes inputs
    }
}
```

### AlertId (Value Object)

```php
readonly class AlertId
{
    public function __construct(
        public string $source,
        public string $externalId,
    ) {}

    public static function fromParts(string $source, string $externalId): self
    public static function fromString(string $value): self  // Parses "source:id"
    public function value(): string  // Returns "source:id"
}
```

---

## Enums

### AlertSource

```php
enum AlertSource: string
{
    case Fire = 'fire';
    case Police = 'police';
    case Transit = 'transit';
    case GoTransit = 'go_transit';

    public static function values(): array;
    public static function isValid(?string $value): bool;
}
```

### AlertStatus

```php
enum AlertStatus: string
{
    case All = 'all';
    case Active = 'active';
    case Cleared = 'cleared';

    public static function normalize(?string $value): string;
}
```

---

## Scheduling

Data fetchers run as **queued jobs** on Laravel's scheduler (`routes/console.php`). Using `Schedule::job()` instead of `Schedule::command()` enables job-level retries, backoff, and timeout configuration:

```php
// withoutOverlapping(10) sets a 10-minute lock expiry to avoid 24-hour lockouts on crash
Schedule::job(new FetchFireIncidentsJob)->name('fire:fetch-incidents')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::job(new FetchPoliceCallsJob)->name('police:fetch-calls')->everyTenMinutes()->withoutOverlapping(10);
Schedule::job(new FetchTransitAlertsJob)->name('transit:fetch-alerts')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::job(new FetchGoTransitAlertsJob)->name('go-transit:fetch-alerts')->everyFiveMinutes()->withoutOverlapping(10);
```

Each job is configured with `$tries = 3`, `$backoff = 30`, `$timeout = 120` and uses `WithoutOverlapping` middleware for per-job lock management.

Additional scheduled tasks:
```php
Schedule::job(new GenerateDailyDigestJob)->dailyAt('00:10')->withoutOverlapping();
Schedule::command('notifications:prune')->daily()->withoutOverlapping();
Schedule::command('queue:prune-failed', ['--hours' => 168])->daily()->withoutOverlapping();
Schedule::command('model:prune', ['--model' => [IncidentUpdate::class]])->daily()->withoutOverlapping();
// Queue depth monitor runs every 5 minutes; logs error if depth > 100
Schedule::call(...)->name('monitor:queue-depth')->everyFiveMinutes()->withoutOverlapping(5);
```

---

## Key Design Decisions

### 1. Union All Pattern

- **Why**: Efficient pagination across heterogeneous data sources
- **How**: Each provider returns a Query Builder with identical column structure
- **Benefit**: Database handles sorting/filtering; PHP just orchestrates

### 2. JSON Meta Column

- **Why**: Source-specific fields don't pollute unified schema
- **How**: Providers serialize extra data to JSON; mapper deserializes
- **Benefit**: Flexible schema without migrations for new fields

### 3. Tagged DI

- **Why**: Clean way to inject variable number of providers
- **How**: Laravel's service tagging + `#[Tag]` attribute
- **Benefit**: Easy to add new alert sources

### 4. Stateless Feed Services

- **Why**: Simple to test; no hidden dependencies
- **How**: Direct facade usage; pure functions
- **Benefit**: Deterministic behavior; easy mocking

### 5. Immutable DTOs

- **Why**: Prevents accidental mutation; thread-safe
- **How**: PHP 8.2 `readonly` classes
- **Benefit**: Predictable data flow

---

## Adding a New Alert Source

To add a new data source (e.g., "Weather Alerts"):

1. **Create migration**: `create_weather_alerts_table.php`
2. **Create model**: `WeatherAlert extends Model`
3. **Create feed service**: `WeatherFeedService::fetch()`
4. **Create command**: `FetchWeatherAlertsCommand`
5. **Create provider**: `WeatherAlertSelectProvider implements AlertSelectProvider`
6. **Tag provider**: Add to `AppServiceProvider::register()`
7. **Schedule command**: Add to `routes/console.php`

The UnifiedAlertsQuery will automatically include it in the union!

---

## Testing Strategy

### Unit Tests

- Test each FeedService with mocked Http responses
- Test each Provider's SQL output
- Test Mapper with fake row objects

### Integration Tests

- Test full flow: Command → Database → Query → Resource
- Use SQLite in-memory for speed

### Manual Tests

- Run commands directly: `php artisan fire:fetch-incidents`
- Check database: `sqlite3 database/database.sqlite "SELECT * FROM fire_incidents LIMIT 5"`
- Hit endpoint: `curl http://localhost`

---

## Related Documentation

- [Unified Alerts System](unified-alerts-system.md) - Core aggregation logic
- [Provider & Adapter Pattern](../architecture/provider-adapter-pattern.md) - Pattern details
- [DTOs](dtos.md) - Data Transfer Objects reference
- [Enums](enums.md) - Enumeration definitions
- [Mappers](mappers.md) - Data transformation layer
- [Production Scheduler](production-scheduler.md) - Background job observability
