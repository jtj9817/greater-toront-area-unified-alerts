# Frontend Types

- Source file: `resources/js/features/gta-alerts/types.ts`

## AlertItem (UI View-Model)

`AlertItem` is the normalized frontend shape used by feed/detail components.

Notable fields:
- `type`: `'fire' | 'police' | 'transit' | 'go_transit' | 'hazard' | 'medical'`
- `severity`: `'high' | 'medium' | 'low'`
- `metadata`: optional source-specific payload used by detail views

## UnifiedAlertResource (Backend Transport)

This mirrors `UnifiedAlertResource` from Laravel:

```typescript
interface UnifiedAlertResource {
    id: string;
    source: 'fire' | 'police' | 'transit' | 'go_transit';
    external_id: string;
    is_active: boolean;
    timestamp: string;
    title: string;
    location: {
        name: string | null;
        lat: number | null;
        lng: number | null;
    } | null;
    meta: Record<string, unknown>;
}
```

## Mapping Boundary

Components should consume `AlertItem`, not `UnifiedAlertResource` directly.

Mapping lives in `resources/js/features/gta-alerts/services/AlertService.ts`.
