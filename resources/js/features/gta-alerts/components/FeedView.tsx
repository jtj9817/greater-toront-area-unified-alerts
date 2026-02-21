import { Link, router } from '@inertiajs/react';
import React, { useEffect, useMemo, useState } from 'react';
import { formatTimeAgo } from '@/lib/utils';
import { home } from '@/routes';
import type { UnifiedAlertResource } from '../domain/alerts';
import { useInfiniteScroll } from '../hooks/useInfiniteScroll';
import { AlertCard } from './AlertCard';
import { AlertTableView } from './AlertTableView';
import { Icon } from './Icon';

interface FeedViewProps {
    searchQuery: string;
    onSelectAlert: (id: string) => void;
    initialAlerts: UnifiedAlertResource[];
    initialNextCursor: string | null;
    latestFeedUpdatedAt: string | null;
    status?: 'all' | 'active' | 'cleared';
    source?: string | null;
    since?: string | null;
}

export const FeedView: React.FC<FeedViewProps> = ({
    searchQuery,
    onSelectAlert,
    initialAlerts,
    initialNextCursor,
    latestFeedUpdatedAt,
    status = 'all',
    source = null,
    since = null,
}) => {
    // State for Filters
    const [viewMode, setViewMode] = useState<'cards' | 'table'>('cards');

    // Loading state detection from Inertia router events
    const [isFilterLoading, setIsFilterLoading] = useState(false);

    useEffect(() => {
        const removeStartListener = router.on('start', (event) => {
            // Only show loading if we are reloading alerts or filters
            if (
                event.detail.visit.only?.includes('alerts') ||
                event.detail.visit.only?.includes('filters')
            ) {
                setIsFilterLoading(true);
            }
        });
        const removeFinishListener = router.on('finish', () => {
            setIsFilterLoading(false);
        });

        return () => {
            removeStartListener();
            removeFinishListener();
        };
    }, []);

    // Infinite scroll hook
    const {
        alerts: allAlerts,
        isLoading: isLoadingMore,
        error: loadMoreError,
        hasMore,
        sentinelRef,
    } = useInfiniteScroll({
        initialAlerts,
        initialNextCursor,
        filters: {
            status,
            source,
            q: searchQuery,
            since,
        },
        apiUrl: '/api/feed',
        rootMargin: '300px',
    });

    const activeCategory = source || 'all';

    // Get Set of Saved IDs for efficient lookup
    const savedIds = useMemo(() => new Set<string>([]), []); // Temporary empty until saved logic implemented

    // Handler for Reset
    const handleReset = () => {
        if (isFilterLoading) return;
        router.get(
            home({
                query: {
                    status: status === 'all' ? null : status,
                    source: null,
                    q: searchQuery || null,
                    since: null,
                },
            }).url,
            {},
            {
                preserveScroll: true,
                preserveState: true,
                replace: true,
                only: ['alerts', 'filters'],
            },
        );
    };

    const categories = [
        { id: 'all', label: 'All Alerts', icon: 'grid_view' },
        { id: 'fire', label: 'Fire', icon: 'local_fire_department' },
        { id: 'police', label: 'Police', icon: 'local_police' },
        { id: 'transit', label: 'TTC', icon: 'train' },
        { id: 'go_transit', label: 'GO Transit', icon: 'directions_bus' },
    ];

    const sinceOptions = [
        { label: 'All time', value: 'all' },
        { label: 'Last 30m', value: '30m' },
        { label: 'Last 1h', value: '1h' },
        { label: 'Last 3h', value: '3h' },
        { label: 'Last 6h', value: '6h' },
        { label: 'Last 12h', value: '12h' },
    ];

    const statusOptions: Array<{
        id: 'all' | 'active' | 'cleared';
        label: string;
        icon: string;
    }> = [
        { id: 'all', label: 'All', icon: 'history' },
        { id: 'active', label: 'Active', icon: 'play_circle' },
        { id: 'cleared', label: 'Cleared', icon: 'check_circle' },
    ];

    const totalCount = allAlerts.length;

    return (
        <div className="flex h-full flex-col">
            {/* Sticky Header: Filters */}
            <div className="sticky top-0 z-30 border-b border-white/5 bg-background-dark/95 shadow-lg backdrop-blur-md">
                {/* Row 0: Status */}
                <div className="border-b border-white/5 bg-surface-dark/20 px-4 py-2 md:px-6">
                    <div className="flex items-center gap-2">
                        <span className="mr-1 text-[10px] font-bold tracking-widest text-text-secondary/70 uppercase">
                            Status
                        </span>
                        <div className="flex gap-2">
                            {statusOptions.map((opt) => (
                                <Link
                                    key={opt.id}
                                    href={
                                        home({
                                            query: {
                                                status:
                                                    opt.id === 'all'
                                                        ? null
                                                        : opt.id,
                                                source: source ?? null,
                                                q: searchQuery || null,
                                                since: since ?? null,
                                            },
                                        }).url
                                    }
                                    preserveScroll
                                    preserveState
                                    only={['alerts', 'filters']}
                                    disabled={isFilterLoading}
                                    className={`flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-[11px] font-bold whitespace-nowrap transition-all ${
                                        status === opt.id
                                            ? 'border-white/20 bg-white/10 text-white'
                                            : 'border-white/10 bg-transparent text-text-secondary hover:border-white/20 hover:bg-white/5 hover:text-white'
                                    } ${isFilterLoading ? 'pointer-events-none opacity-50' : ''}`}
                                >
                                    <Icon name={opt.icon} className="text-sm" />
                                    {opt.label}
                                </Link>
                            ))}
                        </div>
                        <span className="ml-auto rounded-full border border-white/5 bg-white/5 px-2.5 py-1 text-[10px] text-text-secondary">
                            {totalCount} loaded
                        </span>
                    </div>
                </div>

                {/* Row 1: Categories */}
                <div className="border-b border-white/5 px-4 py-3 md:px-6">
                    <div className="no-scrollbar mask-linear-fade flex w-full justify-start gap-2 overflow-x-auto pb-1">
                        {categories.map((cat) => {
                            const isSelected = activeCategory === cat.id;
                            const nextSource = isSelected
                                ? null
                                : cat.id === 'all'
                                  ? null
                                  : cat.id;

                            return (
                                <Link
                                    key={cat.id}
                                    href={
                                        home({
                                            query: {
                                                status:
                                                    status === 'all'
                                                        ? null
                                                        : status,
                                                source: nextSource,
                                                q: searchQuery || null,
                                                since: since ?? null,
                                            },
                                        }).url
                                    }
                                    preserveScroll
                                    preserveState
                                    only={['alerts', 'filters']}
                                    disabled={isFilterLoading}
                                    className={`flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-medium whitespace-nowrap transition-all md:px-4 md:py-2 ${
                                        isSelected
                                            ? 'border-white/20 bg-white/10 text-white shadow-lg'
                                            : 'border-white/5 bg-surface-dark text-text-secondary hover:border-white/20 hover:bg-white/5 hover:text-white'
                                    } ${isFilterLoading ? 'pointer-events-none opacity-50' : ''}`}
                                >
                                    <Icon name={cat.icon} className="text-lg" />
                                    {cat.label}
                                </Link>
                            );
                        })}
                    </div>
                </div>

                {/* Loading Indicator Row */}
                {isFilterLoading && (
                    <div className="flex items-center gap-2 border-b border-white/5 bg-primary/10 px-4 py-1.5 md:px-6">
                        <span className="flex h-3 w-3 animate-pulse rounded-full bg-primary"></span>
                        <span className="text-[11px] font-medium text-primary">
                            Updating feed...
                        </span>
                    </div>
                )}

                {/* Row 2: Time Window + View Toggle */}
                <div className="flex flex-wrap items-center gap-3 bg-surface-dark/30 px-4 py-2 md:px-6">
                    <div className="flex items-center gap-3">
                        {/* Time Window Selector */}
                        <div className="group relative">
                            <div className="pointer-events-none absolute inset-y-0 left-2 flex items-center text-text-secondary">
                                <Icon name="schedule" className="text-sm" />
                            </div>
                            <select
                                value={since ?? 'all'}
                                disabled={isFilterLoading}
                                onChange={(e) =>
                                    router.get(
                                        home({
                                            query: {
                                                status:
                                                    status === 'all'
                                                        ? null
                                                        : status,
                                                source: source ?? null,
                                                q: searchQuery || null,
                                                since:
                                                    e.target.value === 'all'
                                                        ? null
                                                        : e.target.value,
                                            },
                                        }).url,
                                        {},
                                        {
                                            preserveScroll: true,
                                            preserveState: true,
                                            replace: true,
                                            only: ['alerts', 'filters'],
                                        },
                                    )
                                }
                                className="w-36 cursor-pointer appearance-none rounded-lg border border-white/10 bg-surface-dark py-1.5 pr-8 pl-8 text-xs text-white transition-colors outline-none hover:border-white/20 focus:border-primary focus:ring-1 focus:ring-primary disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {sinceOptions.map((opt) => (
                                    <option
                                        key={String(opt.value)}
                                        value={String(opt.value)}
                                    >
                                        {opt.label}
                                    </option>
                                ))}
                            </select>
                            <div className="pointer-events-none absolute inset-y-0 right-2 flex items-center text-text-secondary">
                                <Icon name="expand_more" className="text-sm" />
                            </div>
                        </div>
                    </div>

                    <div className="ml-auto flex items-center gap-3">
                        {/* Reset Button (Only shows if filters are active) */}
                        {(since !== null || activeCategory !== 'all') && (
                            <button
                                onClick={handleReset}
                                disabled={isFilterLoading}
                                className="flex items-center gap-1 rounded px-2 py-1 text-xs font-medium text-coral transition-colors hover:bg-coral/10 hover:text-amber disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                <Icon name="restart_alt" className="text-sm" />
                                Reset
                            </button>
                        )}

                        {/* View Mode Toggle */}
                        <div className="flex items-center rounded-lg border border-white/10 bg-surface-dark">
                            <button
                                onClick={() => setViewMode('cards')}
                                className={`flex items-center gap-1.5 rounded-l-lg px-3 py-1.5 text-xs font-medium transition-all ${
                                    viewMode === 'cards'
                                        ? 'bg-white/10 text-white'
                                        : 'text-text-secondary hover:text-white'
                                }`}
                            >
                                <Icon name="grid_view" className="text-sm" />
                                Cards
                            </button>
                            <button
                                onClick={() => setViewMode('table')}
                                className={`flex items-center gap-1.5 rounded-r-lg px-3 py-1.5 text-xs font-medium transition-all ${
                                    viewMode === 'table'
                                        ? 'bg-white/10 text-white'
                                        : 'text-text-secondary hover:text-white'
                                }`}
                            >
                                <Icon name="table_rows" className="text-sm" />
                                Table
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            {/* List Container */}
            <div
                className={`relative flex-1 overflow-y-auto p-4 md:p-6 ${isFilterLoading ? 'opacity-50' : 'opacity-100'} transition-opacity duration-200`}
            >
                {/* Loading Overlay */}
                {isFilterLoading && (
                    <div className="absolute inset-0 z-10 flex items-start justify-center pt-20">
                        <div className="flex items-center gap-2 rounded-lg border border-white/10 bg-surface-dark/90 px-4 py-3 shadow-lg backdrop-blur-sm">
                            <span className="flex h-4 w-4 animate-spin rounded-full border-2 border-white/20 border-t-primary"></span>
                            <span className="text-sm font-medium text-white">
                                Loading alerts...
                            </span>
                        </div>
                    </div>
                )}
                <div className="mx-auto flex w-full max-w-5xl flex-col gap-4 md:gap-5">
                    {latestFeedUpdatedAt && (
                        <div className="mb-2 flex items-center justify-between px-1">
                            <div className="flex items-center gap-2 text-[10px] font-bold tracking-widest text-text-secondary uppercase">
                                <span className="flex h-1.5 w-1.5 animate-pulse rounded-full bg-forest"></span>
                                Live Feed Active
                            </div>
                            <div className="flex items-center gap-1.5 rounded-full border border-white/5 bg-white/5 px-2.5 py-1 text-[10px] text-text-secondary">
                                <Icon
                                    name="history"
                                    className="text-sm opacity-50"
                                />
                                Updated: {formatTimeAgo(latestFeedUpdatedAt)}
                            </div>
                        </div>
                    )}

                    {viewMode === 'cards' ? (
                        allAlerts.map((item) => (
                            <AlertCard
                                key={item.id}
                                alert={item}
                                onViewDetails={() => onSelectAlert(item.id)}
                                isSaved={savedIds.has(item.id)}
                            />
                        ))
                    ) : (
                        <AlertTableView
                            items={allAlerts}
                            onSelectAlert={onSelectAlert}
                            savedIds={savedIds}
                        />
                    )}

                    {/* Infinite Scroll Sentinel */}
                    {allAlerts.length > 0 && (
                        <div
                            ref={sentinelRef}
                            className="flex h-20 items-center justify-center"
                        >
                            {isLoadingMore && (
                                <div className="flex items-center gap-2 text-text-secondary">
                                    <span className="flex h-4 w-4 animate-spin rounded-full border-2 border-white/20 border-t-primary"></span>
                                    <span className="text-sm">
                                        Loading more...
                                    </span>
                                </div>
                            )}
                            {!isLoadingMore && !hasMore && (
                                <span className="text-xs text-text-secondary/50">
                                    No more alerts
                                </span>
                            )}
                            {loadMoreError && (
                                <div className="flex flex-col items-center gap-2">
                                    <span className="text-sm text-coral">
                                        {loadMoreError}
                                    </span>
                                    <button
                                        onClick={() => window.location.reload()}
                                        className="text-xs text-primary hover:underline"
                                    >
                                        Reload page
                                    </button>
                                </div>
                            )}
                        </div>
                    )}
                </div>

                {/* Empty State */}
                {allAlerts.length === 0 && !isFilterLoading && (
                    <div className="flex animate-in flex-col items-center justify-center py-20 text-center duration-300 fade-in zoom-in">
                        <div className="mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-white/5">
                            <Icon
                                name="filter_list_off"
                                className="text-3xl text-text-secondary opacity-30"
                            />
                        </div>
                        <p className="mb-2 text-lg font-bold text-white">
                            No alerts match your filters
                        </p>
                        <p className="mx-auto mb-6 max-w-xs text-sm text-text-secondary">
                            Try adjusting the time range or date selection to
                            see more results.
                        </p>
                        <button
                            onClick={() =>
                                router.get(
                                    home({
                                        query: {
                                            status: null,
                                            source: null,
                                            q: null,
                                            since: null,
                                        },
                                    }).url,
                                    {},
                                    {
                                        preserveScroll: true,
                                        preserveState: true,
                                        replace: true,
                                    },
                                )
                            }
                            className="rounded-lg bg-primary px-6 py-2 text-sm font-bold text-white shadow-lg shadow-primary/20 transition-colors hover:bg-primary/90"
                        >
                            Reset All Filters
                        </button>
                    </div>
                )}
            </div>
        </div>
    );
};
