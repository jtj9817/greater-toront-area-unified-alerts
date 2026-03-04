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
│   ├── unified-alerts-qa.md
│   └── unified-alerts-system.md
├── frontend/
│   ├── alert-service.md
│   └── types.md
├── reviews/
│   └── GTA-NOTIF-BEND-REVIEW.md
├── runbooks/
│   ├── forge-go-live-checklist.md
│   ├── queue-troubleshooting.md
│   └── scheduler-troubleshooting.md
├── sources/
│   ├── go-transit.md
│   ├── toronto-fire.md
│   ├── toronto-police.md
│   ├── toronto-police-architecture.md
│   └── ttc-transit.md
├── plans/
│   ├── frontend-typed-alert-domain-plan.md
│   ├── hetzner-forge-deployment-preflight.md
│   ├── notification-system-feature-plan.md
│   ├── production-data-migration.md
│   └── scene-intel-feature-plan.md
├── tickets/
│   ├── FEED-001-server-side-filters-infinite-scroll.md
│   ├── FEED-001-phase-3-review.md
│   ├── FEED-002-provider-filter-optimization.md
│   ├── FEED-002-real-time-push.md
│   ├── FEED-003-code-review-phase-2.md
│   ├── FEED-003-saved-filter-presets.md
│   ├── FEED-004-sort-direction-toggle.md
│   ├── FEED-005-test-harness-stabilization.md
│   ├── FEED-006-go-transit-html-sanitization.md
│   ├── FEED-007-scheduled-jobs-never-processed.md
│   ├── FEED-008-scheduler-missing-from-dev-script.md
│   ├── FEED-009-sql-export-import-pipeline.md
│   ├── FEED-010-postgresql-refactoring.md
│   ├── FEED-011-coverage-gap-to-90-threshold.md
│   ├── FEED-012-forge-postgresql-go-live-preflight-checklist.md
│   ├── FEED-013-arcgis-objectid-sequence-reset.md
│   ├── FEED-014-queue-worker-137-and-notification-fanout-idempotency.md
│   └── archive/  (closed tickets)
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
- **[tickets/FEED-004-sort-direction-toggle.md](tickets/FEED-004-sort-direction-toggle.md)** - Sort direction toggle in the feed (depends on FEED-001)
- **[tickets/FEED-014-queue-worker-137-and-notification-fanout-idempotency.md](tickets/FEED-014-queue-worker-137-and-notification-fanout-idempotency.md)** - Stabilize queue worker exit 137 and harden notification fan-out idempotency (critical)

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
| Unified Alerts Query | Implemented | [backend/unified-alerts-system.md](backend/unified-alerts-system.md) |
| Server-Side Feed Filters + Infinite Scroll (FEED-001) | Implemented | [backend/unified-alerts-system.md](backend/unified-alerts-system.md) |
| Production Scheduler | Implemented | [backend/production-scheduler.md](backend/production-scheduler.md) |
| In-App Notifications | Implemented | [backend/notification-system.md](backend/notification-system.md) |
| Scene Intel (Fire) | Implemented | [backend/scene-intel.md](backend/scene-intel.md) |
| Real-Time Feed Push | Planned | [tickets/FEED-002-real-time-push.md](tickets/FEED-002-real-time-push.md) |
| Dynamic Zones | Planned | [architecture/dynamic-zones.md](architecture/dynamic-zones.md) |

## Runbooks

- **[runbooks/forge-go-live-checklist.md](runbooks/forge-go-live-checklist.md)** - Production go-live sequence for Laravel Forge on Hetzner (PostgreSQL)
- **[runbooks/scheduler-troubleshooting.md](runbooks/scheduler-troubleshooting.md)** - Scheduler failures, overlap locks, empty feed protection
- **[runbooks/queue-troubleshooting.md](runbooks/queue-troubleshooting.md)** - Queue backlog and failed job recovery
