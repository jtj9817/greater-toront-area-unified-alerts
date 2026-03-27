import { render } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const mapContainerSpy = vi.hoisted(() =>
    vi.fn(({ children }: { children?: ReactNode }) => (
        <div data-testid="leaflet-map-container">{children}</div>
    )),
);

const configureLeafletDefaultIconsSpy = vi.hoisted(() => vi.fn());

vi.mock('react-leaflet', () => ({
    MapContainer: mapContainerSpy,
}));

vi.mock('../lib/leaflet', () => ({
    configureLeafletDefaultIcons: configureLeafletDefaultIconsSpy,
}));

import { AlertLocationMapClient } from './AlertLocationMap.client';

describe('AlertLocationMap.client', () => {
    beforeEach(() => {
        mapContainerSpy.mockClear();
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
    });
});
