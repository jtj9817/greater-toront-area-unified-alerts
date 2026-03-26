import { act, renderHook, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { useWeather } from './useWeather';

const LOCATION_STORAGE_KEY = 'gta_weather_location_v1';

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeWeatherResponse(overrides: Record<string, unknown> = {}) {
    return {
        data: {
            fsa: 'M5V',
            provider: 'environment_canada',
            temperature: 15.5,
            humidity: 65.0,
            wind_speed: '20 km/h',
            wind_direction: 'NW',
            condition: 'Mostly Cloudy',
            alert_level: null,
            alert_text: null,
            fetched_at: '2026-03-25T12:00:00+00:00',
            ...overrides,
        },
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

const mockLocation = {
    fsa: 'M5V',
    label: 'M5V — Waterfront Communities, Toronto',
    lat: 43.6406,
    lng: -79.3961,
};

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

describe('useWeather', () => {
    beforeEach(() => {
        localStorage.clear();
        vi.restoreAllMocks();
    });

    afterEach(() => {
        localStorage.clear();
    });

    // -----------------------------------------------------------------------
    // Default / empty state
    // -----------------------------------------------------------------------

    it('initializes with null location and null weather when localStorage is empty', () => {
        const { result } = renderHook(() => useWeather());

        expect(result.current.location).toBeNull();
        expect(result.current.weather).toBeNull();
        expect(result.current.isLoading).toBe(false);
        expect(result.current.error).toBeNull();
    });

    // -----------------------------------------------------------------------
    // localStorage persistence
    // -----------------------------------------------------------------------

    it('loads a previously persisted location from localStorage on mount', () => {
        localStorage.setItem(
            LOCATION_STORAGE_KEY,
            JSON.stringify(mockLocation),
        );

        const fetchSpy = vi
            .spyOn(global, 'fetch')
            .mockResolvedValue(mockFetchOk(makeWeatherResponse()));

        const { result } = renderHook(() => useWeather());

        expect(result.current.location).toEqual(mockLocation);
        fetchSpy.mockRestore();
    });

    it('ignores malformed localStorage data and falls back to null', () => {
        localStorage.setItem(LOCATION_STORAGE_KEY, 'not-valid-json{{');

        const { result } = renderHook(() => useWeather());

        expect(result.current.location).toBeNull();
    });

    it('persists location to localStorage when setLocation is called', async () => {
        vi.spyOn(global, 'fetch').mockResolvedValue(
            mockFetchOk(makeWeatherResponse()),
        );

        const { result } = renderHook(() => useWeather());

        await act(async () => {
            result.current.setLocation(mockLocation);
        });

        const stored = JSON.parse(
            localStorage.getItem(LOCATION_STORAGE_KEY) ?? 'null',
        );
        expect(stored).toEqual(mockLocation);
    });

    it('removes location from localStorage when setLocation(null) is called', async () => {
        localStorage.setItem(
            LOCATION_STORAGE_KEY,
            JSON.stringify(mockLocation),
        );
        vi.spyOn(global, 'fetch').mockResolvedValue(
            mockFetchOk(makeWeatherResponse()),
        );

        const { result } = renderHook(() => useWeather());

        // Wait for initial weather load from persisted location
        await waitFor(() => {
            expect(result.current.weather).not.toBeNull();
        });

        await act(async () => {
            result.current.setLocation(null);
        });

        expect(localStorage.getItem(LOCATION_STORAGE_KEY)).toBeNull();
        expect(result.current.location).toBeNull();
        expect(result.current.weather).toBeNull();
    });

    // -----------------------------------------------------------------------
    // API fetching
    // -----------------------------------------------------------------------

    it('fetches weather data when location is set', async () => {
        const fetchSpy = vi
            .spyOn(global, 'fetch')
            .mockResolvedValue(mockFetchOk(makeWeatherResponse()));

        const { result } = renderHook(() => useWeather());

        await act(async () => {
            result.current.setLocation(mockLocation);
        });

        await waitFor(() => {
            expect(result.current.weather).not.toBeNull();
        });

        expect(fetchSpy).toHaveBeenCalledWith(
            expect.stringContaining('/api/weather?fsa=M5V'),
            expect.objectContaining({ signal: expect.anything() }),
        );
        expect(result.current.weather?.fsa).toBe('M5V');
        expect(result.current.weather?.temperature).toBe(15.5);
        expect(result.current.weather?.windSpeed).toBe('20 km/h');
    });

    it('fetches weather for a persisted location on mount', async () => {
        localStorage.setItem(
            LOCATION_STORAGE_KEY,
            JSON.stringify(mockLocation),
        );

        const fetchSpy = vi
            .spyOn(global, 'fetch')
            .mockResolvedValue(mockFetchOk(makeWeatherResponse()));

        renderHook(() => useWeather());

        await waitFor(() => {
            expect(fetchSpy).toHaveBeenCalledWith(
                expect.stringContaining('/api/weather?fsa=M5V'),
                expect.objectContaining({ signal: expect.anything() }),
            );
        });
    });

    it('maps snake_case API response to camelCase WeatherData', async () => {
        vi.spyOn(global, 'fetch').mockResolvedValue(
            mockFetchOk(
                makeWeatherResponse({
                    wind_speed: '30 km/h',
                    wind_direction: 'S',
                    alert_level: 'orange',
                    alert_text: 'Freezing rain warning.',
                }),
            ),
        );

        const { result } = renderHook(() => useWeather());

        await act(async () => {
            result.current.setLocation(mockLocation);
        });

        await waitFor(() => {
            expect(result.current.weather?.windSpeed).toBe('30 km/h');
        });

        expect(result.current.weather?.windDirection).toBe('S');
        expect(result.current.weather?.alertLevel).toBe('orange');
        expect(result.current.weather?.alertText).toBe(
            'Freezing rain warning.',
        );
    });

    // -----------------------------------------------------------------------
    // Stale-while-revalidate
    // -----------------------------------------------------------------------

    it('keeps stale weather data visible while a background fetch is in-flight', async () => {
        let resolveFetch!: (value: Response) => void;
        const pendingFetch = new Promise<Response>((resolve) => {
            resolveFetch = resolve;
        });

        vi.spyOn(global, 'fetch')
            .mockResolvedValueOnce(mockFetchOk(makeWeatherResponse()))
            .mockReturnValueOnce(pendingFetch as Promise<Response>);

        const { result } = renderHook(() => useWeather());

        // First fetch — populates weather
        await act(async () => {
            result.current.setLocation(mockLocation);
        });

        await waitFor(() => {
            expect(result.current.weather).not.toBeNull();
        });

        const staleWeather = result.current.weather;

        // Trigger a refresh — second fetch is pending
        await act(async () => {
            result.current.refresh();
        });

        // Stale data must remain visible during the background fetch
        expect(result.current.weather).toEqual(staleWeather);
        expect(result.current.isLoading).toBe(false);

        // Resolve the background fetch
        await act(async () => {
            resolveFetch(mockFetchOk(makeWeatherResponse({ temperature: 20 })));
        });

        await waitFor(() => {
            expect(result.current.weather?.temperature).toBe(20);
        });
    });

    it('aborts an in-flight request when switching to a different location', async () => {
        let firstSignal: AbortSignal | undefined;
        const firstPending = new Promise<Response>(() => {
            // Intentionally left unresolved to assert abort-on-switch behavior.
        });

        vi.spyOn(global, 'fetch')
            .mockImplementationOnce(
                (_input: RequestInfo | URL, init?: RequestInit) => {
                    firstSignal = init?.signal as AbortSignal | undefined;
                    return firstPending;
                },
            )
            .mockResolvedValueOnce(mockFetchError(503));

        const { result } = renderHook(() => useWeather());

        act(() => {
            result.current.setLocation(mockLocation);
        });

        await waitFor(() => {
            expect(global.fetch).toHaveBeenCalledTimes(1);
        });

        act(() => {
            result.current.setLocation({
                ...mockLocation,
                fsa: 'M4B',
                label: 'M4B — East York, Toronto',
            });
        });

        expect(firstSignal?.aborted).toBe(true);

        await waitFor(() => {
            expect(result.current.error).toBe('Weather API error: 503');
        });

        expect(result.current.location?.fsa).toBe('M4B');
        expect(result.current.weather).toBeNull();
    });

    // -----------------------------------------------------------------------
    // Error handling
    // -----------------------------------------------------------------------

    it('sets error when API returns a non-ok response', async () => {
        vi.spyOn(global, 'fetch').mockResolvedValue(mockFetchError(503));

        const { result } = renderHook(() => useWeather());

        await act(async () => {
            result.current.setLocation(mockLocation);
        });

        await waitFor(() => {
            expect(result.current.error).not.toBeNull();
        });

        expect(result.current.weather).toBeNull();
        expect(result.current.isLoading).toBe(false);
    });

    it('sets error when API returns malformed JSON', async () => {
        vi.spyOn(global, 'fetch').mockResolvedValue(
            mockFetchOk({ data: { fsa: 123 /* invalid type */ } }),
        );

        const { result } = renderHook(() => useWeather());

        await act(async () => {
            result.current.setLocation(mockLocation);
        });

        await waitFor(() => {
            expect(result.current.error).not.toBeNull();
        });
    });

    it('clears error when a subsequent fetch succeeds', async () => {
        vi.spyOn(global, 'fetch')
            .mockResolvedValueOnce(mockFetchError(503))
            .mockResolvedValueOnce(mockFetchOk(makeWeatherResponse()));

        const { result } = renderHook(() => useWeather());

        await act(async () => {
            result.current.setLocation(mockLocation);
        });

        await waitFor(() => {
            expect(result.current.error).not.toBeNull();
        });

        await act(async () => {
            result.current.refresh();
        });

        await waitFor(() => {
            expect(result.current.error).toBeNull();
            expect(result.current.weather).not.toBeNull();
        });
    });

    it('sets isLoading true during initial fetch (no prior weather data)', async () => {
        let resolveFetch!: (value: Response) => void;
        const pendingFetch = new Promise<Response>((resolve) => {
            resolveFetch = resolve;
        });

        vi.spyOn(global, 'fetch').mockReturnValue(
            pendingFetch as Promise<Response>,
        );

        const { result } = renderHook(() => useWeather());

        act(() => {
            result.current.setLocation(mockLocation);
        });

        expect(result.current.isLoading).toBe(true);

        await act(async () => {
            resolveFetch(mockFetchOk(makeWeatherResponse()));
        });

        await waitFor(() => {
            expect(result.current.isLoading).toBe(false);
        });
    });
});
