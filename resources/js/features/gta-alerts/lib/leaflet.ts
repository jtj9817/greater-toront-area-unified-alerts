import L from 'leaflet';

const markerIconUrl = new URL(
    'leaflet/dist/images/marker-icon.png',
    import.meta.url,
).href;

const markerIconRetinaUrl = new URL(
    'leaflet/dist/images/marker-icon-2x.png',
    import.meta.url,
).href;

const markerShadowUrl = new URL(
    'leaflet/dist/images/marker-shadow.png',
    import.meta.url,
).href;

export function configureLeafletDefaultIcons(): void {
    L.Icon.Default.mergeOptions({
        iconUrl: markerIconUrl,
        iconRetinaUrl: markerIconRetinaUrl,
        shadowUrl: markerShadowUrl,
        iconSize: [25, 41],
        iconAnchor: [12, 41],
        popupAnchor: [1, -34],
        tooltipAnchor: [16, -28],
        shadowSize: [41, 41],
    });
}
