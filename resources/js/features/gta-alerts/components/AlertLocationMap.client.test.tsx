import { render } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const mapContainerSpy = vi.hoisted(() =>
    vi.fn(({ children }: { children?: ReactNode }) => (
        <div data-testid="leaflet-map-container">{children}</div>
    )),
);

const configureLeafletDefaultIconsSpy = vi.hoisted(() => vi.fn());
const tileLayerSpy = vi.hoisted(() => vi.fn(() => null));
const markerSpy = vi.hoisted(() =>
    vi.fn(({ children }: { children?: ReactNode }) => <>{children}</>),
);
const popupSpy = vi.hoisted(() =>
    vi.fn(({ children }: { children?: ReactNode }) => <>{children}</>),
);

vi.mock('react-leaflet', () => ({
    MapContainer: mapContainerSpy,
    TileLayer: tileLayerSpy,
    Marker: markerSpy,
    Popup: popupSpy,
}));

vi.mock('../lib/leaflet', () => ({
    configureLeafletDefaultIcons: configureLeafletDefaultIconsSpy,
    OPEN_STREET_MAP_ATTRIBUTION:
        '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
    OPEN_STREET_MAP_TILE_URL:
        'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
}));

vi.mock('@/hooks/use-mobile', () => ({
    useIsMobile: () => false,
}));

import { AlertLocationMapClient } from './AlertLocationMap.client';

describe('AlertLocationMap.client', () => {
    beforeEach(() => {
        mapContainerSpy.mockClear();
        tileLayerSpy.mockClear();
        markerSpy.mockClear();
        popupSpy.mockClear();
    });

    it('configures Leaflet default icons during module setup', () => {
        expect(configureLeafletDefaultIconsSpy).toHaveBeenCalledTimes(1);
    });

    it('renders a sized map wrapper and full-size map container', () => {
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

        const mapContainerProps = mapContainerSpy.mock.calls[0]?.[0] as {
            style: { height: string; width: string };
        };

        expect(mapContainerProps.style).toEqual({
            height: '100%',
            width: '100%',
        });

        expect(tileLayerSpy).toHaveBeenCalledTimes(1);
        expect(markerSpy).toHaveBeenCalledTimes(1);
        expect(popupSpy).toHaveBeenCalledTimes(1);
    });
});
