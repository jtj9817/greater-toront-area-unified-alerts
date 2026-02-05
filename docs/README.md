# GTA Alerts Documentation

Welcome to the GTA Alerts documentation. This directory contains comprehensive documentation for the project architecture, implementation, and development practices.

## Quick Links

- **[CHANGELOG.md](CHANGELOG.md)** - Recent changes and version history
- **[CLAUDE.md](../CLAUDE.md)** - Agent guidance and project overview
- **[README.md](../README.md)** - Project README with setup instructions

## Documentation Structure

```
docs/
├── architecture/          # System architecture and design patterns
│   ├── dynamic-zones.md               # Dynamic zones feature (PLANNED)
│   └── provider-adapter-pattern.md    # Provider pattern explanation
│
├── backend/              # Server-side implementation
│   ├── dtos.md                         # UnifiedAlert, UnifiedAlertsCriteria, AlertLocation
│   ├── enums.md                        # AlertSource, AlertStatus, AlertId
│   ├── fire-incidents.md               # Toronto Fire integration
│   ├── mappers.md                      # UnifiedAlertMapper
│   ├── production-scheduler.md         # Scheduler container architecture
│   ├── unified-alerts-qa.md            # Architecture Q&A
│   └── unified-alerts-system.md        # Complete unified alerts system (IMPLEMENTED)
│
├── frontend/             # Client-side implementation
│   ├── alert-service.md               # AlertService mapping logic
│   └── types.md                        # TypeScript type definitions
│
├── sources/              # Data source integrations
│   ├── toronto-fire.md                # Toronto Fire Services XML feed
│   ├── toronto-police.md              # Toronto Police Services scraping
│   ├── toronto-police-architecture.md # Police scraper architecture
│   └── ttc-transit.md                 # TTC Transit alerts (PLANNED)
│
├── archive/              # Historical design documents
│   ├── query-refinement-testing.md    # Query refinement testing track
│   └── unified-alerts-design.md       # Original unified alerts design spec
│
├── domain/               # Business logic documentation (empty)
├── issues/               # Known issues and troubleshooting (empty)
└── CHANGELOG.md          # Version history
```

## Getting Started

### New Developers

1. Read **[README.md](../README.md)** for project setup and quick start
2. Read **[CLAUDE.md](../CLAUDE.md)** for architecture overview and conventions
3. Review **[backend/unified-alerts-system.md](backend/unified-alerts-system.md)** for the core system

### Understanding the Architecture

1. **[architecture/provider-adapter-pattern.md](architecture/provider-adapter-pattern.md)** - How data sources are unified
2. **[backend/unified-alerts-system.md](backend/unified-alerts-system.md)** - Complete system overview
3. **[backend/enums.md](backend/enums.md)** - Type-safe enums for sources and status
4. **[backend/dtos.md](backend/dtos.md)** - Data transfer objects

### Adding New Features

1. **[backend/unified-alerts-system.md](backend/unified-alerts-system.md)** - System architecture
2. **[CLAUDE.md](../CLAUDE.md)** - "Adding New Alert Sources" section
3. **[architecture/provider-adapter-pattern.md](architecture/provider-adapter-pattern.md)** - Extending the provider pattern

### Frontend Development

1. **[frontend/types.md](frontend/types.md)** - TypeScript type definitions
2. **[frontend/alert-service.md](frontend/alert-service.md)** - Service layer mapping
3. **[../resources/js/features/gta-alerts/](../resources/js/features/gta-alerts/)** - Component code

### Data Source Integration

1. **[sources/toronto-fire.md](sources/toronto-fire.md)** - Toronto Fire XML feed
2. **[sources/toronto-police.md](sources/toronto-police.md)** - Toronto Police scraping
3. **[sources/ttc-transit.md](sources/ttc-transit.md)** - TTC Transit (planned)

## Key Concepts

### Unified Alerts System

The core of GTA Alerts is a **Provider & Adapter** pattern that unifies multiple data sources (Fire, Police, Transit) into a single feed:

```
Source Models → Select Providers → UNION Query → UnifiedAlert DTO → AlertItem View-Model
```

- **Source Models:** Raw data tables (`fire_incidents`, `police_calls`)
- **Select Providers:** Adapters that map source tables to unified columns
- **UNION Query:** Database-level aggregation for efficient pagination
- **UnifiedAlert DTO:** Transport shape (UI-agnostic)
- **AlertItem:** Frontend view-model (presentation logic)

### Tagged Provider Injection

New data sources are auto-discovered via Laravel's tagged container:

```php
$this->app->tag([
    FireAlertSelectProvider::class,
    PoliceAlertSelectProvider::class,
    // Add new providers here
], 'alerts.select-providers');
```

### Type Safety

PHP enums provide type-safe constants:

- `AlertSource` - Fire, Police, Transit
- `AlertStatus` - All, Active, Cleared

Value objects validate data:

- `AlertId` - Composite IDs with format validation
- `UnifiedAlertsCriteria` - Query parameters with range validation

## Implementation Status

| Feature | Status | Documentation |
|---------|--------|---------------|
| Toronto Fire Integration | ✅ Implemented | [sources/toronto-fire.md](sources/toronto-fire.md) |
| Toronto Police Integration | ✅ Implemented | [sources/toronto-police.md](sources/toronto-police.md) |
| Unified Alerts Query | ✅ Implemented | [backend/unified-alerts-system.md](backend/unified-alerts-system.md) |
| Provider Pattern | ✅ Implemented | [architecture/provider-adapter-pattern.md](architecture/provider-adapter-pattern.md) |
| TTC Transit Integration | ⚠️ Planned | [sources/ttc-transit.md](sources/ttc-transit.md) |
| Dynamic Zones | ⚠️ Planned | [architecture/dynamic-zones.md](architecture/dynamic-zones.md) |
| Production Scheduler | ✅ Implemented | [backend/production-scheduler.md](backend/production-scheduler.md) |

## Contributing

When adding new documentation:

1. Use clear, descriptive filenames
2. Include code examples with proper syntax highlighting
3. Reference related documents in "Related Documentation" sections
4. Update this README with new file locations
5. Follow existing documentation structure and formatting

## Support

For questions or issues:
1. Check the relevant documentation section above
2. Review the [archive/](archive/) directory for historical context
3. See [CLAUDE.md](../CLAUDE.md) for development conventions
