import { router } from '@inertiajs/react';
import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { home } from '@/routes';
import { AlertDetailsView } from './components/AlertDetailsView';
import { BottomNav } from './components/BottomNav';
import { FeedView } from './components/FeedView';
import { Footer } from './components/Footer';
import { Icon } from './components/Icon';
import { NotificationInboxView } from './components/NotificationInboxView';
import { NotificationToastLayer } from './components/NotificationToastLayer';
import { SavedView } from './components/SavedView';
import { SettingsView } from './components/SettingsView';
import { Sidebar } from './components/Sidebar';
import { ZonesView } from './components/ZonesView';
import type { UnifiedAlertResource } from './domain/alerts';
import { useSavedAlerts } from './hooks/useSavedAlerts';
import { AlertService } from './services/AlertService';

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
    const [activeAlertId, setActiveAlertId] = useState<string | null>(null);
    const [isRefreshingFeed, setIsRefreshingFeed] = useState(false);
    const [inboxUnreadCount, setInboxUnreadCount] = useState(0);

    // Centralised saved-alert state
    const {
        savedIds,
        isSaved,
        isPending,
        toggleAlert,
        guestCapReached,
        evictOldestThree,
    } = useSavedAlerts({
        authUserId,
        initialSavedIds: initialSavedAlertIds,
    });

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

    // Map initial alerts for SavedView (which needs static data)
    const initialDomainAlerts = useMemo(() => {
        return AlertService.mapUnifiedAlertsToDomainAlerts(alerts.data);
    }, [alerts.data]);

    const activeAlert = useMemo(() => {
        if (!activeAlertId) {
            return null;
        }

        return (
            initialDomainAlerts.find((alert) => alert.id === activeAlertId) ??
            null
        );
    }, [activeAlertId, initialDomainAlerts]);

    const handleNavigate = (view: string) => {
        setCurrentView(view);
        setActiveAlertId(null);
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
        if (activeAlertId && activeAlert) {
            return (
                <AlertDetailsView
                    alert={activeAlert}
                    onBack={() => setActiveAlertId(null)}
                    isSaved={isSaved(activeAlert.id)}
                    isPending={isPending(activeAlert.id)}
                    onToggleSave={() => toggleAlert(activeAlert.id)}
                />
            );
        }

        switch (currentView) {
            case 'saved':
                return (
                    <SavedView
                        authUserId={authUserId}
                        onSelectAlert={setActiveAlertId}
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
                        onOpenAlert={setActiveAlertId}
                        onUnreadCountChange={setInboxUnreadCount}
                    />
                );
            case 'feed':
            default:
                return (
                    <FeedView
                        searchQuery={searchQuery}
                        onSelectAlert={setActiveAlertId}
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

                <Footer />
                <BottomNav
                    currentView={currentView}
                    onNavigate={handleNavigate}
                />
            </div>

            {currentView === 'feed' && (
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
            )}
            <NotificationToastLayer authUserId={authUserId} />
        </div>
    );
};

export default App;
