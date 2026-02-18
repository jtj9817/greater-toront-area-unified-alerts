import React, { useState, useMemo } from 'react';
import { AlertDetailsView } from './components/AlertDetailsView';
import { BottomNav } from './components/BottomNav';
import { FeedView } from './components/FeedView';
import { Icon } from './components/Icon';
import { NotificationInboxView } from './components/NotificationInboxView';
import { NotificationToastLayer } from './components/NotificationToastLayer';
import { SavedView } from './components/SavedView';
import { SettingsView } from './components/SettingsView';
import { Sidebar } from './components/Sidebar';
import { ZonesView } from './components/ZonesView';
import type { DomainAlert, UnifiedAlertResource } from './domain/alerts';
import { AlertService } from './services/AlertService';

interface AppProps {
    alerts: {
        data: UnifiedAlertResource[];
        links: Record<string, string | null>;
        meta: Record<string, unknown>;
    };
    filters: {
        status: 'all' | 'active' | 'cleared';
    };
    latestFeedUpdatedAt: string | null;
    authUserId: number | null;
    subscriptionRouteOptions?: string[];
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
}) => {
    const [currentView, setCurrentView] = useState('feed');
    const [activeAlertId, setActiveAlertId] = useState<string | null>(null);
    const [searchQuery, setSearchQuery] = useState('');

    // Sidebar states
    const [isSidebarCollapsed, setIsSidebarCollapsed] = useState(false);
    const [isMobileMenuOpen, setIsMobileMenuOpen] = useState(false);

    // Map backend unified alerts to frontend domain values
    const allAlerts = useMemo(() => {
        return AlertService.mapUnifiedAlertsToDomainAlerts(alerts.data);
    }, [alerts.data]);

    const pagination = useMemo(() => {
        const meta = alerts.meta as Record<string, unknown>;
        return {
            prevUrl: alerts.links?.prev ?? null,
            nextUrl: alerts.links?.next ?? null,
            currentPage:
                typeof meta.current_page === 'number'
                    ? meta.current_page
                    : null,
            lastPage:
                typeof meta.last_page === 'number' ? meta.last_page : null,
            total: typeof meta.total === 'number' ? meta.total : null,
        };
    }, [alerts.links, alerts.meta]);

    const routeOptions = useMemo(
        () => normalizeRouteOptions(subscriptionRouteOptions),
        [subscriptionRouteOptions],
    );

    // Use the local alerts to find the active alert
    const activeAlert = useMemo<DomainAlert | null>(() => {
        return activeAlertId
            ? (allAlerts.find((item) => item.id === activeAlertId) ?? null)
            : null;
    }, [activeAlertId, allAlerts]);

    const handleNavigate = (view: string) => {
        setCurrentView(view);
        setActiveAlertId(null);
        setIsMobileMenuOpen(false); // Close mobile drawer on navigation
    };

    const renderView = () => {
        if (activeAlert) {
            return (
                <AlertDetailsView
                    alert={activeAlert}
                    onBack={() => setActiveAlertId(null)}
                />
            );
        }

        switch (currentView) {
            case 'saved':
                return (
                    <SavedView
                        onSelectAlert={setActiveAlertId}
                        allAlerts={allAlerts}
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
                    />
                );
            case 'feed':
            default:
                return (
                    <FeedView
                        searchQuery={searchQuery}
                        onSelectAlert={setActiveAlertId}
                        allAlerts={allAlerts}
                        latestFeedUpdatedAt={latestFeedUpdatedAt}
                        status={filters.status}
                        pagination={pagination}
                    />
                );
        }
    };

    const getBreadcrumbTitle = () => {
        if (activeAlert) return 'Alert Details';
        switch (currentView) {
            case 'saved':
                return 'Saved Alerts';
            case 'zones':
                return 'Active Zones';
            case 'settings':
                return 'Settings';
            case 'inbox':
                return 'Notification Center';
            default:
                return 'Live Feed';
        }
    };

    const getBreadcrumbIcon = () => {
        if (activeAlert) return 'info';
        switch (currentView) {
            case 'saved':
                return 'bookmark';
            case 'zones':
                return 'map';
            case 'settings':
                return 'settings';
            case 'inbox':
                return 'notifications';
            default:
                return 'dashboard';
        }
    };

    return (
        <div className="gta-alerts-theme relative flex h-screen w-full overflow-hidden bg-background-dark font-display text-white">
            {/* Mobile Sidebar Overlay/Backdrop */}
            {isMobileMenuOpen && (
                <div
                    className="fixed inset-0 z-[90] bg-black/60 backdrop-blur-sm transition-opacity duration-300 md:hidden"
                    onClick={() => setIsMobileMenuOpen(false)}
                />
            )}

            {/* Unified Sidebar (handles mobile drawer and desktop collapse) */}
            <Sidebar
                currentView={activeAlert ? '' : currentView}
                onNavigate={handleNavigate}
                isCollapsed={isSidebarCollapsed}
                onToggleCollapse={() =>
                    setIsSidebarCollapsed(!isSidebarCollapsed)
                }
                isMobileOpen={isMobileMenuOpen}
                onCloseMobile={() => setIsMobileMenuOpen(false)}
            />

            <div className="relative flex h-full min-w-0 flex-1 flex-col">
                <header className="glass-header z-50 flex-none border-b border-white/5 md:bg-background-dark/95 md:backdrop-blur-md">
                    <div className="w-full md:mx-auto md:max-w-7xl">
                        <div className="flex items-center justify-between px-4 pt-6 pb-2 md:hidden">
                            <div className="flex items-center gap-3">
                                <button
                                    onClick={() => setIsMobileMenuOpen(true)}
                                    className="flex h-10 w-10 items-center justify-center rounded-lg bg-white/5 text-white/80 transition-colors hover:text-primary"
                                    aria-label="Open menu"
                                >
                                    <Icon
                                        name="menu"
                                        style={{ fontSize: '24px' }}
                                    />
                                </button>
                                <h2 className="text-xl leading-tight font-bold tracking-tight text-white">
                                    GTA Alerts
                                </h2>
                            </div>
                            <div className="flex gap-3">
                                <button
                                    onClick={() => handleNavigate('inbox')}
                                    className="text-white/80 transition-colors hover:text-primary"
                                    aria-label="Open notification center"
                                >
                                    <Icon
                                        name="notifications"
                                        style={{ fontSize: '24px' }}
                                    />
                                </button>
                                <button
                                    onClick={() => handleNavigate('settings')}
                                    className="text-white/80 transition-colors hover:text-primary"
                                    aria-label="Open settings"
                                >
                                    <Icon
                                        name="settings"
                                        style={{ fontSize: '24px' }}
                                    />
                                </button>
                            </div>
                        </div>

                        <div className="flex items-center gap-4 px-4 pt-1 pb-4 md:py-4">
                            <div className="mr-4 hidden min-w-max items-center text-sm text-text-secondary md:flex">
                                <Icon
                                    name={getBreadcrumbIcon()}
                                    className="mr-2 text-primary"
                                />
                                <span className="font-medium text-white">
                                    {getBreadcrumbTitle()}
                                </span>
                                <Icon
                                    name="chevron_right"
                                    className="mx-2 text-lg text-white/20"
                                />
                                <span className="opacity-60">
                                    Greater Toronto Area
                                </span>
                            </div>

                            {!activeAlert && (
                                <label className="flex w-full max-w-2xl flex-col">
                                    <div className="group relative flex h-10 w-full flex-1 items-stretch rounded-lg transition-all md:h-11">
                                        <div className="pointer-events-none absolute top-0 left-0 z-10 flex h-full items-center pl-4">
                                            <span className="text-text-secondary transition-colors group-focus-within:text-primary">
                                                <Icon name="search" />
                                            </span>
                                        </div>
                                        <input
                                            className="flex h-full w-full min-w-0 flex-1 resize-none overflow-hidden rounded-lg border border-transparent bg-input-dark px-4 pl-12 text-sm leading-normal font-normal text-white shadow-inner transition-all placeholder:text-text-secondary focus:border-primary/50 focus:ring-2 focus:ring-primary/50 focus:outline-none md:text-base"
                                            placeholder="Search alerts, streets, or categories..."
                                            value={searchQuery}
                                            onChange={(e) =>
                                                setSearchQuery(e.target.value)
                                            }
                                        />
                                    </div>
                                </label>
                            )}

                            <div className="ml-auto hidden items-center gap-3 md:flex">
                                <div className="mx-1 h-6 w-px bg-white/10"></div>
                                <button
                                    className="relative flex h-10 w-10 items-center justify-center rounded-full border border-white/5 bg-surface-dark text-text-secondary transition-all hover:border-primary/30 hover:bg-white/5 hover:text-white"
                                    onClick={() => handleNavigate('inbox')}
                                    aria-label="Open notification center"
                                >
                                    <Icon name="notifications" />
                                    <span className="absolute top-2 right-2.5 h-2 w-2 rounded-full border-2 border-surface-dark bg-amber"></span>
                                </button>
                            </div>
                        </div>
                    </div>
                </header>

                <main className="no-scrollbar relative flex-1 overflow-y-auto scroll-smooth p-0 pb-6">
                    <div className="h-full w-full md:mx-auto md:max-w-7xl">
                        {renderView()}
                    </div>
                </main>

                <BottomNav
                    currentView={activeAlert ? '' : currentView}
                    onNavigate={handleNavigate}
                />
            </div>
            <NotificationToastLayer authUserId={authUserId} />
        </div>
    );
};

export default App;
