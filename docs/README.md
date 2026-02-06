# GTA Alerts Documentation

This directory contains project documentation for the current production architecture and data-source integrations.

## Quick Links

- **[CHANGELOG.md](CHANGELOG.md)** - Recent project/documentation changes
- **[README.md](../README.md)** - Root project setup and development workflow
- **[CLAUDE.md](../CLAUDE.md)** - Agent notes and additional project context

## Documentation Structure

```
docs/
├── architecture/
│   ├── dynamic-zones.md
│   └── provider-adapter-pattern.md
├── backend/
│   ├── dtos.md
│   ├── enums.md
│   ├── fire-incidents.md
│   ├── mappers.md
│   ├── production-scheduler.md
│   ├── unified-alerts-qa.md
│   └── unified-alerts-system.md
├── frontend/
│   ├── alert-service.md
│   └── types.md
├── sources/
│   ├── toronto-fire.md
│   ├── toronto-police.md
│   ├── toronto-police-architecture.md
│   ├── ttc-transit.md
│   └── go-transit.md
├── plans/
│   └── frontend-typed-alert-domain-plan.md
├── archive/
│   ├── query-refinement-testing.md
│   └── unified-alerts-design.md
└── CHANGELOG.md
```

## Current System Scope

The unified feed currently aggregates four source types:

- `fire` (Toronto Fire CAD)
- `police` (Toronto Police ArcGIS)
- `transit` (TTC composite feed: live API + SXA + static page)
- `go_transit` (Metrolinx GO Transit service updates)

## Recommended Reading Order

1. **[backend/unified-alerts-system.md](backend/unified-alerts-system.md)**
2. **[architecture/provider-adapter-pattern.md](architecture/provider-adapter-pattern.md)**
3. **[backend/enums.md](backend/enums.md)**
4. **[backend/dtos.md](backend/dtos.md)**
5. **[frontend/types.md](frontend/types.md)**
6. **[frontend/alert-service.md](frontend/alert-service.md)**

## Source Integration Docs

- **[sources/toronto-fire.md](sources/toronto-fire.md)**
- **[sources/toronto-police.md](sources/toronto-police.md)**
- **[sources/ttc-transit.md](sources/ttc-transit.md)**
- **[sources/go-transit.md](sources/go-transit.md)**

## Implementation Status

| Feature | Status | Primary Documentation |
|---|---|---|
| Toronto Fire Integration | Implemented | [sources/toronto-fire.md](sources/toronto-fire.md) |
| Toronto Police Integration | Implemented | [sources/toronto-police.md](sources/toronto-police.md) |
| TTC Transit Integration | Implemented | [sources/ttc-transit.md](sources/ttc-transit.md) |
| GO Transit Integration | Implemented | [sources/go-transit.md](sources/go-transit.md) |
| Unified Alerts Query | Implemented | [backend/unified-alerts-system.md](backend/unified-alerts-system.md) |
| Production Scheduler | Implemented | [backend/production-scheduler.md](backend/production-scheduler.md) |
| Dynamic Zones | Planned | [architecture/dynamic-zones.md](architecture/dynamic-zones.md) |
