# Frontend Types

- Source directory: `resources/js/features/gta-alerts/domain/alerts/`

## UnifiedAlertResource (Backend Transport)

Transport shape is validated at the frontend boundary with Zod:
- Schema: `resources/js/features/gta-alerts/domain/alerts/resource.ts`
- Type: `UnifiedAlertResource`

`UnifiedAlertResource` is not rendered directly in components.

## DomainAlert (Typed Domain Union)

`DomainAlert` is the discriminated union used across GTA Alerts feature logic:
- `kind: 'fire'`
- `kind: 'police'`
- `kind: 'transit'`
- `kind: 'go_transit'`

Source-specific schema modules validate and map each source into its domain type.

## AlertPresentation (UI View Model)

`AlertPresentation` is the derived presentation model used by card/table/details renderers:
- Type: `resources/js/features/gta-alerts/domain/alerts/view/types.ts`
- Mapper: `mapDomainAlertToPresentation(...)` in `resources/js/features/gta-alerts/domain/alerts/view/mapDomainAlertToPresentation.ts`

Presentation-only categories (`hazard`, `medical`) are derived here and are not `DomainAlert.kind` values.

## Boundary Contract

Canonical boundary entrypoint:
- `fromResource(resource): DomainAlert | null`
- File: `resources/js/features/gta-alerts/domain/alerts/fromResource.ts`

Behavior:
- Valid resources map to typed `DomainAlert`.
- Invalid resources are caught, logged (`[DomainAlert] ...`), and discarded (`null`).
- UI rendering must never crash due to malformed backend items.
