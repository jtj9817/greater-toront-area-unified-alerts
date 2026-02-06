# Toronto Police Integration Architecture Notes

This file records the current architecture and prior design direction.

## Current Production Approach

The project uses direct ArcGIS REST ingestion in Laravel (no browser sidecar service):

- `TorontoPoliceFeedService` calls the FeatureServer query endpoint directly.
- Pagination is handled through `resultOffset` / `resultRecordCount`.
- Data is normalized and persisted by `FetchPoliceCallsCommand`.
- Unified exposure is handled by `PoliceAlertSelectProvider`.

This approach has been sufficient for reliability and avoids the operational cost of a Playwright microservice.

## Historical Alternative (Not Active)

Earlier design work evaluated a Cloud Run Playwright sidecar to interact with the TPS Experience Builder UI and intercept underlying network calls. That architecture is not currently used by this repository.

If TPS changes make direct ArcGIS querying unreliable, the sidecar approach can be reconsidered.
