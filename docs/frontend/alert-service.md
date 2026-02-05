# AlertService (Frontend)

**File:** `resources/js/features/gta-alerts/services/AlertService.ts`

The `AlertService` is the central service for mapping backend `UnifiedAlertResource` data to frontend `AlertItem` view-models, and handling client-side search, filtering, and sorting.

## Overview

```typescript
class AlertService {
    // Transport to View-Model mapping
    static mapUnifiedAlertToAlertItem(unified: UnifiedAlertResource): AlertItem

    // Search and filter
    static search(alerts: AlertItem[], query: string): AlertItem[]
    static filterByType(alerts: AlertItem[], type: string): AlertItem[]
    static filterByStatus(alerts: AlertItem[], status: string): AlertItem[]

    // Helpers
    static formatTimeAgo(timestamp: string): string
    static calculateSeverity(unified: UnifiedAlertResource): Severity
    static getIconForSource(source: string): string
}
```

## Transport vs View-Model

The backend sends `UnifiedAlertResource[]` (transport shape), which the service maps to `AlertItem` (view-model):

### UnifiedAlertResource (Transport)

```typescript
interface UnifiedAlertResource {
    id: string;                  // "fire:12345"
    source: 'fire' | 'police' | 'transit';
    external_id: string;         // "12345"
    is_active: boolean;
    timestamp: string;           // ISO 8601
    title: string;               // Raw event type
    location: {
        name: string | null;
        lat: number | null;
        lng: number | null;
    } | null;
    meta: Record<string, unknown>;  // Source-specific data
}
```

### AlertItem (View-Model)

```typescript
interface AlertItem {
    id: string;
    title: string;               // Formatted title
    location: string;            // Formatted location string
    timeAgo: string;             // "5 minutes ago"
    timestamp: string;           // Original ISO 8601
    description: string;         // Human-readable description
    type: AlertType;             // 'fire' | 'police' | 'transit' | 'hazard' | 'medical'
    severity: Severity;          // 'high' | 'medium' | 'low'
    iconName: string;            // Lucide icon name
    accentColor: string;         // Tailwind class
    iconColor: string;           // Tailwind class
    metadata?: {
        eventNum: string;
        alarmLevel: number;
        unitsDispatched: string | null;
        beat: string | null;
        source?: string;
        estimatedDelay?: string;  // Transit
        shuttleInfo?: string;     // Transit
    };
}
```

## Primary Method: mapUnifiedAlertToAlertItem

```typescript
static mapUnifiedAlertToAlertItem(unified: UnifiedAlertResource): AlertItem {
    return {
        id: unified.id,
        title: unified.title,
        location: this.formatLocation(unified.location),
        timeAgo: this.formatTimeAgo(unified.timestamp),
        timestamp: unified.timestamp,
        description: this.buildDescription(unified),
        type: this.mapType(unified.source),
        severity: this.calculateSeverity(unified),
        iconName: this.getIconForSource(unified.source),
        accentColor: this.getAccentColor(unified.source),
        iconColor: this.getIconColor(unified.source),
        metadata: this.extractMetadata(unified),
    };
}
```

## Helper Methods

### formatLocation

```typescript
static formatLocation(location: UnifiedAlertResource['location']): string
```

Extracts location name from the location object or returns "Unknown location".

**Examples:**
- `{ name: "Yonge St / Dundas St", lat: null, lng: null }` → `"Yonge St / Dundas St"`
- `null` → `"Unknown location"`

### formatTimeAgo

```typescript
static formatTimeAgo(timestamp: string): string
```

Formats an ISO 8601 timestamp as a relative time string.

**Examples:**
- `"2026-02-05T12:00:00Z"` (30 seconds ago) → `"30 seconds ago"`
- `"2026-02-05T11:55:00Z"` (5 minutes ago) → `"5 minutes ago"`

### buildDescription

```typescript
static buildDescription(unified: UnifiedAlertResource): string
```

Builds a human-readable description from the alert data.

**Fire incidents:**
- Combines `meta.alarmLevel`, `meta.unitsDispatched`, `meta.beat`

**Police calls:**
- Combines `meta.callTypeCode`, `meta.division`

**Transit alerts:**
- Combines `meta.routeType`, `meta.route`, `meta.effect`, `meta.description`

### calculateSeverity

```typescript
static calculateSeverity(unified: UnifiedAlertResource): Severity
```

Determines alert severity based on source and metadata.

**Fire incidents:**
- `alarmLevel >= 3` → `"high"`
- `alarmLevel >= 2` → `"medium"`
- Else → `"low"`

**Police calls:**
- Based on `call_type` keywords (emergency, assault, etc.)

**Transit alerts:**
- `severity === "Critical"` → `"high"`
- `effect` in `["SIGNIFICANT_DELAYS", "REDUCED_SERVICE"]` → `"medium"`
- Else → `"low"`

### mapType

```typescript
static mapType(source: AlertSource): AlertType
```

Maps alert source to display type.

```typescript
const map: Record<AlertSource, AlertType> = {
    fire: 'fire',
    police: 'police',
    transit: 'transit',
};
```

### getIconForSource

```typescript
static getIconForSource(source: AlertSource): string
```

Returns Lucide icon name for the source.

```typescript
const icons: Record<AlertSource, string> = {
    fire: 'flame_concise',
    police: 'shield_concise',
    transit: 'train_track',
};
```

### getAccentColor

```typescript
static getAccentColor(source: AlertSource): string
```

Returns Tailwind border/background color class.

```typescript
const colors: Record<AlertSource, string> = {
    fire: 'bg-coral',
    police: 'bg-blue-500',
    transit: 'bg-purple-500',
};
```

### getIconColor

```typescript
static getIconColor(source: AlertSource): string
```

Returns Tailwind text color class for the icon.

```typescript
const colors: Record<AlertSource, string> = {
    fire: 'text-coral',
    police: 'text-blue-400',
    transit: 'text-purple-400',
};
```

### extractMetadata

```typescript
static extractMetadata(unified: UnifiedAlertResource): Metadata | undefined
```

Extracts source-specific metadata for display in alert details.

**Fire incidents:**
```typescript
{
    eventNum: meta.event_num,
    alarmLevel: meta.alarm_level,
    unitsDispatched: meta.units_dispatched,
    beat: meta.beat,
    source: "Toronto Fire Services",
}
```

**Police calls:**
```typescript
{
    eventNum: meta.object_id,
    alarmLevel: 0,
    unitsDispatched: null,
    beat: null,
    source: "Toronto Police Service",
}
```

## Search and Filter

### search

```typescript
static search(alerts: AlertItem[], query: string): AlertItem[]
```

Performs case-insensitive search across multiple fields.

**Searches:**
- Title
- Location
- Description
- Metadata

**Example:**
```typescript
const results = AlertService.search(allAlerts, "fire yonge");
// Returns alerts containing "fire" AND "yonge" in any searchable field
```

### filterByType

```typescript
static filterByType(alerts: AlertItem[], type: string): AlertItem[]
```

Filters alerts by type.

```typescript
const fireAlerts = AlertService.filterByType(allAlerts, 'fire');
```

### filterByStatus

```typescript
static filterByStatus(alerts: AlertItem[], status: string): AlertItem[]
```

Filters alerts by active status (client-side only).

**Note:** Status filtering is primarily done server-side via the `status` query param. This method is for additional client-side filtering if needed.

## Usage in Components

### FeedView

```typescript
const FeedView: React.FC<FeedViewProps> = ({ allAlerts, searchQuery }) => {
    const filteredAlerts = useMemo(() => {
        let filtered = allAlerts;

        if (searchQuery) {
            filtered = AlertService.search(filtered, searchQuery);
        }

        return filtered;
    }, [allAlerts, searchQuery]);

    return (
        // Render filteredAlerts
    );
};
```

### App.tsx

```typescript
const App: React.FC<AppProps> = ({ alerts }) => {
    const allAlerts = useMemo(() => {
        return alerts.data.map((a) =>
            AlertService.mapUnifiedAlertToAlertItem(a),
        );
    }, [alerts.data]);

    // Pass to child views
    return <FeedView allAlerts={allAlerts} />;
};
```

## Testing

**File:** `resources/js/features/gta-alerts/services/AlertService.test.ts`

Tests cover:
- `mapUnifiedAlertToAlertItem` mapping accuracy
- Search query parsing and filtering
- Severity calculation for each source
- Location formatting
- Time ago formatting

Run tests:
```bash
pnpm test -- AlertService
```

## Related Documentation

- **[../backend/unified-alerts-system.md](../backend/unified-alerts-system.md)** - Backend unified alerts architecture
- **[types.md](types.md)** - TypeScript type definitions
