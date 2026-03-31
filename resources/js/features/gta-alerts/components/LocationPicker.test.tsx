import {
    render,
    screen,
    fireEvent,
    waitFor,
    act,
} from '@testing-library/react';
import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import type { WeatherLocation } from '../domain/weather/types';
import type { LocationPickerHandle } from './LocationPicker';
import { LocationPicker } from './LocationPicker';

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeSearchResponse(results: unknown[] = []) {
    return { data: results };
}

function makePostalCodeResult(overrides: Record<string, unknown> = {}) {
    return {
        fsa: 'M5V',
        municipality: 'Toronto',
        neighbourhood: 'Waterfront Communities',
        lat: 43.6406,
        lng: -79.3961,
        ...overrides,
    };
}

function mockFetchOk(body: unknown): Response {
    return {
        ok: true,
        status: 200,
        json: async () => body,
    } as unknown as Response;
}

function mockFetchError(status: number): Response {
    return {
        ok: false,
        status,
        json: async () => ({ message: 'error' }),
    } as unknown as Response;
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

describe('LocationPicker', () => {
    beforeEach(() => {
        vi.restoreAllMocks();
        document.head.querySelector('meta[name="csrf-token"]')?.remove();
    });

    // -----------------------------------------------------------------------
    // Rendering
    // -----------------------------------------------------------------------

    it('renders a search input', () => {
        render(<LocationPicker onSelect={vi.fn()} />);
        expect(
            screen.getByPlaceholderText(/postal code|search/i),
        ).toBeInTheDocument();
    });

    it('renders a geolocation button', () => {
        render(<LocationPicker onSelect={vi.fn()} />);
        expect(
            screen.getByRole('button', { name: /use my location/i }),
        ).toBeInTheDocument();
    });

    it('displays the selected location label when provided', () => {
        const location: WeatherLocation = {
            fsa: 'M5V',
            label: 'M5V — Waterfront Communities, Toronto',
            lat: 43.6406,
            lng: -79.3961,
        };
        render(
            <LocationPicker onSelect={vi.fn()} selectedLocation={location} />,
        );
        expect(screen.getByText(/Waterfront Communities/)).toBeInTheDocument();
    });

    // -----------------------------------------------------------------------
    // Search
    // -----------------------------------------------------------------------

    it('does not search when input is shorter than 2 characters', () => {
        const fetchSpy = vi
            .spyOn(global, 'fetch')
            .mockResolvedValue(mockFetchOk(makeSearchResponse()));
        fetchSpy.mockClear();

        render(<LocationPicker onSelect={vi.fn()} />);
        const input = screen.getByPlaceholderText(/postal code|search/i);

        fireEvent.change(input, { target: { value: 'M' } });

        expect(fetchSpy).not.toHaveBeenCalled();
    });

    it('fetches postal code suggestions when input has 2+ characters', async () => {
        vi.spyOn(global, 'fetch').mockResolvedValue(
            mockFetchOk(makeSearchResponse([makePostalCodeResult()])),
        );

        render(<LocationPicker onSelect={vi.fn()} />);
        const input = screen.getByPlaceholderText(/postal code|search/i);

        fireEvent.change(input, { target: { value: 'M5' } });

        await waitFor(() => {
            expect(global.fetch).toHaveBeenCalledWith(
                expect.stringContaining('/api/postal-codes?q=M5'),
                expect.objectContaining({ signal: expect.anything() }),
            );
        });
    });

    it('renders search results as options', async () => {
        vi.spyOn(global, 'fetch').mockResolvedValue(
            mockFetchOk(
                makeSearchResponse([
                    makePostalCodeResult({
                        fsa: 'M5V',
                        neighbourhood: 'Waterfront Communities',
                    }),
                    makePostalCodeResult({
                        fsa: 'M5A',
                        neighbourhood: 'Regent Park',
                    }),
                ]),
            ),
        );

        render(<LocationPicker onSelect={vi.fn()} />);
        const input = screen.getByPlaceholderText(/postal code|search/i);

        fireEvent.change(input, { target: { value: 'M5' } });

        await waitFor(() => {
            expect(screen.getByText(/M5V/)).toBeInTheDocument();
            expect(screen.getByText(/M5A/)).toBeInTheDocument();
        });
    });

    it('calls onSelect with the correct WeatherLocation when a result is clicked', async () => {
        const onSelect = vi.fn();
        vi.spyOn(global, 'fetch').mockResolvedValue(
            mockFetchOk(
                makeSearchResponse([
                    makePostalCodeResult({
                        fsa: 'M5V',
                        municipality: 'Toronto',
                        neighbourhood: 'Waterfront Communities',
                        lat: 43.6406,
                        lng: -79.3961,
                    }),
                ]),
            ),
        );

        render(<LocationPicker onSelect={onSelect} />);
        const input = screen.getByPlaceholderText(/postal code|search/i);

        fireEvent.change(input, { target: { value: 'M5V' } });

        await waitFor(() => {
            expect(screen.getByText(/M5V/)).toBeInTheDocument();
        });

        fireEvent.click(screen.getByText(/M5V/));

        expect(onSelect).toHaveBeenCalledWith(
            expect.objectContaining({
                fsa: 'M5V',
                lat: 43.6406,
                lng: -79.3961,
            }),
        );
    });

    it('clears results when input is cleared', async () => {
        vi.spyOn(global, 'fetch').mockResolvedValue(
            mockFetchOk(makeSearchResponse([makePostalCodeResult()])),
        );

        render(<LocationPicker onSelect={vi.fn()} />);
        const input = screen.getByPlaceholderText(/postal code|search/i);

        fireEvent.change(input, { target: { value: 'M5V' } });

        await waitFor(() => {
            expect(screen.getByText(/M5V/)).toBeInTheDocument();
        });

        fireEvent.change(input, { target: { value: '' } });

        await waitFor(() => {
            expect(screen.queryByText(/M5V/)).not.toBeInTheDocument();
        });
    });

    // -----------------------------------------------------------------------
    // Geolocation
    // -----------------------------------------------------------------------

    it('calls navigator.geolocation.getCurrentPosition when geolocation button is clicked', async () => {
        const mockGetCurrentPosition = vi.fn();
        Object.defineProperty(global.navigator, 'geolocation', {
            value: { getCurrentPosition: mockGetCurrentPosition },
            writable: true,
            configurable: true,
        });

        render(<LocationPicker onSelect={vi.fn()} />);
        fireEvent.click(
            screen.getByRole('button', { name: /use my location/i }),
        );

        expect(mockGetCurrentPosition).toHaveBeenCalled();
    });

    it('exposes requestGeolocation through component ref', () => {
        const mockGetCurrentPosition = vi.fn();
        Object.defineProperty(global.navigator, 'geolocation', {
            value: { getCurrentPosition: mockGetCurrentPosition },
            writable: true,
            configurable: true,
        });

        const ref = React.createRef<LocationPickerHandle>();
        render(<LocationPicker ref={ref} onSelect={vi.fn()} />);

        act(() => {
            ref.current?.requestGeolocation();
        });

        expect(mockGetCurrentPosition).toHaveBeenCalledTimes(1);
    });

    it('resolves geolocation coordinates and calls onSelect', async () => {
        const onSelect = vi.fn();
        const onGeolocationResult = vi.fn();
        const csrfMeta = document.createElement('meta');
        csrfMeta.setAttribute('name', 'csrf-token');
        csrfMeta.setAttribute('content', 'test-csrf-token');
        document.head.appendChild(csrfMeta);

        const mockGetCurrentPosition = vi.fn((success: PositionCallback) => {
            success({
                coords: { latitude: 43.6406, longitude: -79.3961 },
            } as GeolocationPosition);
        });

        Object.defineProperty(global.navigator, 'geolocation', {
            value: { getCurrentPosition: mockGetCurrentPosition },
            writable: true,
            configurable: true,
        });

        vi.spyOn(global, 'fetch').mockResolvedValue(
            mockFetchOk({
                data: makePostalCodeResult({
                    fsa: 'M5V',
                    municipality: 'Toronto',
                    neighbourhood: 'Waterfront Communities',
                    lat: 43.6406,
                    lng: -79.3961,
                }),
            }),
        );

        render(
            <LocationPicker
                onSelect={onSelect}
                onGeolocationResult={onGeolocationResult}
            />,
        );

        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', { name: /use my location/i }),
            );
        });

        await waitFor(() => {
            expect(global.fetch).toHaveBeenCalledWith(
                expect.stringContaining('/api/postal-codes/resolve-coords'),
                expect.objectContaining({
                    method: 'POST',
                    headers: expect.objectContaining({
                        'X-CSRF-TOKEN': 'test-csrf-token',
                    }),
                }),
            );
        });

        await waitFor(() => {
            expect(onSelect).toHaveBeenCalledWith(
                expect.objectContaining({ fsa: 'M5V' }),
            );
        });

        expect(onGeolocationResult).toHaveBeenCalledWith('success');
    });

    it('clears geolocation error after successful manual selection', async () => {
        const onSelect = vi.fn();
        const mockGetCurrentPosition = vi.fn(
            (_success: PositionCallback, error: PositionErrorCallback) => {
                error({
                    code: 1,
                    message: 'User denied geolocation',
                    PERMISSION_DENIED: 1,
                    POSITION_UNAVAILABLE: 2,
                    TIMEOUT: 3,
                } as GeolocationPositionError);
            },
        );

        Object.defineProperty(global.navigator, 'geolocation', {
            value: { getCurrentPosition: mockGetCurrentPosition },
            writable: true,
            configurable: true,
        });

        vi.spyOn(global, 'fetch').mockResolvedValue(
            mockFetchOk(makeSearchResponse([makePostalCodeResult()])),
        );

        render(<LocationPicker onSelect={onSelect} />);

        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', { name: /use my location/i }),
            );
        });

        await waitFor(() => {
            expect(
                screen.getByText(/location access denied|denied|unavailable/i),
            ).toBeInTheDocument();
        });

        fireEvent.change(screen.getByPlaceholderText(/postal code|search/i), {
            target: { value: 'M5' },
        });

        await waitFor(() => {
            expect(screen.getByText(/M5V/)).toBeInTheDocument();
        });

        fireEvent.click(screen.getByText(/M5V/));

        expect(onSelect).toHaveBeenCalledWith(
            expect.objectContaining({ fsa: 'M5V' }),
        );
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });

    it('adds animate-spin class to the geolocation icon while loading', () => {
        const mockGetCurrentPosition = vi.fn();
        Object.defineProperty(global.navigator, 'geolocation', {
            value: { getCurrentPosition: mockGetCurrentPosition },
            writable: true,
            configurable: true,
        });

        render(<LocationPicker onSelect={vi.fn()} />);

        fireEvent.click(
            screen.getByRole('button', { name: /use my location/i }),
        );

        const icon = screen.getByText('sync');
        expect(icon).toHaveClass('animate-spin');
    });

    it('shows an error when geolocation is denied', async () => {
        const onGeolocationResult = vi.fn();
        const mockGetCurrentPosition = vi.fn(
            (_success: PositionCallback, error: PositionErrorCallback) => {
                error({
                    code: 1,
                    message: 'User denied geolocation',
                    PERMISSION_DENIED: 1,
                    POSITION_UNAVAILABLE: 2,
                    TIMEOUT: 3,
                } as GeolocationPositionError);
            },
        );

        Object.defineProperty(global.navigator, 'geolocation', {
            value: { getCurrentPosition: mockGetCurrentPosition },
            writable: true,
            configurable: true,
        });

        render(
            <LocationPicker
                onSelect={vi.fn()}
                onGeolocationResult={onGeolocationResult}
            />,
        );

        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', { name: /use my location/i }),
            );
        });

        await waitFor(() => {
            expect(
                screen.getByText(/location access denied|denied|unavailable/i),
            ).toBeInTheDocument();
        });

        expect(onGeolocationResult).toHaveBeenCalledWith('denied');
    });

    it('shows an error when resolve-coords returns 422 (out-of-GTA bounding box)', async () => {
        const mockGetCurrentPosition = vi.fn((success: PositionCallback) => {
            // London, UK — outside GTA bounding box
            success({
                coords: { latitude: 51.5, longitude: -0.1 },
            } as GeolocationPosition);
        });

        Object.defineProperty(global.navigator, 'geolocation', {
            value: { getCurrentPosition: mockGetCurrentPosition },
            writable: true,
            configurable: true,
        });

        vi.spyOn(global, 'fetch').mockResolvedValue(mockFetchError(422));

        render(<LocationPicker onSelect={vi.fn()} />);

        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', { name: /use my location/i }),
            );
        });

        await waitFor(() => {
            expect(
                screen.getByText(
                    /outside.*gta|gta.*outside|not.*gta|gta.*area|outside/i,
                ),
            ).toBeInTheDocument();
        });
    });

    it('shows a generic error when navigator.geolocation is unavailable', () => {
        Object.defineProperty(global.navigator, 'geolocation', {
            value: undefined,
            writable: true,
            configurable: true,
        });

        render(<LocationPicker onSelect={vi.fn()} />);
        fireEvent.click(
            screen.getByRole('button', { name: /use my location/i }),
        );

        expect(
            screen.getByText(/not supported|unavailable/i),
        ).toBeInTheDocument();
    });
});
