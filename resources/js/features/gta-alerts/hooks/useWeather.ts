import { useCallback, useEffect, useRef, useState } from 'react';
import { fromWeatherResource } from '../domain/weather/fromResource';
import type { WeatherData, WeatherLocation } from '../domain/weather/types';

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

/** Versioned localStorage key for the user's selected weather location. */
export const LOCATION_STORAGE_KEY = 'gta_weather_location_v1';
/** Versioned localStorage key for first-visit weather onboarding handling. */
export const LOCATION_PROMPT_STORAGE_KEY = 'gta_weather_location_prompt_v1';
export type LocationPromptResult = 'accepted' | 'declined' | 'deferred';

// ---------------------------------------------------------------------------
// localStorage helpers (SSR-safe)
// ---------------------------------------------------------------------------

function readStoredLocation(): WeatherLocation | null {
    if (typeof window === 'undefined') return null;

    try {
        const raw = localStorage.getItem(LOCATION_STORAGE_KEY);
        if (!raw) return null;

        const parsed: unknown = JSON.parse(raw);
        if (
            typeof parsed === 'object' &&
            parsed !== null &&
            typeof (parsed as Record<string, unknown>).fsa === 'string' &&
            typeof (parsed as Record<string, unknown>).label === 'string' &&
            typeof (parsed as Record<string, unknown>).lat === 'number' &&
            typeof (parsed as Record<string, unknown>).lng === 'number'
        ) {
            return parsed as WeatherLocation;
        }

        return null;
    } catch {
        return null;
    }
}

function writeStoredLocation(location: WeatherLocation | null): void {
    if (typeof window === 'undefined') return;

    try {
        if (location === null) {
            localStorage.removeItem(LOCATION_STORAGE_KEY);
        } else {
            localStorage.setItem(
                LOCATION_STORAGE_KEY,
                JSON.stringify(location),
            );
        }
    } catch {
        // localStorage may be unavailable (private browsing, quota exceeded).
    }
}

function readStoredPromptResult(): LocationPromptResult | null {
    if (typeof window === 'undefined') return null;

    try {
        const raw = localStorage.getItem(LOCATION_PROMPT_STORAGE_KEY);
        if (!raw) return null;

        const parsed: unknown = JSON.parse(raw);
        if (
            typeof parsed === 'object' &&
            parsed !== null &&
            (parsed as Record<string, unknown>).handled === true &&
            typeof (parsed as Record<string, unknown>).result === 'string'
        ) {
            const result = (parsed as Record<string, unknown>).result;
            if (
                result === 'accepted' ||
                result === 'declined' ||
                result === 'deferred'
            ) {
                return result;
            }
        }

        return null;
    } catch {
        return null;
    }
}

function writeStoredPromptResult(result: LocationPromptResult): void {
    if (typeof window === 'undefined') return;

    try {
        localStorage.setItem(
            LOCATION_PROMPT_STORAGE_KEY,
            JSON.stringify({
                handled: true,
                result,
            }),
        );
    } catch {
        // localStorage may be unavailable (private browsing, quota exceeded).
    }
}

// ---------------------------------------------------------------------------
// Hook
// ---------------------------------------------------------------------------

export interface UseWeatherReturn {
    /** The currently selected GTA location, or null if none chosen. */
    location: WeatherLocation | null;
    /** Live weather data for the selected location, or null if not yet loaded. */
    weather: WeatherData | null;
    /**
     * True only during the initial fetch when no prior weather data is cached.
     * Derived as: location set AND weather not yet loaded AND no error.
     * Stays false during background revalidation (stale-while-revalidate).
     */
    isLoading: boolean;
    /** Error message if the last fetch failed, otherwise null. */
    error: string | null;
    /** True when the first-visit weather onboarding prompt should be shown. */
    shouldPromptForLocation: boolean;
    /** Update the selected location (persists to localStorage). Pass null to clear. */
    setLocation: (location: WeatherLocation | null) => void;
    /** Mark first-visit prompt handling result so it is not repeatedly shown. */
    markLocationPromptHandled: (result: LocationPromptResult) => void;
    /** Re-fetch weather for the current location (stale data remains visible). */
    refresh: () => void;
}

/**
 * useWeather — manages the user's selected location and live weather state.
 *
 * - Location is persisted in localStorage under `gta_weather_location_v1`.
 * - Stale weather data remains visible during background revalidation so the
 *   footer never shows a loading skeleton on refresh.
 * - `isLoading` is derived: true when location is set but no data has arrived
 *   yet (and no error). Background refreshes keep weather visible (isLoading stays false).
 */
export function useWeather(): UseWeatherReturn {
    const [location, setLocationState] = useState<WeatherLocation | null>(() =>
        readStoredLocation(),
    );
    const [weather, setWeather] = useState<WeatherData | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [promptResult, setPromptResult] =
        useState<LocationPromptResult | null>(() => readStoredPromptResult());

    const locationRef = useRef<WeatherLocation | null>(location);
    const abortControllerRef = useRef<AbortController | null>(null);

    // Derived loading indicator: true only on initial fetch (no stale data, no error).
    const isLoading = location !== null && weather === null && error === null;
    const shouldPromptForLocation = location === null && promptResult === null;

    useEffect(() => {
        locationRef.current = location;
    }, [location]);

    // -----------------------------------------------------------------------
    // Core fetch function
    // Only calls setState inside async promise callbacks — no synchronous state
    // mutations so this is safe to call from effects and event handlers.
    // -----------------------------------------------------------------------

    const fetchWeather = useCallback((fsa: string): void => {
        const requestFsa = fsa;
        abortControllerRef.current?.abort();
        const controller = new AbortController();
        abortControllerRef.current = controller;

        fetch(`/api/weather?fsa=${encodeURIComponent(fsa)}`, {
            signal: controller.signal,
        })
            .then(async (response) => {
                if (!response.ok) {
                    throw new Error(`Weather API error: ${response.status}`);
                }
                const body: unknown = await response.json();
                const raw =
                    body !== null &&
                    typeof body === 'object' &&
                    'data' in (body as Record<string, unknown>)
                        ? (body as Record<string, unknown>).data
                        : body;

                const mapped = fromWeatherResource(raw);
                if (!mapped) {
                    throw new Error('Received malformed weather data from API');
                }

                if (locationRef.current?.fsa !== requestFsa) {
                    return;
                }

                setWeather(mapped);
                setError(null);
            })
            .catch((err: unknown) => {
                if (err instanceof Error && err.name === 'AbortError') return;
                if (locationRef.current?.fsa !== requestFsa) return;
                setError(
                    err instanceof Error ? err.message : 'Weather fetch failed',
                );
            });
    }, []);

    // -----------------------------------------------------------------------
    // Auto-fetch when location changes
    // fetchWeather only sets state in async callbacks, so calling it from the
    // effect body does not trigger synchronous cascading renders.
    // -----------------------------------------------------------------------

    useEffect(() => {
        if (!location) return;

        fetchWeather(location.fsa);

        return () => {
            abortControllerRef.current?.abort();
        };
    }, [location, fetchWeather]);

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    const markLocationPromptHandled = useCallback(
        (result: LocationPromptResult): void => {
            writeStoredPromptResult(result);
            setPromptResult(result);
        },
        [],
    );

    const setLocation = useCallback(
        (next: WeatherLocation | null): void => {
            // Cancel any in-flight request immediately so older responses cannot
            // race and overwrite state for the newly selected location.
            abortControllerRef.current?.abort();
            writeStoredLocation(next);
            if (next !== null) {
                markLocationPromptHandled('accepted');
            }
            // Reset weather and error so the derived isLoading becomes true
            // immediately for the new location (or stays false when clearing).
            setWeather(null);
            setError(null);
            setLocationState(next);
        },
        [markLocationPromptHandled],
    );

    const refresh = useCallback((): void => {
        const current = locationRef.current;
        if (!current) return;
        // Does NOT clear weather — stale data stays visible during revalidation.
        fetchWeather(current.fsa);
    }, [fetchWeather]);

    return {
        location,
        weather,
        isLoading,
        error,
        shouldPromptForLocation,
        setLocation,
        markLocationPromptHandled,
        refresh,
    };
}
