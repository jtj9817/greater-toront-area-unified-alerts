# Track Specification: TTC Transit Integration

## Overview
Implement a real-time integration with TTC (Toronto Transit Commission) service alerts. This track will provide the "Transit" portion of the unified GTA Alerts dashboard by aggregating data from three distinct TTC sources.

## Goals
- Fetch real-time service alerts from the `alerts.ttc.ca` JSON API.
- Scrape supplemental alerts and construction notices from Sitecore SXA search endpoints.
- Scrape static streetcar service change notices from the TTC website.
- Normalize and store these alerts in a new `transit_alerts` table.
- Integrate the alerts into the unified dashboard through the existing provider pattern.

## Technical Details

### Data Sources
1. **JSON API**: `https://alerts.ttc.ca/api/alerts/live-alerts` (Real-time subway, bus, streetcar, and elevator alerts).
2. **SXA Search**: Sitecore-driven AJAX endpoints for service changes, subway service, construction, and accessibility advisories.
3. **Static CMS**: `https://www.ttc.ca/service-advisories/Streetcar-Service-Changes` (Accordion-style static HTML).

### Database Schema (`transit_alerts`)
- `external_id`: Unique ID with source prefix (`api:`, `sxa:`, `static:`).
- `source_feed`: Origin source (`live-api`, `sxa`, `static`).
- `alert_type`, `route_type`, `route`, `title`, `description`, `severity`, `effect`, `cause`.
- `active_period_start`, `active_period_end`.
- `direction`, `stop_start`, `stop_end`, `url`.
- `is_active`: Boolean status.
- `feed_updated_at`: Last sync time.

### Unified Mapping
- Source: `transit`
- Severity Mapping: `severity='Critical'` or significant effects -> High/Medium; else Low.
- Location: Constructed from route and stop names.

## Success Criteria
- [ ] `transit:fetch-alerts` command successfully syncs data from all three sources.
- [ ] Alerts appear in the unified dashboard with correct icons (subway, bus, tram, elevator).
- [ ] Resolved alerts are correctly marked as `is_active = false`.
- [ ] Test coverage for the new service and provider exceeds 90%.
