import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { AlertLocationMap } from './AlertLocationMap';

vi.mock('./AlertLocationMap.client', () => ({
    AlertLocationMapClient: ({
        idBase,
        locationName,
    }: {
        idBase: string;
        locationName: string;
    }) => (
        <div
            id={`${idBase}-map-client`}
            data-testid="alert-location-map-client"
        >
            {locationName}
        </div>
    ),
}));

describe('AlertLocationMap', () => {
    it('loads the lazy client map component from a named export module', async () => {
        render(
            <AlertLocationMap
                idBase="test-alert"
                lat={43.6532}
                lng={-79.3832}
                locationName="Downtown Toronto"
            />,
        );

        const loadedClientMap = await screen.findByTestId(
            'alert-location-map-client',
        );

        expect(loadedClientMap).toHaveTextContent('Downtown Toronto');
        expect(loadedClientMap).toHaveAttribute('id', 'test-alert-map-client');
    });
});
