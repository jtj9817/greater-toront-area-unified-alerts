# Frontend Types

This document describes the TypeScript type definitions used in the GTA Alerts frontend.

## Location

**File:** `resources/js/features/gta-alerts/types.ts`

## Core Types

### AlertItem (View-Model)

The primary UI view-model used by all alert components. This is distinct from the backend transport shape (`UnifiedAlertResource`) to allow UI-specific concerns like formatted strings, icon names, and color classes.

```typescript
interface AlertItem {
    // Identification
    id: string;

    // Display content
    title: string;
    location: string;
    timeAgo: string;
    timestamp: string;          // Raw ISO 8601 from backend
    description: string;

    // Classification
    type: AlertType;
    severity: Severity;

    // Presentation
    iconName: string;           // Lucide icon name
    accentColor: string;        // Tailwind bg-* class (border)
    iconColor: string;          // Tailwind text-* class

    // Optional metadata
    metadata?: AlertMetadata;
}
```

### AlertType

```typescript
type AlertType = 'fire' | 'police' | 'transit' | 'hazard' | 'medical';
```

Maps from backend `source` field but allows for future expansion (hazard, medical).

### Severity

```typescript
type Severity = 'high' | 'medium' | 'low';
```

Derived from source-specific data (alarm level, call type, etc.) via `AlertService.calculateSeverity()`.

### AlertMetadata

```typescript
interface AlertMetadata {
    eventNum: string;
    alarmLevel: number;
    unitsDispatched: string | null;
    beat: string | null;
    source?: string;                // "Toronto Fire Services", etc.
    estimatedDelay?: string;        // Transit-specific
    shuttleInfo?: string;           // Transit-specific
}
```

Source-specific metadata displayed in alert details view.

## Transport Types

### UnifiedAlertResource

The transport shape sent from backend via Inertia props. Do not use directly in components; use `AlertItem` instead.

```typescript
interface UnifiedAlertResource {
    // Composite ID: "fire:12345"
    id: string;

    // Source classification
    source: AlertSource;

    // Original source ID
    external_id: string;

    // Active status
    is_active: boolean;

    // Timestamp (ISO 8601)
    timestamp: string;

    // Primary label
    title: string;

    // Location data
    location: {
        name: string | null;
        lat: number | null;
        lng: number | null;
    } | null;

    // Source-specific metadata
    meta: Record<string, unknown>;
}
```

### AlertSource

```typescript
type AlertSource = 'fire' | 'police' | 'transit';
```

Corresponds to PHP `AlertSource` enum values.

### ZoneStatsResource

```typescript
interface ZoneStatsResource {
    id: string;                    // "downtown-core"
    name: string;                  // "Downtown Core"
    active_count: number;
    cleared_count: number;
    total_count: number;
    activity_status: ActivityStatus;
}
```

### ActivityStatus

```typescript
type ActivityStatus =
    | 'high_activity'
    | 'moderate'
    | 'low_activity'
    | 'normal'
    | 'monitoring';
```

Derived from total alert count thresholds.

## Legacy Types

### IncidentResource

Legacy type for Toronto Fire incidents. Kept for backward compatibility during migration.

```typescript
interface IncidentResource {
    id: number;
    event_num: string;
    event_type: string;
    prime_street: string;
    cross_streets: string | null;
    dispatch_time: string;
    alarm_level: number;
    beat: string | null;
    units_dispatched: string | null;
    is_active: boolean;
    feed_updated_at: string;
}
```

### AlertSectionData

Used for grouped alert views (not currently used in main feed).

```typescript
interface AlertSectionData {
    id: string;
    title: string;
    iconName: string;
    activeCount: number;
    items: AlertItem[];
}
```

## Inertia Props Types

### GTAAlertsProps

```typescript
interface GTAAlertsProps {
    alerts: {
        data: UnifiedAlertResource[];
        links: Record<string, string | null>;
        meta: Record<string, unknown>;
    };
    filters: {
        status: AlertStatus;
    };
    latest_feed_updated_at: string | null;
}
```

### AlertStatus

```typescript
type AlertStatus = 'all' | 'active' | 'cleared';
```

Corresponds to PHP `AlertStatus` enum values.

## Mapping Functions

### AlertService Methods

```typescript
class AlertService {
    // Transport to View-Model
    static mapUnifiedAlertToAlertItem(
        unified: UnifiedAlertResource
    ): AlertItem;

    // Legacy support
    static mapIncidentToAlertItem(
        incident: IncidentResource
    ): AlertItem;
}
```

The mapping function transforms backend data to the view-model:

1. **ID:** Direct copy
2. **Title:** Direct copy (may be enhanced in future)
3. **Location:** Format from `location.name` or "Unknown location"
4. **TimeAgo:** Format from `timestamp` ("5 minutes ago")
5. **Timestamp:** Direct copy (for sorting)
6. **Description:** Build from `meta` fields
7. **Type:** Map from `source`
8. **Severity:** Calculate from source-specific rules
9. **IconName:** Map from `source`
10. **AccentColor:** Map from `source`
11. **IconColor:** Map from `source`
12. **Metadata:** Extract from `meta`

## Type Guards

### isAlertItem

```typescript
function isAlertItem(item: unknown): item is AlertItem {
    return (
        typeof item === 'object' &&
        item !== null &&
        'id' in item &&
        'title' in item &&
        // ... other checks
    );
}
```

## Related Documentation

- **[alert-service.md](alert-service.md)** - AlertService mapping logic
- **[../backend/unified-alerts-system.md](../backend/unified-alerts-system.md)** - Backend unified alerts architecture
