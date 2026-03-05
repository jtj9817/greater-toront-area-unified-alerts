import React, { useEffect, useMemo, useState } from 'react';
import {
    fetchSubscriptionOptions,
    type SubscriptionOption,
    type SubscriptionOptions,
} from '../services/SubscriptionOptionsService';

type SubscriptionTab = 'routes' | 'stations' | 'lines';

type SubscriptionManagerProps = {
    authUserId: number | null;
    selectedSubscriptions: string[];
    onChange: (subscriptions: string[]) => void;
    fallbackRoutes?: string[];
};

const DEFAULT_OPTIONS: SubscriptionOptions = {
    agency: {
        urn: 'agency:ttc',
        name: 'Toronto Transit Commission',
    },
    routes: [],
    stations: [],
    lines: [],
};

const TAB_LABELS: Record<SubscriptionTab, string> = {
    routes: 'Routes',
    stations: 'Stations',
    lines: 'Lines',
};

const normalizeSubscriptions = (subscriptions: string[]): string[] =>
    Array.from(
        new Set(
            subscriptions
                .map((subscription) => subscription.trim().toLowerCase())
                .filter((subscription) => subscription.length > 0),
        ),
    );

const toIdToken = (value: string): string =>
    value.trim().toLowerCase().replace(/[^a-z0-9-]+/g, '-');

export const SubscriptionManager: React.FC<SubscriptionManagerProps> = ({
    authUserId,
    selectedSubscriptions,
    onChange,
    fallbackRoutes = [],
}) => {
    const [activeTab, setActiveTab] = useState<SubscriptionTab>('routes');
    const [searchQuery, setSearchQuery] = useState('');
    const [options, setOptions] =
        useState<SubscriptionOptions>(DEFAULT_OPTIONS);
    const [isLoading, setIsLoading] = useState(authUserId !== null);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);

    useEffect(() => {
        if (authUserId === null) {
            setIsLoading(false);
            return;
        }

        let isMounted = true;

        const loadOptions = async (): Promise<void> => {
            setIsLoading(true);
            setErrorMessage(null);

            try {
                const result = await fetchSubscriptionOptions();

                if (isMounted) {
                    setOptions((current) => ({
                        ...result,
                        routes:
                            result.routes.length > 0
                                ? result.routes
                                : current.routes,
                    }));
                }
            } catch {
                if (isMounted) {
                    setErrorMessage(
                        'Could not load all subscription options. Showing available route options only.',
                    );
                }
            } finally {
                if (isMounted) {
                    setIsLoading(false);
                }
            }
        };

        void loadOptions();

        return () => {
            isMounted = false;
        };
    }, [authUserId]);

    const mergedRouteOptions = useMemo(() => {
        const fallbackOptions = fallbackRoutes
            .map((route) => route.trim())
            .filter((route) => route.length > 0)
            .map(
                (route): SubscriptionOption => ({
                    urn: `route:${route}`.toLowerCase(),
                    id: route,
                    name: route,
                }),
            );

        const merged = [...fallbackOptions, ...options.routes];
        const deduped = new Map<string, SubscriptionOption>();

        for (const option of merged) {
            deduped.set(option.urn, option);
        }

        return Array.from(deduped.values()).sort((left, right) =>
            left.name.localeCompare(right.name, undefined, { numeric: true }),
        );
    }, [fallbackRoutes, options.routes]);

    const allOptionsByUrn = useMemo(() => {
        const map = new Map<string, SubscriptionOption>();

        for (const option of [
            ...mergedRouteOptions,
            ...options.stations,
            ...options.lines,
        ]) {
            map.set(option.urn, option);
        }

        return map;
    }, [mergedRouteOptions, options.stations, options.lines]);

    const visibleOptions = useMemo(() => {
        const tabOptions =
            activeTab === 'routes'
                ? mergedRouteOptions
                : activeTab === 'stations'
                  ? options.stations
                  : options.lines;
        const query = searchQuery.trim().toLowerCase();

        if (query.length === 0) {
            return tabOptions;
        }

        return tabOptions.filter((option) => {
            return (
                option.name.toLowerCase().includes(query) ||
                option.id.toLowerCase().includes(query)
            );
        });
    }, [
        activeTab,
        mergedRouteOptions,
        options.lines,
        options.stations,
        searchQuery,
    ]);

    const selectedSet = useMemo(
        () => new Set(normalizeSubscriptions(selectedSubscriptions)),
        [selectedSubscriptions],
    );

    const selectedChips = useMemo(() => {
        return normalizeSubscriptions(selectedSubscriptions).map(
            (subscription) => {
                const option = allOptionsByUrn.get(subscription);

                if (option) {
                    return {
                        urn: subscription,
                        label: option.name,
                    };
                }

                return {
                    urn: subscription,
                    label: subscription,
                };
            },
        );
    }, [allOptionsByUrn, selectedSubscriptions]);

    const toggleSubscription = (urn: string): void => {
        const normalized = urn.trim().toLowerCase();
        if (normalized.length === 0) {
            return;
        }

        if (selectedSet.has(normalized)) {
            onChange(
                normalizeSubscriptions(selectedSubscriptions).filter(
                    (subscription) => subscription !== normalized,
                ),
            );
            return;
        }

        onChange([
            ...normalizeSubscriptions(selectedSubscriptions),
            normalized,
        ]);
    };

    const removeSubscription = (urn: string): void => {
        onChange(
            normalizeSubscriptions(selectedSubscriptions).filter(
                (subscription) => subscription !== urn.trim().toLowerCase(),
            ),
        );
    };

    return (
        <section id="gta-alerts-subscription-manager" className="rounded-xl border border-white/10 bg-surface-dark p-4 md:p-5">
            <div id="gta-alerts-subscription-manager-header" className="mb-4 flex flex-wrap items-center justify-between gap-2">
                <h3 id="gta-alerts-subscription-manager-title" className="text-sm font-semibold tracking-wide text-primary uppercase">
                    My Subscriptions
                </h3>
                <span className="text-xs text-text-secondary">
                    {selectedSet.size} selected
                </span>
            </div>

            {errorMessage && (
                <div className="mb-3 rounded-lg border border-amber/40 bg-amber/10 px-3 py-2 text-xs text-amber">
                    {errorMessage}
                </div>
            )}

            <div id="gta-alerts-subscription-tab-list" className="mb-3 grid grid-cols-3 gap-2">
                {(['routes', 'stations', 'lines'] as SubscriptionTab[]).map(
                    (tab) => (
                        <button
                            id={`gta-alerts-subscription-tab-${tab}-btn`}
                            key={tab}
                            type="button"
                            onClick={() => setActiveTab(tab)}
                            className={`rounded-lg border px-3 py-2 text-sm transition ${
                                activeTab === tab
                                    ? 'border-primary/60 bg-primary/20 text-white'
                                    : 'border-white/15 bg-background-dark/60 text-text-secondary hover:text-white'
                            }`}
                        >
                            {TAB_LABELS[tab]}
                        </button>
                    ),
                )}
            </div>

            <label htmlFor="gta-alerts-subscription-search-input" className="mb-3 block">
                <span className="sr-only">Search subscriptions</span>
                <input
                    id="gta-alerts-subscription-search-input"
                    type="search"
                    value={searchQuery}
                    onChange={(event) => setSearchQuery(event.target.value)}
                    placeholder={`Search ${TAB_LABELS[activeTab].toLowerCase()}...`}
                    className="w-full rounded-lg border border-white/15 bg-background-dark px-3 py-2 text-sm text-white placeholder:text-text-secondary focus:border-primary/60 focus:outline-none"
                />
            </label>

            <div id="gta-alerts-subscription-options" className="max-h-64 space-y-2 overflow-auto pr-1">
                {isLoading ? (
                    <p className="text-sm text-text-secondary">
                        Loading subscription options...
                    </p>
                ) : visibleOptions.length === 0 ? (
                    <p className="text-sm text-text-secondary">
                        No options found for this search.
                    </p>
                ) : (
                    visibleOptions.map((option) => (
                        <label
                            htmlFor={`gta-alerts-subscription-option-${toIdToken(option.urn)}`}
                            key={option.urn}
                            className="flex items-center justify-between gap-3 rounded-lg border border-white/15 bg-background-dark/60 px-3 py-2 text-sm text-white hover:border-primary/40"
                        >
                            <span className="min-w-0 truncate">
                                {option.name}
                            </span>
                            <input
                                id={`gta-alerts-subscription-option-${toIdToken(option.urn)}`}
                                type="checkbox"
                                aria-label={`Subscription ${option.urn}`}
                                checked={selectedSet.has(option.urn)}
                                onChange={() => toggleSubscription(option.urn)}
                                className="h-4 w-4 shrink-0 accent-primary"
                            />
                        </label>
                    ))
                )}
            </div>

            <div id="gta-alerts-subscription-selected-chips" className="mt-4 flex flex-wrap gap-2">
                {selectedChips.length === 0 ? (
                    <p className="text-xs text-text-secondary">
                        No subscriptions selected yet.
                    </p>
                ) : (
                    selectedChips.map((chip) => (
                        <button
                            id={`gta-alerts-subscription-chip-remove-${toIdToken(chip.urn)}`}
                            key={chip.urn}
                            type="button"
                            onClick={() => removeSubscription(chip.urn)}
                            className="inline-flex items-center gap-2 rounded-full border border-white/15 bg-background-dark/80 px-3 py-1 text-xs text-white transition hover:border-coral/50 hover:text-coral"
                        >
                            <span>{chip.label}</span>
                            <span aria-hidden>×</span>
                        </button>
                    ))
                )}
            </div>
        </section>
    );
};
