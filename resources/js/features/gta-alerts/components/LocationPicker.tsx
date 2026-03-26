import React, { useCallback, useRef, useState } from 'react';
import type { PostalCodeResourceParsed } from '../domain/weather/resource';
import { PostalCodeResourceSchema } from '../domain/weather/resource';
import type { WeatherLocation } from '../domain/weather/types';
import { Icon } from './Icon';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

interface LocationPickerProps {
    /** Called when the user selects or resolves a location. */
    onSelect: (location: WeatherLocation) => void;
    /** The currently selected location, if any. */
    selectedLocation?: WeatherLocation | null;
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function buildLabel(result: PostalCodeResourceParsed): string {
    const parts = [result.fsa];

    if (result.neighbourhood) {
        parts.push(`${result.neighbourhood}, ${result.municipality}`);
    } else {
        parts.push(result.municipality);
    }

    return parts.join(' — ');
}

function toWeatherLocation(result: PostalCodeResourceParsed): WeatherLocation {
    return {
        fsa: result.fsa,
        label: buildLabel(result),
        lat: result.lat,
        lng: result.lng,
    };
}

function parseSingleResult(raw: unknown): PostalCodeResourceParsed | null {
    const parsed = PostalCodeResourceSchema.safeParse(raw);
    return parsed.success ? parsed.data : null;
}

function parseResultList(raw: unknown): PostalCodeResourceParsed[] {
    if (!Array.isArray(raw)) return [];
    return raw.flatMap((item) => {
        const parsed = parseSingleResult(item);
        return parsed ? [parsed] : [];
    });
}

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

/**
 * LocationPicker — lets users search for a GTA postal area by FSA /
 * municipality / neighbourhood, or resolve their position via browser
 * geolocation (constrained to the GTA bounding box).
 */
export const LocationPicker: React.FC<LocationPickerProps> = ({
    onSelect,
    selectedLocation = null,
}) => {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<PostalCodeResourceParsed[]>([]);
    const [geoError, setGeoError] = useState<string | null>(null);
    const [isGeoLoading, setIsGeoLoading] = useState(false);
    const abortRef = useRef<AbortController | null>(null);

    // -----------------------------------------------------------------------
    // Search — called directly from the onChange handler (not from an effect)
    // so that all setState calls originate from user events, not effects.
    // -----------------------------------------------------------------------

    const search = useCallback(async (q: string): Promise<void> => {
        abortRef.current?.abort();

        const controller = new AbortController();
        abortRef.current = controller;

        try {
            const response = await fetch(
                `/api/postal-codes?q=${encodeURIComponent(q)}&limit=10`,
                { signal: controller.signal },
            );

            if (!response.ok) {
                setResults([]);
                return;
            }

            const body: unknown = await response.json();
            const data =
                body !== null &&
                typeof body === 'object' &&
                'data' in (body as Record<string, unknown>)
                    ? (body as Record<string, unknown>).data
                    : [];

            setResults(parseResultList(data));
        } catch (err) {
            if (err instanceof Error && err.name === 'AbortError') return;
            setResults([]);
        }
    }, []);

    const handleQueryChange = useCallback(
        (e: React.ChangeEvent<HTMLInputElement>) => {
            const newQuery = e.target.value;
            setQuery(newQuery);

            if (newQuery.length < 2) {
                abortRef.current?.abort();
                setResults([]);
                return;
            }

            void search(newQuery);
        },
        [search],
    );

    // -----------------------------------------------------------------------
    // Geolocation
    // -----------------------------------------------------------------------

    const handleGeolocate = useCallback((): void => {
        setGeoError(null);

        if (!navigator.geolocation) {
            setGeoError(
                'Geolocation is not supported or unavailable in this browser.',
            );
            return;
        }

        setIsGeoLoading(true);

        navigator.geolocation.getCurrentPosition(
            (position) => {
                const { latitude, longitude } = position.coords;

                fetch('/api/postal-codes/resolve-coords', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ lat: latitude, lng: longitude }),
                })
                    .then(async (response) => {
                        if (!response.ok) {
                            const status = response.status;
                            if (status === 422) {
                                setGeoError(
                                    'Your location appears to be outside the GTA area.',
                                );
                            } else {
                                setGeoError(
                                    'Could not resolve your location. Please search instead.',
                                );
                            }
                            return;
                        }

                        const body: unknown = await response.json();
                        const raw =
                            body !== null &&
                            typeof body === 'object' &&
                            'data' in (body as Record<string, unknown>)
                                ? (body as Record<string, unknown>).data
                                : body;

                        const result = parseSingleResult(raw);
                        if (!result) {
                            setGeoError(
                                'Received an unexpected response. Please search instead.',
                            );
                            return;
                        }

                        onSelect(toWeatherLocation(result));
                    })
                    .catch(() => {
                        setGeoError(
                            'Failed to resolve your location. Please try again.',
                        );
                    })
                    .finally(() => {
                        setIsGeoLoading(false);
                    });
            },
            () => {
                setGeoError('Location access denied. Please search manually.');
                setIsGeoLoading(false);
            },
        );
    }, [onSelect]);

    // -----------------------------------------------------------------------
    // Render
    // -----------------------------------------------------------------------

    return (
        <div id="gta-location-picker" className="relative flex flex-col gap-1">
            <div className="flex items-center gap-2">
                <div className="relative flex-1">
                    <input
                        id="gta-location-picker-input"
                        type="text"
                        className="h-8 w-full border border-[#333333] bg-[#1a1a1a] px-3 text-xs font-bold text-white uppercase placeholder:text-text-secondary/70 focus:border-primary focus:outline-none"
                        placeholder="Search postal code or area…"
                        value={query}
                        onChange={handleQueryChange}
                        aria-label="Search for a GTA location"
                        aria-autocomplete="list"
                        aria-controls="gta-location-picker-results"
                        aria-expanded={results.length > 0}
                    />

                    {results.length > 0 && (
                        <ul
                            id="gta-location-picker-results"
                            role="listbox"
                            className="absolute top-full left-0 z-50 mt-1 w-full border border-[#333333] bg-[#111111] shadow-lg"
                        >
                            {results.map((result) => (
                                <li
                                    key={result.fsa}
                                    role="option"
                                    aria-selected={
                                        selectedLocation?.fsa === result.fsa
                                    }
                                    className="cursor-pointer px-3 py-2 text-xs font-bold text-white uppercase hover:bg-primary hover:text-black"
                                    onClick={() => {
                                        onSelect(toWeatherLocation(result));
                                        setQuery('');
                                        setResults([]);
                                    }}
                                >
                                    <span className="font-black">
                                        {result.fsa}
                                    </span>
                                    {' — '}
                                    {result.neighbourhood
                                        ? `${result.neighbourhood}, `
                                        : ''}
                                    {result.municipality}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

                <button
                    id="gta-location-picker-geolocate-btn"
                    type="button"
                    aria-label="Use my location"
                    disabled={isGeoLoading}
                    onClick={handleGeolocate}
                    className="flex h-8 w-8 flex-none items-center justify-center border border-[#333333] bg-[#1a1a1a] text-white transition-colors hover:bg-primary hover:text-black disabled:opacity-50"
                >
                    <Icon
                        name={isGeoLoading ? 'sync' : 'my_location'}
                        className={`text-xs${isGeoLoading ? 'animate-spin' : ''}`}
                    />
                </button>
            </div>

            {selectedLocation && (
                <p
                    id="gta-location-picker-selected"
                    className="truncate text-[10px] font-bold tracking-widest text-primary uppercase"
                >
                    {selectedLocation.label}
                </p>
            )}

            {geoError && (
                <p
                    id="gta-location-picker-error"
                    role="alert"
                    className="text-[10px] font-bold text-critical uppercase"
                >
                    {geoError}
                </p>
            )}
        </div>
    );
};
