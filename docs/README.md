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
├── deployment/
│   └── production-seeding.md
├── backend/
│   ├── architecture-walkthrough.md
│   ├── database-schema.md
│   ├── dtos.md
│   ├── enums.md
│   ├── fire-incidents.md
│   ├── maintenance.md
│   ├── mappers.md
│   ├── notification-system.md
│   ├── production-scheduler.md
│   ├── scene-intel.md
│   ├── security-headers.md
│   ├── unified-alerts-qa.md
│   ├── unified-alerts-system.md
│   └── weather.md
├── frontend/
│   ├── alert-location-map.md
│   ├── alert-service.md
│   └── types.md
├── reviews/
│   └── GTA-NOTIF-BEND-REVIEW.md
├── runbooks/
│   ├── design-revamp-phase-4-verification.md
│   ├── forge-go-live-checklist.md
│   ├── queue-troubleshooting.md
│   └── scheduler-troubleshooting.md
├── sources/
│   ├── go-transit.md
│   ├── miway.md
│   ├── toronto-fire.md
│   ├── toronto-police.md
│   ├── toronto-police-architecture.md
│   ├── ttc-transit.md
│   └── yrt.md
├── plans/
│   ├── frontend-typed-alert-domain-plan.md
│   ├── hetzner-forge-deployment-preflight.md
│   ├── notification-system-feature-plan.md
│   ├── production-data-migration.md
│   └── scene-intel-feature-plan.md
├── tickets/
│   ├── FEED-002-real-time-push.md            (Open)
│   ├── FEED-003-saved-filter-presets.md      (Open)
│   ├── FEED-015-footer-weather-stats-hardcoded-placeholder.md  (Open)
│   ├── FEED-001 through FEED-021             (all others Closed/Done — not archived to subdirectory)
│   └── archive/  (older closed tickets moved here)
├── archive/
│   ├── query-refinement-testing.md
│   └── unified-alerts-design.md
└── CHANGELOG.md
```

## Current System Scope

The unified feed currently aggregates seven source types:

- `fire` (Toronto Fire CAD)
- `police` (Toronto Police ArcGIS)
- `transit` (TTC composite feed: live API + SXA + static page)
- `go_transit` (Metrolinx GO Transit service updates)
- `miway` (MiWay GTFS-RT service alerts)
- `yrt` (YRT service advisories)
- `drt` (Durham Region Transit Service Alerts and Detours)

## Recommended Reading Order

1. **[backend/unified-alerts-system.md](backend/unified-alerts-system.md)**
2. **[architecture/provider-adapter-pattern.md](architecture/provider-adapter-pattern.md)**
3. **[backend/database-schema.md](backend/database-schema.md)**
4. **[backend/enums.md](backend/enums.md)**
5. **[backend/dtos.md](backend/dtos.md)**
6. **[frontend/types.md](frontend/types.md)**
7. **[frontend/alert-service.md](frontend/alert-service.md)**
8. **[backend/notification-system.md](backend/notification-system.md)**
9. **[backend/scene-intel.md](backend/scene-intel.md)**
10. **[backend/maintenance.md](backend/maintenance.md)**

## Source Integration Docs

- **[sources/toronto-fire.md](sources/toronto-fire.md)**
- **[sources/toronto-police.md](sources/toronto-police.md)**
- **[sources/ttc-transit.md](sources/ttc-transit.md)**
- **[sources/go-transit.md](sources/go-transit.md)**
- **[sources/miway.md](sources/miway.md)**
- **[sources/yrt.md](sources/yrt.md)**
- **[sources/drt.md](sources/drt.md)**

## Deployment Docs

- **[deployment/production-seeding.md](deployment/production-seeding.md)** - Production data migration and Forge seeding runbook
  - Includes Phase 4 final quality gate command:
    `php tests/manual/verify_production_data_migration_phase_4_final_quality_gate.php`

## Plan Docs

- **[plans/production-data-migration.md](plans/production-data-migration.md)** - Implementation and completion record for production data migration/export tooling.
- **[plans/notification-system-feature-plan.md](plans/notification-system-feature-plan.md)** - Historical implementation plan for the in-app notification system (completed).
- **[plans/scene-intel-feature-plan.md](plans/scene-intel-feature-plan.md)** - Historical implementation plan for the Scene Intel feature (completed).
- **[plans/frontend-typed-alert-domain-plan.md](plans/frontend-typed-alert-domain-plan.md)** - Historical implementation plan for the typed frontend alert domain (completed).

## Open Tickets

Active work items and feature proposals:

- **[tickets/FEED-002-real-time-push.md](tickets/FEED-002-real-time-push.md)** - Real-time push updates for the alert feed (depends on FEED-001)
- **[tickets/FEED-003-saved-filter-presets.md](tickets/FEED-003-saved-filter-presets.md)** - Saved filter presets for quick-access filter combinations (depends on FEED-001)

## Resolved Tickets

- **[tickets/FEED-015-footer-weather-stats-hardcoded-placeholder.md](tickets/FEED-015-footer-weather-stats-hardcoded-placeholder.md)** - Replace hardcoded footer weather placeholder with real data source (resolved by Weather feature implementation)

## Historical Docs Notes

- Some files in `docs/plans/`, `docs/tickets/`, and `docs/reviews/` preserve historical terminology used during implementation (for example `geofences` or `subscribed_routes`).
- For the current backend contract, use:
  - `docs/backend/notification-system.md` (notifications architecture + API)
  - `docs/backend/maintenance.md` (retention/pruning policy)

## Implementation Status

| Feature | Status | Primary Documentation |
|---|---|---|
| Toronto Fire Integration | Implemented | [sources/toronto-fire.md](sources/toronto-fire.md) |
| Toronto Police Integration | Implemented | [sources/toronto-police.md](sources/toronto-police.md) |
| TTC Transit Integration | Implemented | [sources/ttc-transit.md](sources/ttc-transit.md) |
| GO Transit Integration | Implemented | [sources/go-transit.md](sources/go-transit.md) |
| MiWay Integration | Implemented | [sources/miway.md](sources/miway.md) |
| YRT Integration | Implemented | [sources/yrt.md](sources/yrt.md) |
| DRT Integration | Implemented | [sources/drt.md](sources/drt.md) |
| Unified Alerts Query | Implemented | [backend/unified-alerts-system.md](backend/unified-alerts-system.md) |
| Server-Side Feed Filters + Infinite Scroll (FEED-001) | Implemented | [backend/unified-alerts-system.md](backend/unified-alerts-system.md) |
| Sort Direction Toggle (FEED-004) | Implemented | [tickets/FEED-004-sort-direction-toggle.md](tickets/FEED-004-sort-direction-toggle.md) |
| Production Scheduler | Implemented | [backend/production-scheduler.md](backend/production-scheduler.md) |
| Scheduler Fetch Dedupe (`ScheduledFetchJobDispatcher`) | Implemented | [backend/production-scheduler.md](backend/production-scheduler.md) |
| Content Security Policy (hot-mode aware) | Implemented | [backend/security-headers.md](backend/security-headers.md) |
| In-App Notifications | Implemented | [backend/notification-system.md](backend/notification-system.md) |
| Scene Intel (Fire) | Implemented | [backend/scene-intel.md](backend/scene-intel.md) |
| Weather Feature | Implemented | [backend/weather.md](backend/weather.md) |
| Alert Location Map (Leaflet + OSM) | Implemented | [frontend/alert-location-map.md](frontend/alert-location-map.md) |
| Real-Time Feed Push | Planned | [tickets/FEED-002-real-time-push.md](tickets/FEED-002-real-time-push.md) |
| Dynamic Zones | Planned | [architecture/dynamic-zones.md](architecture/dynamic-zones.md) |

## Runbooks

- **[runbooks/forge-go-live-checklist.md](runbooks/forge-go-live-checklist.md)** - Production go-live sequence for Laravel Forge on Hetzner (PostgreSQL)
- **[runbooks/design-revamp-phase-4-verification.md](runbooks/design-revamp-phase-4-verification.md)** - Local verification workflow for UI Design Revamp Phase 4/5 gates and troubleshooting
- **[runbooks/scheduler-troubleshooting.md](runbooks/scheduler-troubleshooting.md)** - Scheduler failures, overlap locks, empty feed protection
- **[runbooks/queue-troubleshooting.md](runbooks/queue-troubleshooting.md)** - Queue backlog and failed job recovery
