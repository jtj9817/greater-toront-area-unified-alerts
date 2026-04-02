# Alert Location Map

Renders a Leaflet + OpenStreetMap map inside the alert details view when valid coordinates are available. When coordinates are absent or ineligible, an explicit unavailable state is shown. The feature is shared across all alert source types. v1 is frontend-only with no geocoding or coordinate enrichment.

## Architecture

The component is split into two files to remain safe under Inertia SSR.

**`AlertLocationMap.tsx`** is the SSR-safe lazy wrapper. It uses `React.lazy()` to import the client module and wraps it in `Suspense` with a loading skeleton div. It never imports Leaflet directly. This is the component consumed by `AlertDetailsView`.

**`AlertLocationMap.client.tsx`** is the browser-only module. All Leaflet and react-leaflet imports live here, including `leaflet/dist/leaflet.css`. It calls `configureLeafletDefaultIcons()` at module scope and uses `useIsMobile()` to control interaction props. It renders `MapContainer`, `TileLayer`, `Marker`, and `Popup`.

The split is required because Leaflet accesses browser globals (`window`, `document`, `navigator`) at import time. Importing it in an SSR context causes a crash. `React.lazy()` defers the import to the client bundle so the server never evaluates the Leaflet module.

**`AlertLocationUnavailable.tsx`** is a companion empty-state card shown when `locationCoords` is `null`. It is a plain synchronous component with no map dependency.

Both `AlertLocationMap` and `AlertLocationUnavailable` share the same props signature where applicable and are rendered from the same conditional block in `AlertDetailsView`.

## Coordinate Eligibility Rules

`locationCoords` is computed at the presentation boundary inside `mapDomainAlertToPresentation.ts` and exposed as `locationCoords: AlertPresentationCoordinates | null` on `AlertPresentation`. UI components never inspect `alert.location.lat` or `alert.location.lng` directly.

A coordinate pair is eligible only when all of the following are true:

- Both `lat` and `lng` are present and of type `number`
- Both pass `Number.isFinite()`
- `lat` is in the closed range [40, 50] — the GTA/Ontario bounding box
- `lng` is in the closed range [-90, -70] — the GTA/Ontario bounding box

The following are rejected:

- `null` or missing coordinates
- Partial pairs (one coordinate present, one absent)
- Non-finite values (`NaN`, `Infinity`)
- The `0, 0` origin (Null Island) — excluded by the range checks
- Coordinates outside the GTA/Ontario region

When all checks pass, the result is `AlertPresentationCoordinates { lat: number; lng: number }`. Otherwise `locationCoords` is `null`.

The type is defined in `resources/js/features/gta-alerts/domain/alerts/view/types.ts`.

## Tile Provider Seam

`resources/js/features/gta-alerts/lib/leaflet.ts` is the single point of truth for tile configuration. It exports:

- `OPEN_STREET_MAP_TILE_URL` — `'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png'`
- `OPEN_STREET_MAP_ATTRIBUTION` — the required OSM attribution string

Only `AlertLocationMap.client.tsx` imports these constants. To swap tile providers (for example, switching to a self-hosted proxy or a paid provider), only `lib/leaflet.ts` needs to change. No other file references tile URLs.

## CSP Note

No Content Security Policy expansion was required for v1.

OSM raster tiles are fetched as cross-origin `<img>` requests by the Leaflet map. The existing `img-src 'self' data: https:` directive already permits these. The `connect-src` directive was not changed because raster tile loading is an image fetch, not an XHR or `fetch()` call.

Leaflet CSS is bundled through Vite as a regular stylesheet import in `AlertLocationMap.client.tsx`. The nonce-based CSP flow that applies to inline styles injected by Vite applies normally here.

## Mobile Scrolling

`AlertLocationMap.client.tsx` imports `useIsMobile()` from `@/hooks/use-mobile` and disables all pointer and gesture interaction when on a mobile viewport. The following props are passed to `MapContainer`:

```tsx
dragging={!isMobile}
touchZoom={!isMobile}
doubleClickZoom={!isMobile}
boxZoom={!isMobile}
keyboard={!isMobile}
```

This prevents the map from trapping vertical scroll gestures on touch devices. The map remains visible and readable on mobile; only interaction is suppressed.

## Marker Icon Setup

Leaflet's default marker images are referenced via relative paths that Vite does not resolve correctly from inside `node_modules`. `lib/leaflet.ts` exports `configureLeafletDefaultIcons()`, which calls `L.Icon.Default.mergeOptions(...)` with explicit `import.meta.url`-based URLs for the three marker assets:

- `marker-icon.png`
- `marker-icon-2x.png`
- `marker-shadow.png`

`AlertLocationMap.client.tsx` calls `configureLeafletDefaultIcons()` at module scope, before any map component renders. This ensures markers display correctly in both development and production builds.

## Integration in AlertDetailsView

`resources/js/features/gta-alerts/components/AlertDetailsView.tsx` renders a "Location Map" section in the common details layout immediately after the "Official Briefing" section, for all alert source types.

The conditional rendering logic:

```tsx
{alert.locationCoords ? (
  <AlertLocationMap
    idBase={idBase}
    lat={alert.locationCoords.lat}
    lng={alert.locationCoords.lng}
    locationName={alert.location}
  />
) : (
  <AlertLocationUnavailable
    idBase={idBase}
    locationName={alert.location}
  />
)}
```

`idBase` is derived from the alert ID by `AlertDetailsView` and threaded into both components. The following element IDs are produced:

| Element | ID |
|---|---|
| Location section wrapper | `${idBase}-location-section` |
| Map outer wrapper | `${idBase}-map-wrapper` |
| Leaflet map container | `${idBase}-map` |
| Unavailable state | `${idBase}-location-unavailable` |

## Source Coverage

| Source | Coordinates | Notes |
|---|---|---|
| Toronto Police | Yes | Real lat/lng from ArcGIS FeatureServer |
| Toronto Fire | No (v1) | Intersection text only; geocoding deferred |
| TTC Transit | No (v1) | Text location only; geocoding deferred |
| GO Transit | No (v1) | Corridor/route text only; geocoding deferred |
| MiWay | No (v1) | Route/stop text only; geocoding deferred |
| YRT | No (v1) | Route text only; geocoding deferred |

## v1 Scope Limits

The following are explicitly out of scope for v1:

- Geocoding or reverse geocoding of text addresses
- Provider-side coordinate enrichment (Fire, TTC, GO Transit)
- Marker clustering for multiple alerts
- Routing or directions
- Paid or self-hosted tile providers
- Tile proxy to avoid direct client-to-OSM requests
