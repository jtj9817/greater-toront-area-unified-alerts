import React, { useEffect, useMemo, useState } from 'react';
import {
    createSavedPlace,
    deleteSavedPlace,
    fetchSavedPlaces,
    searchGeocoding,
    SavedPlaceServiceError,
    type GeocodingSearchResult,
    type SavedPlace,
} from '../services/SavedPlaceService';

const RADIUS_OPTIONS = [250, 500, 1000, 2000, 5000];

type SavedPlacesManagerProps = {
    authUserId: number | null;
};

export const SavedPlacesManager: React.FC<SavedPlacesManagerProps> = ({
    authUserId,
}) => {
    const [isExpanded, setIsExpanded] = useState(false);
    const [hasLoadedPlaces, setHasLoadedPlaces] = useState(false);
    const [isBusy, setIsBusy] = useState(false);
    const [searchQuery, setSearchQuery] = useState('');
    const [searchResults, setSearchResults] = useState<GeocodingSearchResult[]>(
        [],
    );
    const [selectedResult, setSelectedResult] =
        useState<GeocodingSearchResult | null>(null);
    const [selectedRadius, setSelectedRadius] = useState(500);
    const [savedPlaces, setSavedPlaces] = useState<SavedPlace[]>([]);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);
    const [successMessage, setSuccessMessage] = useState<string | null>(null);

    useEffect(() => {
        if (!isExpanded || hasLoadedPlaces || authUserId === null) {
            return;
        }

        let isMounted = true;

        const load = async (): Promise<void> => {
            setIsBusy(true);
            setErrorMessage(null);

            try {
                const places = await fetchSavedPlaces();

                if (isMounted) {
                    setSavedPlaces(places);
                    setHasLoadedPlaces(true);
                }
            } catch (error) {
                if (!isMounted) {
                    return;
                }

                if (
                    error instanceof SavedPlaceServiceError &&
                    error.status === 401
                ) {
                    setErrorMessage(
                        'Please sign in again to manage saved places.',
                    );
                } else {
                    setErrorMessage('Unable to load saved places right now.');
                }
            } finally {
                if (isMounted) {
                    setIsBusy(false);
                }
            }
        };

        void load();

        return () => {
            isMounted = false;
        };
    }, [authUserId, hasLoadedPlaces, isExpanded]);

    useEffect(() => {
        if (!isExpanded) {
            return;
        }

        const query = searchQuery.trim();

        if (query.length < 2) {
            setSearchResults([]);
            setSelectedResult(null);
            return;
        }

        const timeout = setTimeout(() => {
            void (async () => {
                try {
                    const results = await searchGeocoding(query);
                    setSearchResults(results);
                    setSelectedResult((current) => {
                        if (!current) {
                            return null;
                        }

                        return (
                            results.find(
                                (result) => result.id === current.id,
                            ) ?? null
                        );
                    });
                } catch {
                    setSearchResults([]);
                }
            })();
        }, 250);

        return () => clearTimeout(timeout);
    }, [isExpanded, searchQuery]);

    const selectedResultLabel = useMemo(() => {
        if (selectedResult === null) {
            return null;
        }

        if (selectedResult.secondary) {
            return `${selectedResult.name} (${selectedResult.secondary})`;
        }

        return selectedResult.name;
    }, [selectedResult]);

    const toggleExpanded = (): void => {
        setIsExpanded((current) => !current);
        setErrorMessage(null);
        setSuccessMessage(null);
    };

    const handleSelectResult = (result: GeocodingSearchResult): void => {
        setSelectedResult(result);
        setSearchQuery(result.name);
        setSuccessMessage(null);
        setErrorMessage(null);
    };

    const handleSavePlace = async (): Promise<void> => {
        if (selectedResult === null) {
            setErrorMessage('Select an address or place before saving.');
            return;
        }

        setIsBusy(true);
        setErrorMessage(null);
        setSuccessMessage(null);

        try {
            const created = await createSavedPlace({
                name: selectedResult.name,
                lat: selectedResult.lat,
                long: selectedResult.long,
                radius: selectedRadius,
                type: selectedResult.type,
            });

            setSavedPlaces((current) =>
                [...current, created].sort((left, right) =>
                    left.name.localeCompare(right.name),
                ),
            );
            setSearchResults([]);
            setSelectedResult(null);
            setSearchQuery('');
            setSuccessMessage('Saved place added.');
        } catch (error) {
            if (
                error instanceof SavedPlaceServiceError &&
                error.status === 422
            ) {
                setErrorMessage(
                    'Saved place is outside the supported GTA area or is invalid.',
                );
            } else {
                setErrorMessage('Unable to save this place right now.');
            }
        } finally {
            setIsBusy(false);
        }
    };

    const handleDeletePlace = async (savedPlaceId: number): Promise<void> => {
        setIsBusy(true);
        setErrorMessage(null);
        setSuccessMessage(null);

        try {
            await deleteSavedPlace(savedPlaceId);
            setSavedPlaces((current) =>
                current.filter((item) => item.id !== savedPlaceId),
            );
            setSuccessMessage('Saved place removed.');
        } catch {
            setErrorMessage('Unable to remove that place right now.');
        } finally {
            setIsBusy(false);
        }
    };

    if (authUserId === null) {
        return null;
    }

    return (
        <section
            id="gta-alerts-saved-places-manager"
            className="rounded-xl border border-white/10 bg-surface-dark p-4 md:p-5"
        >
            <div
                id="gta-alerts-saved-places-manager-header"
                className="flex flex-wrap items-center justify-between gap-3"
            >
                <div>
                    <h3
                        id="gta-alerts-saved-places-manager-title"
                        className="text-sm font-semibold tracking-wide text-primary uppercase"
                    >
                        Saved Places
                    </h3>
                    <p className="mt-1 text-xs text-text-secondary">
                        Search Toronto addresses or POIs and save geofenced
                        places.
                    </p>
                </div>
                <button
                    id="gta-alerts-saved-places-toggle-btn"
                    type="button"
                    onClick={toggleExpanded}
                    className="rounded-lg border border-white/15 bg-background-dark px-3 py-2 text-xs font-medium text-white transition hover:border-primary/40"
                >
                    {isExpanded ? 'Hide manager' : 'Manage saved places'}
                </button>
            </div>

            {isExpanded && (
                <div className="mt-4 space-y-4">
                    {errorMessage && (
                        <div className="rounded-lg border border-coral/40 bg-coral/10 px-3 py-2 text-xs text-coral">
                            {errorMessage}
                        </div>
                    )}
                    {successMessage && (
                        <div className="rounded-lg border border-forest/40 bg-forest/10 px-3 py-2 text-xs text-forest">
                            {successMessage}
                        </div>
                    )}

                    <div className="grid gap-3 md:grid-cols-[1.6fr_0.6fr_auto]">
                        <div>
                            <input
                                id="gta-alerts-saved-places-search-input"
                                type="text"
                                value={searchQuery}
                                onChange={(event) =>
                                    setSearchQuery(event.target.value)
                                }
                                placeholder="Search: 100 Queen St W, CN Tower..."
                                className="w-full rounded-lg border border-white/15 bg-background-dark px-3 py-2 text-sm text-white focus:border-primary/60 focus:outline-none"
                            />
                            {searchResults.length > 0 && (
                                <div
                                    id="gta-alerts-saved-places-search-results"
                                    className="mt-2 max-h-48 overflow-y-auto rounded-lg border border-white/15 bg-background-dark"
                                >
                                    {searchResults.map((result) => {
                                        const isSelected =
                                            selectedResult?.id === result.id;

                                        return (
                                            <button
                                                id={`gta-alerts-saved-places-search-result-${result.id}`}
                                                key={result.id}
                                                type="button"
                                                onClick={() =>
                                                    handleSelectResult(result)
                                                }
                                                className={`flex w-full flex-col items-start px-3 py-2 text-left text-sm transition ${
                                                    isSelected
                                                        ? 'bg-[#FF7F00]/20 text-white'
                                                        : 'text-white hover:bg-white/10'
                                                }`}
                                            >
                                                <span>{result.name}</span>
                                                {result.secondary && (
                                                    <span className="text-xs text-text-secondary">
                                                        {result.secondary}
                                                    </span>
                                                )}
                                            </button>
                                        );
                                    })}
                                </div>
                            )}
                        </div>

                        <select
                            id="gta-alerts-saved-places-radius-select"
                            value={selectedRadius}
                            onChange={(event) =>
                                setSelectedRadius(Number(event.target.value))
                            }
                            className="rounded-lg border border-white/15 bg-background-dark px-3 py-2 text-sm text-white focus:border-primary/60 focus:outline-none"
                        >
                            {RADIUS_OPTIONS.map((radius) => (
                                <option
                                    key={radius}
                                    value={radius}
                                    className="bg-background-dark"
                                >
                                    {radius} m radius
                                </option>
                            ))}
                        </select>

                        <button
                            id="gta-alerts-saved-places-save-btn"
                            type="button"
                            onClick={() => {
                                void handleSavePlace();
                            }}
                            disabled={isBusy}
                            className="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white transition hover:bg-primary/90 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            Save place
                        </button>
                    </div>

                    {selectedResultLabel && (
                        <div className="rounded-lg border border-white/15 bg-background-dark/60 px-3 py-2 text-xs text-text-secondary">
                            Selected: {selectedResultLabel}
                        </div>
                    )}

                    {isBusy && !hasLoadedPlaces ? (
                        <p className="text-sm text-text-secondary">
                            Loading saved places...
                        </p>
                    ) : savedPlaces.length === 0 ? (
                        <p className="text-sm text-text-secondary">
                            No saved places configured.
                        </p>
                    ) : (
                        <div
                            id="gta-alerts-saved-places-list"
                            className="space-y-2"
                        >
                            {savedPlaces.map((place) => (
                                <div
                                    id={`gta-alerts-saved-places-item-${place.id}`}
                                    key={place.id}
                                    className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-white/15 bg-background-dark/60 px-3 py-2"
                                >
                                    <div className="min-w-0">
                                        <div className="text-sm font-medium text-white">
                                            {place.name}
                                        </div>
                                        <div className="text-xs text-text-secondary">
                                            {place.type} • {place.radius}m •{' '}
                                            {place.lat.toFixed(4)},{' '}
                                            {place.long.toFixed(4)}
                                        </div>
                                    </div>
                                    <button
                                        id={`gta-alerts-saved-places-remove-btn-${place.id}`}
                                        type="button"
                                        onClick={() => {
                                            void handleDeletePlace(place.id);
                                        }}
                                        className="rounded-md border border-white/15 px-2 py-1 text-xs text-white transition hover:border-coral/50 hover:text-coral"
                                    >
                                        Remove
                                    </button>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            )}
        </section>
    );
};
