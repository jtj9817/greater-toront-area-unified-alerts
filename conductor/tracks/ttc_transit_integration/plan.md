# Implementation Plan: TTC Transit Integration

## Phase 1: Database & Model Setup
- [~] Create `transit_alerts` migration. [ ]
- [ ] Implement `TransitAlert` model and factory. [ ]
- [ ] Add unit tests for `TransitAlert` model. [ ]

## Phase 2: Data Ingestion (Feed Service)
- [ ] Implement `TtcAlertsFeedService` with JSON API support. [ ]
- [ ] Add SXA Search scraping logic to `TtcAlertsFeedService`. [ ]
- [ ] Add Static CMS scraping logic to `TtcAlertsFeedService`. [ ]
- [ ] Implement `FetchTransitAlertsCommand` and `FetchTransitAlertsJob`. [ ]
- [ ] Add feature tests for `TtcAlertsFeedService`. [ ]

## Phase 3: Unified Provider & Integration
- [ ] Implement `TransitAlertSelectProvider`. [ ]
- [ ] Add unit tests for `TransitAlertSelectProvider`. [ ]
- [ ] Update `GtaAlertsController` to include transit data in freshness checks. [ ]
- [ ] Register `transit:fetch-alerts` in `routes/console.php` schedule. [ ]

## Phase 4: Frontend & Seeders
- [ ] Update `AlertService.ts` for transit-specific icons and severity logic. [ ]
- [ ] Add transit alerts to `UnifiedAlertsTestSeeder`. [ ]
- [ ] Verify integration with `UnifiedAlertsQueryTest`. [ ]
- [ ] Final manual verification on dashboard. [ ]
