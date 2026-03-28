import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const mapContainerSpy = vi.hoisted(() =>
    vi.fn(({ children }: { children?: ReactNode }) => (
        <div data-testid="leaflet-map-container">{children}</div>
    )),
);

const tileLayerSpy = vi.hoisted(() =>
    vi.fn(() => <div data-testid="leaflet-tile-layer" />),
);

const markerSpy = vi.hoisted(() =>
    vi.fn(({ children }: { children?: ReactNode }) => (
        <div data-testid="leaflet-marker">{children}</div>
    )),
);

const popupSpy = vi.hoisted(() =>
    vi.fn(({ children }: { children?: ReactNode }) => (
        <div data-testid="leaflet-popup">{children}</div>
    )),
);

vi.mock('react-leaflet', () => ({
    MapContainer: mapContainerSpy,
    TileLayer: tileLayerSpy,
    Marker: markerSpy,
    Popup: popupSpy,
}));

vi.mock('../lib/leaflet', () => ({
    configureLeafletDefaultIcons: vi.fn(),
    OPEN_STREET_MAP_ATTRIBUTION:
        '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
    OPEN_STREET_MAP_TILE_URL: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
}));

vi.mock('@/hooks/use-mobile', () => ({
    useIsMobile: () => false,
}));

import { AlertLocationMapClient } from './AlertLocationMap.client';
import { AlertLocationMap } from './AlertLocationMap';
import { AlertLocationUnavailable } from './AlertLocationUnavailable';

beforeEach(() => {
    mapContainerSpy.mockClear();
    tileLayerSpy.mockClear();
    markerSpy.mockClear();
    popupSpy.mockClear();
});

describe('AlertLocationMap', () => {
    it('loads the lazy client map component from a named export module', async () => {
        const { container } = render(
            <AlertLocationMap
                idBase="test-alert"
                lat={43.6532}
                lng={-79.3832}
                locationName="Downtown Toronto"
            />,
        );

        await screen.findByTestId('leaflet-map-container');

        expect(container.querySelector('#test-alert-map-wrapper')).not.toBeNull();
    });
});

describe('AlertLocationMapClient', () => {
    it('renders leaflet primitives with OSM tile attribution and stable IDs', () => {
        const { container } = render(
            <AlertLocationMapClient
                idBase="alert-123"
                lat={43.7001}
                lng={-79.4163}
                locationName="Annex"
            />,
        );

        const mapWrapper = container.querySelector('#alert-123-map-wrapper');
        expect(mapWrapper).toHaveClass('aspect-video');

        expect(mapContainerSpy).toHaveBeenCalledTimes(1);
        expect(tileLayerSpy).toHaveBeenCalledTimes(1);
        expect(markerSpy).toHaveBeenCalledTimes(1);
        expect(popupSpy).toHaveBeenCalledTimes(1);

        const tileLayerProps = tileLayerSpy.mock.calls[0]?.[0] as {
            attribution: string;
            url: string;
        };
        expect(tileLayerProps.url).toBe(
            'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
        );
        expect(tileLayerProps.attribution).toContain('OpenStreetMap');

        const markerProps = markerSpy.mock.calls[0]?.[0] as {
            position: [number, number];
        };
        expect(markerProps.position).toEqual([43.7001, -79.4163]);

        expect(screen.getByTestId('leaflet-popup')).toHaveTextContent('Annex');
    });
});

describe('AlertLocationUnavailable', () => {
    it('shows the location label and truthful unavailable copy', () => {
        render(
            <AlertLocationUnavailable
                idBase="alert-404"
                locationName="Unknown location"
            />,
        );

        const unavailableCard = screen
            .getByText('Map unavailable')
            .closest('div[id="alert-404-location-unavailable"]');
        expect(unavailableCard).not.toBeNull();
        expect(
            screen.getByText('Exact coordinates are not available for this alert.'),
        ).toBeInTheDocument();
        expect(screen.getByText('Unknown location')).toBeInTheDocument();
    });
});
