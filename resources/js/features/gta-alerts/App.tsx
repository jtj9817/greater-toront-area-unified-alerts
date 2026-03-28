import { router } from '@inertiajs/react';
import React, {
    useCallback,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import { home } from '@/routes';
import { AlertDetailsView } from './components/AlertDetailsView';
import { BottomNav } from './components/BottomNav';
import { FeedView } from './components/FeedView';
import { Footer } from './components/Footer';
import { Icon } from './components/Icon';
import { LocationPicker } from './components/LocationPicker';
import type { LocationPickerHandle } from './components/LocationPicker';
import { MinimalModeToggle } from './components/MinimalModeToggle';
import { NotificationInboxView } from './components/NotificationInboxView';
import { NotificationToastLayer } from './components/NotificationToastLayer';
import { SavedAlertActionToast } from './components/SavedAlertActionToast';
import { SavedView } from './components/SavedView';
import { SettingsView } from './components/SettingsView';
import { Sidebar } from './components/Sidebar';
import { ZonesView } from './components/ZonesView';
import type { UnifiedAlertResource } from './domain/alerts';
import { useMinimalMode } from './hooks/useMinimalMode';
import { useSavedAlerts } from './hooks/useSavedAlerts';
import { useWeather } from './hooks/useWeather';
import { AlertService } from './services/AlertService';

const ALERT_QUERY_PARAM = 'alert';
const SHARE_FEEDBACK_DISMISS_MS = 4500;

interface AppProps {
    alerts: {
        data: UnifiedAlertResource[];
        next_cursor: string | null;
    };
    filters: {
        status: 'all' | 'active' | 'cleared';
        sort: 'asc' | 'desc';
        source?: string | null;
        q?: string | null;
        since?: string | null;
    };
    latestFeedUpdatedAt: string | null;
    authUserId: number | null;
    subscriptionRouteOptions?: string[];
    initialSavedAlertIds?: string[];
}

const normalizeRouteOptions = (options: string[] | undefined): string[] => {
    return Array.from(
        new Set(
            (options ?? [])
                .map((option) => option.trim())
                .filter((option) => option.length > 0),
        ),
    ).sort((left, right) =>
        left.localeCompare(right, undefined, {
            numeric: true,
        }),
    );
};

const readAlertIdFromUrl = (): string | null => {
    if (typeof window === 'undefined') {
        return null;
    }

    const alertId = new URLSearchParams(window.location.search).get(
        ALERT_QUERY_PARAM,
    );
    if (!alertId) {
        return null;
    }

    const trimmed = alertId.trim();
    return trimmed === '' ? null : trimmed;
};

const App: React.FC<AppProps> = ({
    alerts,
    filters,
    latestFeedUpdatedAt,
    authUserId,
    subscriptionRouteOptions,
    initialSavedAlertIds = [],
}) => {
    const [currentView, setCurrentView] = useState('feed');
    const [searchQuery, setSearchQuery] = useState(filters.q || '');
    const [activeAlertId, setActiveAlertId] = useState<string | null>(
        readAlertIdFromUrl,
    );
    const [isRefreshingFeed, setIsRefreshingFeed] = useState(false);
    const [inboxUnreadCount, setInboxUnreadCount] = useState(0);
    const [shareFeedback, setShareFeedback] = useState<string | null>(null);

    // Weather state
    const {
        location: weatherLocation,
        weather,
        isLoading: isWeatherLoading,
        shouldPromptForLocation,
        markLocationPromptHandled,
        setLocation: setWeatherLocation,
    } = useWeather();
    const locationPickerRef = useRef<LocationPickerHandle | null>(null);

    const handleFirstVisitUseMyLocation = useCallback(() => {
        locationPickerRef.current?.requestGeolocation();
    }, []);

    const handleFirstVisitDismissLocationPrompt = useCallback(() => {
        markLocationPromptHandled('deferred');
    }, [markLocationPromptHandled]);

    const handleLocationPickerGeolocationResult = useCallback(
        (result: 'success' | 'denied' | 'error') => {
            if (result === 'success') {
                markLocationPromptHandled('accepted');
                return;
            }

            markLocationPromptHandled('declined');
        },
        [markLocationPromptHandled],
    );

    // Centralised saved-alert state
    const {
        savedIds,
        isSaved,
        isPending,
        toggleAlert,
        guestCapReached,
        evictOldestThree,
        feedback,
        clearFeedback,
    } = useSavedAlerts({
        authUserId,
        initialSavedIds: initialSavedAlertIds,
    });

    // Minimal mode state for feed view
    const { isHidden, toggleSection, isMinimalMode, toggleMinimalMode } =
        useMinimalMode();

    // Sync local search query with URL state (e.g., back button, Reset All Filters).
    const syncSearchQueryFromUrl = useCallback(() => {
        if (typeof window === 'undefined') return;
        const nextQuery =
            new URLSearchParams(window.location.search).get('q') || '';
        setSearchQuery((currentQuery) =>
            currentQuery === nextQuery ? currentQuery : nextQuery,
        );
    }, []);

    useEffect(() => {
        const removeListener = router.on('success', (event) => {
            if (event.detail.page.component !== 'gta-alerts') return;
            syncSearchQueryFromUrl();
        });
        return () => removeListener();
    }, [syncSearchQueryFromUrl]);

    // Debounce search update
    useEffect(() => {
        const timeoutId = setTimeout(() => {
            const urlQuery =
                typeof window === 'undefined'
                    ? ''
                    : new URLSearchParams(window.location.search).get('q') ||
                      '';
            if (searchQuery !== urlQuery) {
                router.get(
                    home({
                        query: {
                            status:
                                filters.status === 'all'
                                    ? null
                                    : filters.status,
                            sort: filters.sort === 'asc' ? filters.sort : null,
                            source: filters.source ?? null,
                            q: searchQuery || null,
                            since: filters.since ?? null,
                        },
                    }).url,
                    {},
                    {
                        preserveState: true,
                        preserveScroll: true,
                        replace: true,
                        only: ['alerts', 'filters'],
                    },
                );
            }
        }, 300);

        return () => clearTimeout(timeoutId);
    }, [searchQuery, filters]);

    // Sidebar states
    const [isSidebarCollapsed, setIsSidebarCollapsed] = useState(false);
    const [isMobileMenuOpen, setIsMobileMenuOpen] = useState(false);
    const closeMobileMenu = useCallback(() => {
        setIsMobileMenuOpen(false);
    }, []);

    useEffect(() => {
        if (typeof window === 'undefined') {
            return;
        }

        const handleEscape = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                closeMobileMenu();
            }
        };

        if (isMobileMenuOpen) {
            window.addEventListener('keydown', handleEscape);
        }

        return () => {
            window.removeEventListener('keydown', handleEscape);
        };
    }, [closeMobileMenu, isMobileMenuOpen]);

    useEffect(() => {
        if (
            typeof window === 'undefined' ||
            typeof window.matchMedia !== 'function'
        ) {
            return;
        }

        const mediaQuery = window.matchMedia('(min-width: 768px)');
        const handleViewportChange = (event: MediaQueryListEvent) => {
            if (event.matches) {
                closeMobileMenu();
            }
        };

        if (typeof mediaQuery.addEventListener === 'function') {
            mediaQuery.addEventListener('change', handleViewportChange);
            return () => {
                mediaQuery.removeEventListener('change', handleViewportChange);
            };
        }

        mediaQuery.addListener(handleViewportChange);
        return () => {
            mediaQuery.removeListener(handleViewportChange);
        };
    }, [closeMobileMenu]);

    const routeOptions = useMemo(
        () => normalizeRouteOptions(subscriptionRouteOptions),
        [subscriptionRouteOptions],
    );

    const updateAlertParamInUrl = useCallback((alertId: string | null) => {
        if (typeof window === 'undefined') {
            return;
        }

        const url = new URL(window.location.href);
        if (alertId === null) {
            url.searchParams.delete(ALERT_QUERY_PARAM);
        } else {
            url.searchParams.set(ALERT_QUERY_PARAM, alertId);
        }

        window.history.replaceState(window.history.state, '', url.toString());
    }, []);

    const openAlertDetails = useCallback(
        (alertId: string): void => {
            setActiveAlertId(alertId);
            updateAlertParamInUrl(alertId);
        },
        [updateAlertParamInUrl],
    );

    const closeAlertDetails = useCallback((): void => {
        setActiveAlertId(null);
        updateAlertParamInUrl(null);
    }, [updateAlertParamInUrl]);

    const buildAlertShareUrl = useCallback((alertId: string): string | null => {
        if (typeof window === 'undefined') {
            return null;
        }

        const url = new URL(window.location.href);
        url.searchParams.set(ALERT_QUERY_PARAM, alertId);
        return url.toString();
    }, []);

    const showShareFeedback = useCallback((message: string): void => {
        setShareFeedback(message);
    }, []);

    const handleShareAlert = useCallback(
        async (alertId: string, alertTitle: string): Promise<void> => {
            const shareUrl = buildAlertShareUrl(alertId);
            if (!shareUrl || typeof window === 'undefined') {
                showShareFeedback('Unable to share this alert.');
                return;
            }

            const nativeShare = window.navigator.share?.bind(window.navigator);
            if (nativeShare) {
                try {
                    await nativeShare({
                        title: 'GTA Alert',
                        text: alertTitle,
                        url: shareUrl,
                    });
                    showShareFeedback('Alert link shared.');
                    return;
                } catch (error) {
                    if (
                        error instanceof DOMException &&
                        error.name === 'AbortError'
                    ) {
                        return;
                    }
                }
            }

            try {
                if (window.navigator.clipboard?.writeText) {
                    await window.navigator.clipboard.writeText(shareUrl);
                    showShareFeedback('Alert link copied.');
                    return;
                }
            } catch {
                // Fall through to legacy copy fallback.
            }

            try {
                const textarea = document.createElement('textarea');
                textarea.value = shareUrl;
                textarea.setAttribute('readonly', '');
                textarea.style.position = 'fixed';
                textarea.style.left = '-9999px';
                document.body.appendChild(textarea);
                textarea.select();
                const copied = document.execCommand('copy');
                document.body.removeChild(textarea);

                if (copied) {
                    showShareFeedback('Alert link copied.');
                    return;
                }
            } catch {
                // No-op: final feedback handled below.
            }

            showShareFeedback('Unable to share this alert.');
        },
        [buildAlertShareUrl, showShareFeedback],
    );

    useEffect(() => {
        if (shareFeedback === null) {
            return;
        }

        const timeoutId = window.setTimeout(() => {
            setShareFeedback(null);
        }, SHARE_FEEDBACK_DISMISS_MS);

        return () => {
            window.clearTimeout(timeoutId);
        };
    }, [shareFeedback]);

    // Map initial alerts for SavedView (which needs static data)
    const initialDomainAlerts = useMemo(() => {
        return AlertService.mapUnifiedAlertsToDomainAlerts(alerts.data);
    }, [alerts.data]);

    const resolvedActiveAlertId = useMemo(() => {
        if (activeAlertId === null) {
            return null;
        }

        const exists = initialDomainAlerts.some(
            (alert) => alert.id === activeAlertId,
        );
        return exists ? activeAlertId : null;
    }, [activeAlertId, initialDomainAlerts]);

    const activeAlert = useMemo(() => {
        if (!resolvedActiveAlertId) {
            return null;
        }

        return (
            initialDomainAlerts.find(
                (alert) => alert.id === resolvedActiveAlertId,
            ) ?? null
        );
    }, [resolvedActiveAlertId, initialDomainAlerts]);

    useEffect(() => {
        if (activeAlertId !== null && resolvedActiveAlertId === null) {
            updateAlertParamInUrl(null);
        }
    }, [activeAlertId, resolvedActiveAlertId, updateAlertParamInUrl]);

    const handleNavigate = (view: string) => {
        setCurrentView(view);
        closeAlertDetails();
        closeMobileMenu(); // Close mobile drawer on navigation
    };

    const handleRefreshFeed = () => {
        if (currentView !== 'feed' || isRefreshingFeed) {
            return;
        }

        if (typeof window === 'undefined') {
            return;
        }

        setIsRefreshingFeed(true);
        router.get(
            `${window.location.pathname}${window.location.search}`,
            {},
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['alerts', 'filters', 'latestFeedUpdatedAt'],
                onFinish: () => {
                    setIsRefreshingFeed(false);
                },
            },
        );
    };

    const renderView = () => {
        if (resolvedActiveAlertId && activeAlert) {
            return (
                <AlertDetailsView
                    alert={activeAlert}
                    onBack={closeAlertDetails}
                    isSaved={isSaved(activeAlert.id)}
                    isPending={isPending(activeAlert.id)}
                    onToggleSave={() => toggleAlert(activeAlert.id)}
                    onShare={() =>
                        void handleShareAlert(activeAlert.id, activeAlert.title)
                    }
                />
            );
        }

        switch (currentView) {
            case 'saved':
                return (
                    <SavedView
                        authUserId={authUserId}
                        onSelectAlert={openAlertDetails}
                        allAlerts={initialDomainAlerts}
                        savedIds={savedIds}
                        isSaved={isSaved}
                        isPending={isPending}
                        onToggleSave={toggleAlert}
                        guestCapReached={guestCapReached}
                        onEvictOldest={evictOldestThree}
                    />
                );
            case 'zones':
                return <ZonesView />;
            case 'settings':
                return (
                    <SettingsView
                        authUserId={authUserId}
                        availableRoutes={routeOptions}
                    />
                );
            case 'inbox':
                return (
                    <NotificationInboxView
                        authUserId={authUserId}
                        onOpenAlert={openAlertDetails}
                        onUnreadCountChange={setInboxUnreadCount}
                    />
                );
            case 'feed':
            default:
                return (
                    <FeedView
                        searchQuery={searchQuery}
                        onSelectAlert={openAlertDetails}
                        initialAlerts={alerts.data}
                        initialNextCursor={alerts.next_cursor}
                        latestFeedUpdatedAt={latestFeedUpdatedAt}
                        status={filters.status}
                        sort={filters.sort}
                        source={filters.source ?? null}
                        since={filters.since ?? null}
                        savedIds={new Set(savedIds)}
                        isPending={isPending}
                        onToggleSave={toggleAlert}
                        hiddenSections={{
                            status: isHidden('status'),
                            category: isHidden('category'),
                            filter: isHidden('filter'),
                        }}
                    />
                );
        }
    };

    return (
        <div
            id="gta-alerts-app"
            className="gta-alerts-theme relative flex h-screen w-full overflow-hidden bg-background-dark font-sans text-white"
        >
            {/* Mobile Sidebar Overlay/Backdrop */}
            {isMobileMenuOpen && (
                <div
                    id="gta-alerts-mobile-overlay"
                    className="fixed inset-0 z-[90] bg-black/60 backdrop-blur-sm transition-opacity duration-300 md:hidden"
                    onClick={closeMobileMenu}
                />
            )}

            {/* Unified Sidebar (handles mobile drawer and desktop collapse) */}
            <Sidebar
                currentView={currentView}
                onNavigate={handleNavigate}
                isCollapsed={isSidebarCollapsed}
                onToggleCollapse={() =>
                    setIsSidebarCollapsed(!isSidebarCollapsed)
                }
                isMobileOpen={isMobileMenuOpen}
                onCloseMobile={closeMobileMenu}
            />

            <div
                id="gta-alerts-main-wrap"
                className="relative flex h-full min-w-0 flex-1 flex-col"
            >
                <header
                    id="gta-alerts-header"
                    className="z-50 flex-none border-b border-[#333333] bg-background-dark"
                >
                    <div id="gta-alerts-header-content" className="w-full">
                        <div
                            id="gta-alerts-header-mobile-row"
                            className="flex items-center justify-between border-b border-[#333333] px-4 py-3 md:hidden"
                        >
                            <div
                                id="gta-alerts-header-mobile-title"
                                className="flex items-center gap-2"
                            >
                                <button
                                    id="gta-alerts-header-mobile-menu-btn"
                                    onClick={() =>
                                        setIsMobileMenuOpen(
                                            (current) => !current,
                                        )
                                    }
                                    className="flex h-10 w-10 items-center justify-center border border-[#333333] bg-[#1a1a1a] text-white transition-colors hover:bg-primary hover:text-black"
                                    aria-label="Open menu"
                                    aria-controls="gta-alerts-sidebar"
                                    aria-expanded={isMobileMenuOpen}
                                >
                                    <Icon
                                        name="menu"
                                        style={{ fontSize: '22px' }}
                                    />
                                </button>
                                <h2 className="text-lg leading-tight font-black tracking-tight text-white uppercase">
                                    GTA Alerts
                                </h2>
                            </div>
                            <div
                                id="gta-alerts-header-mobile-actions"
                                className="flex gap-3"
                            >
                                <button
                                    id="gta-alerts-header-mobile-inbox-btn"
                                    onClick={() => handleNavigate('inbox')}
                                    className="relative border border-[#333333] bg-[#1a1a1a] p-2 text-white transition-colors hover:bg-primary hover:text-black"
                                    aria-label="Open notification center"
                                >
                                    <Icon
                                        name="crisis_alert"
                                        style={{ fontSize: '22px' }}
                                    />
                                    {authUserId !== null &&
                                        inboxUnreadCount > 0 && (
                                            <span className="absolute top-1.5 right-1.5 h-2 w-2 bg-critical"></span>
                                        )}
                                </button>
                                <button
                                    id="gta-alerts-header-mobile-settings-btn"
                                    onClick={() => handleNavigate('settings')}
                                    className="border border-[#333333] bg-[#1a1a1a] p-2 text-white transition-colors hover:bg-primary hover:text-black"
                                    aria-label="Open settings"
                                >
                                    <Icon
                                        name="settings"
                                        style={{ fontSize: '22px' }}
                                    />
                                </button>
                            </div>
                        </div>

                        <div
                            id="gta-alerts-header-search-row"
                            className="flex items-center gap-3 px-4 py-3 md:h-16 md:justify-between md:px-8"
                        >
                            <label
                                id="gta-alerts-search-label"
                                className="flex w-full md:max-w-xl"
                            >
                                <div
                                    id="gta-alerts-search-wrap"
                                    className="group relative flex h-10 w-full items-stretch md:h-11"
                                >
                                    <div
                                        id="gta-alerts-search-icon-wrap"
                                        className="pointer-events-none absolute top-0 left-0 z-10 flex h-full items-center pl-3"
                                    >
                                        <span className="text-primary transition-colors">
                                            <Icon name="search" />
                                        </span>
                                    </div>
                                    <input
                                        id="gta-alerts-search-input"
                                        className="flex h-full w-full min-w-0 resize-none overflow-hidden border border-[#333333] bg-[#1a1a1a] px-4 pl-10 text-sm leading-normal font-bold text-white uppercase placeholder:text-text-secondary/70 focus:border-primary focus:outline-none"
                                        placeholder="Search alerts, streets, or categories..."
                                        value={searchQuery}
                                        onChange={(e) =>
                                            setSearchQuery(e.target.value)
                                        }
                                    />
                                </div>
                            </label>

                            <div
                                id="gta-alerts-header-desktop-actions"
                                className="hidden items-center justify-center gap-3 pl-4 md:flex"
                            >
                                <div
                                    id="gta-alerts-header-location-picker"
                                    className="w-48"
                                >
                                    <LocationPicker
                                        ref={locationPickerRef}
                                        onSelect={setWeatherLocation}
                                        selectedLocation={weatherLocation}
                                        onGeolocationResult={
                                            handleLocationPickerGeolocationResult
                                        }
                                    />
                                </div>
                                <button
                                    id="gta-alerts-header-desktop-inbox-btn"
                                    className="relative flex items-center justify-center p-2 text-white transition-all hover:bg-white/10 hover:text-primary"
                                    onClick={() => handleNavigate('inbox')}
                                    aria-label="Open notification center"
                                >
                                    <Icon name="crisis_alert" />
                                    {authUserId !== null &&
                                        inboxUnreadCount > 0 && (
                                            <span className="absolute top-1.5 right-1.5 h-2 w-2 bg-critical"></span>
                                        )}
                                </button>
                                <button
                                    id="gta-alerts-header-desktop-settings-btn"
                                    className="flex items-center justify-center p-2 text-white transition-all hover:bg-white/10 hover:text-primary"
                                    onClick={() => handleNavigate('settings')}
                                    aria-label="Open settings"
                                >
                                    <Icon name="settings" />
                                </button>
                            </div>
                        </div>

                        {shouldPromptForLocation && (
                            <div
                                id="gta-alerts-weather-first-visit-prompt"
                                className="border-t border-[#333333] bg-[#121212] px-4 py-3 md:px-8"
                            >
                                <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                                    <p
                                        id="gta-alerts-weather-first-visit-prompt-text"
                                        className="text-[11px] font-bold tracking-widest text-white uppercase"
                                    >
                                        Enable local weather for your area?
                                    </p>
                                    <div className="flex items-center gap-2">
                                        <button
                                            id="gta-alerts-weather-first-visit-prompt-use-location"
                                            type="button"
                                            onClick={
                                                handleFirstVisitUseMyLocation
                                            }
                                            className="inline-flex items-center gap-1 border border-[#333333] bg-primary px-3 py-1.5 text-[10px] font-black tracking-widest text-black uppercase transition-colors hover:bg-white"
                                        >
                                            <Icon
                                                name="my_location"
                                                className="text-xs"
                                            />
                                            Use my location
                                        </button>
                                        <button
                                            id="gta-alerts-weather-first-visit-prompt-not-now"
                                            type="button"
                                            onClick={
                                                handleFirstVisitDismissLocationPrompt
                                            }
                                            className="inline-flex items-center border border-[#333333] bg-[#1a1a1a] px-3 py-1.5 text-[10px] font-black tracking-widest text-white uppercase transition-colors hover:bg-[#2b2b2b]"
                                        >
                                            Not now
                                        </button>
                                    </div>
                                </div>
                            </div>
                        )}

                        {/* Mobile-only compact weather bar — visible after location is selected */}
                        {!shouldPromptForLocation &&
                            weatherLocation !== null && (
                                <div
                                    id="gta-alerts-mobile-weather-bar"
                                    className="border-t border-[#333333] bg-[#121212] px-4 py-2 md:hidden"
                                >
                                    <div className="flex items-center gap-3 text-[11px] font-bold tracking-widest text-white uppercase">
                                        <span className="flex items-center gap-1">
                                            <Icon
                                                name="location_on"
                                                className="text-xs text-primary"
                                            />
                                            {weatherLocation.fsa}
                                        </span>
                                        {weather ? (
                                            <>
                                                <span className="flex items-center gap-1">
                                                    <Icon
                                                        name="thermostat"
                                                        className="text-xs"
                                                    />
                                                    {weather.temperature !==
                                                    null
                                                        ? `${weather.temperature}°C`
                                                        : '—°C'}
                                                </span>
                                                {weather.condition && (
                                                    <span className="truncate opacity-70">
                                                        {weather.condition}
                                                    </span>
                                                )}
                                                {weather.alertLevel && (
                                                    <span
                                                        role="status"
                                                        className={`flex items-center gap-1 px-2 py-0.5 text-[10px] font-black uppercase ${
                                                            weather.alertLevel ===
                                                            'yellow'
                                                                ? 'bg-yellow-400 text-black'
                                                                : weather.alertLevel ===
                                                                    'orange'
                                                                  ? 'bg-orange-500 text-white'
                                                                  : 'bg-red-600 text-white'
                                                        }`}
                                                    >
                                                        <Icon
                                                            name="warning"
                                                            className="text-xs"
                                                        />
                                                        {weather.alertText ??
                                                            `${weather.alertLevel.charAt(0).toUpperCase() + weather.alertLevel.slice(1)} alert`}
                                                    </span>
                                                )}
                                            </>
                                        ) : isWeatherLoading ? (
                                            <span className="opacity-50">
                                                Loading…
                                            </span>
                                        ) : null}
                                    </div>
                                </div>
                            )}
                    </div>
                </header>

                <main
                    id="gta-alerts-main-content"
                    className="no-scrollbar relative flex-1 overflow-y-auto scroll-smooth p-0 pb-6"
                >
                    <div id="gta-alerts-main-view" className="h-full w-full">
                        {renderView()}
                    </div>
                </main>

                <Footer weather={weather} />
                <BottomNav
                    currentView={currentView}
                    onNavigate={handleNavigate}
                />
            </div>

            {currentView === 'feed' && (
                <>
                    {/* Minimal Mode Toggle FAB */}
                    <div className="fixed right-5 bottom-40 z-[95] md:right-8 md:bottom-24">
                        <MinimalModeToggle
                            isHidden={isHidden}
                            toggleSection={toggleSection}
                            isMinimalMode={isMinimalMode}
                            toggleMinimalMode={toggleMinimalMode}
                        />
                    </div>
                    <button
                        id="gta-alerts-feed-refresh-btn"
                        onClick={handleRefreshFeed}
                        disabled={isRefreshingFeed}
                        aria-label="Refresh feed"
                        className="fixed right-5 bottom-24 z-[95] flex h-12 w-12 items-center justify-center border-2 border-black bg-primary text-black shadow-[5px_5px_0_#000] transition-all hover:translate-x-[1px] hover:translate-y-[1px] hover:shadow-none disabled:cursor-not-allowed disabled:opacity-60 md:right-8 md:bottom-8"
                    >
                        <Icon
                            name={isRefreshingFeed ? 'sync' : 'refresh'}
                            className={isRefreshingFeed ? 'animate-spin' : ''}
                        />
                    </button>
                </>
            )}
            <SavedAlertActionToast
                feedback={feedback}
                onDismiss={clearFeedback}
            />
            <NotificationToastLayer authUserId={authUserId} />
            {shareFeedback !== null && (
                <div
                    id="gta-alerts-share-alert-toast-layer"
                    className="pointer-events-none fixed top-4 left-4 z-[120] flex w-[min(92vw,360px)] flex-col gap-3"
                >
                    <article
                        id="gta-alerts-share-alert-toast"
                        className="pointer-events-auto animate-in rounded-xl border border-forest/50 bg-forest/15 p-3 shadow-xl backdrop-blur duration-200 fade-in slide-in-from-top-2"
                        role="status"
                        aria-live="polite"
                    >
                        <div className="mb-2 flex items-center gap-2">
                            <Icon
                                name="share"
                                fill={true}
                                className="text-forest"
                            />
                            <span className="text-xs font-semibold tracking-wide text-white uppercase">
                                Share Alert
                            </span>
                        </div>
                        <p className="text-sm leading-snug font-medium text-white">
                            {shareFeedback}
                        </p>
                    </article>
                </div>
            )}
        </div>
    );
};

export default App;
