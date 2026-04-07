import { Link, router } from '@inertiajs/react';
import React, {
    useCallback,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import { formatTimeAgo } from '@/lib/utils';
import { home } from '@/routes';
import type { UnifiedAlertResource } from '../domain/alerts';
import { useFilterPresets } from '../hooks/useFilterPresets';
import type { FilterPresetParams } from '../hooks/useFilterPresets';
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
    sort?: 'asc' | 'desc';
    source?: string | null;
    since?: string | null;
    savedIds: Set<string>;
    isPending: (id: string) => boolean;
    onToggleSave: (id: string) => Promise<void>;
    /** Minimal mode hidden sections state */
    hiddenSections?: {
        status: boolean;
        category: boolean;
        filter: boolean;
    };
}

export const FeedView: React.FC<FeedViewProps> = ({
    searchQuery,
    onSelectAlert,
    initialAlerts,
    initialNextCursor,
    latestFeedUpdatedAt,
    status = 'all',
    sort = 'desc',
    source = null,
    since = null,
    savedIds,
    isPending,
    onToggleSave,
    hiddenSections = { status: false, category: false, filter: false },
}) => {
    // State for Filters
    const [viewMode, setViewMode] = useState<'feed' | 'table'>('feed');

    // Filter presets
    const currentPresetParams: FilterPresetParams = useMemo(
        () => ({
            status: status ?? 'all',
            source: source ?? null,
            q: searchQuery || null,
            since: since ?? null,
        }),
        [status, source, searchQuery, since],
    );

    const {
        presets,
        savePreset,
        deletePreset,
        applyPreset,
        isPresetActive,
        hasNonDefaultFilters,
        maxPresetsReached,
    } = useFilterPresets({
        currentParams: currentPresetParams,
        currentSort: sort,
    });

    const [isSavingPreset, setIsSavingPreset] = useState(false);
    const [presetNameInput, setPresetNameInput] = useState('');
    const presetInputRef = useRef<HTMLInputElement>(null);

    const handleStartSavePreset = useCallback(() => {
        setIsSavingPreset(true);
        setTimeout(() => presetInputRef.current?.focus(), 0);
    }, []);

    const handleConfirmSavePreset = useCallback(() => {
        const trimmed = presetNameInput.trim();
        if (trimmed.length === 0) return;
        savePreset(trimmed, currentPresetParams);
        setPresetNameInput('');
        setIsSavingPreset(false);
    }, [presetNameInput, savePreset, currentPresetParams]);

    const handleCancelSavePreset = useCallback(() => {
        setPresetNameInput('');
        setIsSavingPreset(false);
    }, []);

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
            sort,
            source,
            q: searchQuery,
            since,
        },
        apiUrl: '/api/feed',
        rootMargin: '300px',
    });

    const activeCategory = source || 'all';
    const sortQueryValue = sort === 'asc' ? sort : null;

    // Handler for Reset
    const handleReset = () => {
        if (isFilterLoading) return;
        router.get(
            home({
                query: {
                    status: status === 'all' ? null : status,
                    sort: null,
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

    const handleSortToggle = () => {
        if (isFilterLoading) return;
        const nextSort = sort === 'asc' ? 'desc' : 'asc';

        router.get(
            home({
                query: {
                    status: status === 'all' ? null : status,
                    sort: nextSort === 'asc' ? nextSort : null,
                    source: source ?? null,
                    q: searchQuery || null,
                    since: since ?? null,
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
        { id: 'all', label: 'All Alerts', icon: 'feed' },
        { id: 'fire', label: 'Fire', icon: 'local_fire_department' },
        { id: 'police', label: 'Police', icon: 'local_police' },
        { id: 'transit', label: 'TTC', icon: 'train' },
        { id: 'go_transit', label: 'GO Transit', icon: 'directions_bus' },
        { id: 'miway', label: 'MiWay', icon: 'directions_bus' },
        { id: 'yrt', label: 'YRT', icon: 'directions_bus' },
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
        <section id="gta-alerts-feed-view" className="flex h-full flex-col">
            {/* Sticky Header: Filters */}
            <div
                id="gta-alerts-feed-controls"
                className="sticky top-0 z-30 border-b border-[#333333] bg-background-dark"
            >
                {/* Row 0: Status */}
                <div
                    id="gta-alerts-feed-status-row"
                    className={`bg-background-dark px-4 transition-all duration-300 ease-in-out md:px-6 ${
                        hiddenSections.status
                            ? 'h-0 overflow-hidden border-transparent py-0 opacity-0'
                            : 'border-b border-[#333333] py-2 opacity-100'
                    }`}
                >
                    <div
                        id="gta-alerts-feed-status-row-content"
                        className="flex items-center gap-2"
                    >
                        <span className="mr-1 text-[10px] font-bold tracking-widest text-text-secondary/70 uppercase">
                            Status
                        </span>
                        <div
                            id="gta-alerts-feed-status-links"
                            className="flex gap-0"
                        >
                            {statusOptions.map((opt) => (
                                <Link
                                    id={`gta-alerts-feed-status-link-${opt.id}`}
                                    key={opt.id}
                                    href={
                                        home({
                                            query: {
                                                status:
                                                    opt.id === 'all'
                                                        ? null
                                                        : opt.id,
                                                sort: sortQueryValue,
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
                                    className={`flex items-center gap-1.5 border-b-2 px-3 py-1.5 text-[11px] font-bold whitespace-nowrap transition-colors ${
                                        status === opt.id
                                            ? 'border-[#FF7F00] text-white'
                                            : 'border-transparent text-text-secondary hover:border-text-secondary/40 hover:text-white'
                                    } ${isFilterLoading ? 'pointer-events-none opacity-50' : ''}`}
                                >
                                    <Icon name={opt.icon} className="text-sm" />
                                    {opt.label}
                                </Link>
                            ))}
                        </div>
                        <span className="ml-auto hidden rounded-full border border-[#333333] bg-background-dark px-2.5 py-1 text-[10px] text-text-secondary sm:inline">
                            {totalCount} loaded
                        </span>
                    </div>
                </div>

                {/* Row 1: Categories */}
                <div
                    id="gta-alerts-feed-category-row"
                    className={`bg-background-dark px-4 transition-all duration-300 ease-in-out md:px-6 ${
                        hiddenSections.category
                            ? 'h-0 overflow-hidden border-transparent py-0 opacity-0'
                            : 'border-b border-[#333333] py-3 opacity-100'
                    }`}
                >
                    <div
                        id="gta-alerts-feed-category-links"
                        className="no-scrollbar mask-linear-fade flex w-full justify-start gap-0 overflow-x-auto"
                    >
                        {categories.map((cat) => {
                            const isSelected = activeCategory === cat.id;
                            const nextSource = isSelected
                                ? null
                                : cat.id === 'all'
                                  ? null
                                  : cat.id;

                            return (
                                <Link
                                    id={`gta-alerts-feed-category-link-${cat.id}`}
                                    key={cat.id}
                                    href={
                                        home({
                                            query: {
                                                status:
                                                    status === 'all'
                                                        ? null
                                                        : status,
                                                sort: sortQueryValue,
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
                                    className={`flex items-center gap-2 border-b-2 px-3 py-2 text-xs whitespace-nowrap transition-colors md:px-4 ${
                                        isSelected
                                            ? 'border-[#FF7F00] font-semibold text-white'
                                            : 'border-transparent font-medium text-text-secondary hover:border-text-secondary/40 hover:text-white'
                                    } ${isFilterLoading ? 'pointer-events-none opacity-50' : ''}`}
                                >
                                    <Icon name={cat.icon} className="text-lg" />
                                    {cat.label}
                                </Link>
                            );
                        })}
                    </div>
                </div>

                {/* Row 1.5: Filter Preset Chips */}
                {!hiddenSections.filter &&
                    (presets.length > 0 || hasNonDefaultFilters) && (
                        <div
                            id="gta-alerts-feed-preset-row"
                            className="flex items-center gap-2 border-b border-[#333333] bg-background-dark px-4 py-2 md:px-6"
                        >
                            <span className="mr-1 text-[10px] font-bold tracking-widest text-text-secondary/70 uppercase">
                                Presets
                            </span>
                            <div
                                id="gta-alerts-feed-preset-chips"
                                className="no-scrollbar flex items-center gap-2 overflow-x-auto"
                            >
                                {presets.map((preset) => (
                                    <div
                                        key={preset.id}
                                        id={`gta-alerts-feed-preset-chip-${preset.id}`}
                                        className={`group flex shrink-0 items-center gap-1 rounded-full border px-2.5 py-1 text-[11px] font-bold transition-colors ${
                                            isPresetActive(preset.id)
                                                ? 'border-[#FF7F00] bg-[#FF7F00]/15 text-white'
                                                : 'border-[#333333] bg-[#1a1a1a] text-text-secondary hover:border-primary hover:text-white'
                                        }`}
                                    >
                                        <button
                                            type="button"
                                            onClick={() =>
                                                applyPreset(preset.id)
                                            }
                                            disabled={isFilterLoading}
                                            className="whitespace-nowrap disabled:cursor-not-allowed disabled:opacity-50"
                                            aria-label={`Apply preset: ${preset.name}`}
                                        >
                                            {preset.name}
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() =>
                                                deletePreset(preset.id)
                                            }
                                            className="ml-0.5 flex h-4 w-4 items-center justify-center rounded-full text-text-secondary/50 transition-colors hover:bg-white/10 hover:text-critical"
                                            aria-label={`Delete preset: ${preset.name}`}
                                        >
                                            <Icon
                                                name="close"
                                                className="text-[10px]"
                                            />
                                        </button>
                                    </div>
                                ))}

                                {/* Save Preset Button / Inline Input */}
                                {hasNonDefaultFilters &&
                                    !maxPresetsReached &&
                                    (isSavingPreset ? (
                                        <div className="flex shrink-0 items-center gap-1">
                                            <input
                                                ref={presetInputRef}
                                                id="gta-alerts-feed-preset-name-input"
                                                type="text"
                                                value={presetNameInput}
                                                onChange={(e) =>
                                                    setPresetNameInput(
                                                        e.target.value,
                                                    )
                                                }
                                                onKeyDown={(e) => {
                                                    if (e.key === 'Enter')
                                                        handleConfirmSavePreset();
                                                    if (e.key === 'Escape')
                                                        handleCancelSavePreset();
                                                }}
                                                placeholder="Preset name..."
                                                maxLength={30}
                                                className="h-6 w-28 rounded border border-[#333333] bg-[#1a1a1a] px-2 text-[11px] text-white placeholder:text-text-secondary/50 focus:border-primary focus:outline-none"
                                            />
                                            <button
                                                type="button"
                                                onClick={
                                                    handleConfirmSavePreset
                                                }
                                                className="flex h-5 w-5 items-center justify-center rounded-full bg-primary text-black transition-colors hover:bg-white"
                                                aria-label="Confirm save preset"
                                            >
                                                <Icon
                                                    name="check"
                                                    className="text-[12px]"
                                                />
                                            </button>
                                            <button
                                                type="button"
                                                onClick={handleCancelSavePreset}
                                                className="flex h-5 w-5 items-center justify-center rounded-full border border-[#333333] text-text-secondary transition-colors hover:border-critical hover:text-critical"
                                                aria-label="Cancel save preset"
                                            >
                                                <Icon
                                                    name="close"
                                                    className="text-[10px]"
                                                />
                                            </button>
                                        </div>
                                    ) : (
                                        <button
                                            id="gta-alerts-feed-preset-save-btn"
                                            type="button"
                                            onClick={handleStartSavePreset}
                                            disabled={isFilterLoading}
                                            className="flex shrink-0 items-center gap-1 rounded-full border border-dashed border-[#333333] px-2.5 py-1 text-[11px] font-bold text-text-secondary/70 transition-colors hover:border-primary hover:text-primary disabled:cursor-not-allowed disabled:opacity-50"
                                        >
                                            <Icon
                                                name="add"
                                                className="text-sm"
                                            />
                                            Save
                                        </button>
                                    ))}
                            </div>
                        </div>
                    )}

                {/* Loading Indicator Row */}
                {isFilterLoading && (
                    <div
                        id="gta-alerts-feed-loading-row"
                        className="flex items-center gap-2 border-b border-[#333333] bg-[#FF7F00]/15 px-4 py-1.5 md:px-6"
                    >
                        <span className="flex h-3 w-3 animate-pulse rounded-full bg-[#FF7F00]"></span>
                        <span className="text-[11px] font-medium text-primary">
                            Updating feed...
                        </span>
                    </div>
                )}

                {/* Row 2: Time Window + View Toggle */}
                <div
                    id="gta-alerts-feed-filter-row"
                    className={`flex flex-wrap items-center gap-3 border-b bg-background-dark px-4 py-2 transition-all duration-300 ease-in-out md:px-6 ${
                        hiddenSections.filter
                            ? 'h-0 overflow-hidden border-transparent py-0 opacity-0'
                            : 'border-[#333333] opacity-100'
                    }`}
                >
                    <div
                        id="gta-alerts-feed-filter-row-content"
                        className="flex items-center gap-3"
                    >
                        {/* Time Window Selector */}
                        <div
                            id="gta-alerts-feed-since-filter"
                            className="group relative"
                        >
                            <div className="pointer-events-none absolute inset-y-0 left-2 flex items-center text-text-secondary">
                                <Icon name="schedule" className="text-sm" />
                            </div>
                            <select
                                id="gta-alerts-feed-since-select"
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
                                                sort: sortQueryValue,
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
                                className="w-36 cursor-pointer appearance-none rounded-lg border border-[#333333] bg-background-dark py-1.5 pr-8 pl-8 text-xs text-white transition-colors outline-none hover:border-primary focus:border-primary focus:ring-1 focus:ring-primary disabled:cursor-not-allowed disabled:opacity-50"
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
                        {(since !== null ||
                            activeCategory !== 'all' ||
                            sort === 'asc') && (
                            <button
                                id="gta-alerts-feed-reset-btn"
                                onClick={handleReset}
                                disabled={isFilterLoading}
                                className="flex items-center gap-1 rounded px-2 py-1 text-xs font-medium text-primary transition-colors hover:bg-[#FF7F00] hover:text-black disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                <Icon name="restart_alt" className="text-sm" />
                                Reset
                            </button>
                        )}

                        <button
                            id="gta-alerts-feed-sort-direction-btn"
                            onClick={handleSortToggle}
                            disabled={isFilterLoading}
                            aria-label={
                                sort === 'asc'
                                    ? 'Switch to newest first'
                                    : 'Switch to oldest first'
                            }
                            className={`flex items-center gap-1.5 rounded border border-[#333333] bg-background-dark px-2.5 py-1.5 text-xs font-medium transition-colors hover:border-primary hover:text-primary disabled:cursor-not-allowed disabled:opacity-50 ${
                                sort === 'asc' ? 'text-primary' : 'text-white'
                            }`}
                        >
                            <Icon
                                name={
                                    sort === 'asc'
                                        ? 'arrow_upward'
                                        : 'arrow_downward'
                                }
                                className="text-sm"
                            />
                            {sort === 'asc' ? 'Oldest' : 'Newest'}
                        </button>

                        {/* View Mode Toggle */}
                        <button
                            id="gta-alerts-feed-view-toggle"
                            onClick={() =>
                                setViewMode(
                                    viewMode === 'feed' ? 'table' : 'feed',
                                )
                            }
                            aria-label={
                                viewMode === 'feed'
                                    ? 'Switch to table view'
                                    : 'Switch to feed view'
                            }
                            className="relative flex items-center gap-1.5 rounded border border-[#333333] bg-background-dark px-2.5 py-1.5 text-xs font-medium text-white transition-colors hover:border-primary hover:text-primary"
                        >
                            <Icon
                                name={
                                    viewMode === 'feed'
                                        ? 'view_agenda'
                                        : 'table_rows'
                                }
                                className="text-sm"
                            />
                            {viewMode === 'feed' ? 'Feed' : 'Table'}
                            <span className="absolute inset-x-0 bottom-0 h-0.5 rounded-full bg-[#FF7F00]" />
                        </button>
                    </div>
                </div>
            </div>

            {/* List Container */}
            <div
                id="gta-alerts-feed-list-wrap"
                className={`relative w-full flex-1 overflow-y-auto p-4 md:p-6 ${isFilterLoading ? 'opacity-50' : 'opacity-100'} transition-opacity duration-200`}
            >
                {/* Loading Overlay */}
                {isFilterLoading && (
                    <div
                        id="gta-alerts-feed-loading-overlay"
                        className="absolute inset-0 z-10 flex items-start justify-center pt-20"
                    >
                        <div className="flex items-center gap-2 rounded-lg border border-[#333333] bg-background-dark px-4 py-3 shadow-lg backdrop-blur-sm">
                            <span className="flex h-4 w-4 animate-spin rounded-full border-2 border-[#333333] border-t-primary"></span>
                            <span className="text-sm font-medium text-white">
                                Loading alerts...
                            </span>
                        </div>
                    </div>
                )}
                <div
                    id="gta-alerts-feed-list"
                    className="flex w-full flex-col gap-4 md:gap-5"
                >
                    {latestFeedUpdatedAt && (
                        <div
                            id="gta-alerts-feed-updated-bar"
                            className="mb-2 flex items-center justify-between px-1"
                        >
                            <div className="flex items-center gap-2 text-[10px] font-bold tracking-widest text-primary uppercase">
                                <span className="flex h-1.5 w-1.5 animate-pulse rounded-full bg-[#FF7F00]"></span>
                                Live Feed Active
                            </div>
                            <div className="flex items-center gap-1.5 rounded-full border border-[#333333] bg-black px-2.5 py-1 text-[10px] text-text-secondary">
                                <Icon
                                    name="history"
                                    className="text-sm opacity-50"
                                />
                                Updated: {formatTimeAgo(latestFeedUpdatedAt)}
                            </div>
                        </div>
                    )}

                    {viewMode === 'feed' ? (
                        allAlerts.map((item) => (
                            <AlertCard
                                key={item.id}
                                alert={item}
                                onViewDetails={() => onSelectAlert(item.id)}
                                isSaved={savedIds.has(item.id)}
                                isPending={isPending(item.id)}
                                onToggleSave={() => onToggleSave(item.id)}
                            />
                        ))
                    ) : (
                        <AlertTableView
                            items={allAlerts}
                            onSelectAlert={onSelectAlert}
                            savedIds={savedIds}
                            isPending={isPending}
                            onToggleSave={onToggleSave}
                        />
                    )}

                    {/* Infinite Scroll Sentinel */}
                    {allAlerts.length > 0 && (
                        <div
                            id="gta-alerts-feed-sentinel"
                            ref={sentinelRef}
                            className="flex h-20 items-center justify-center"
                        >
                            {isLoadingMore && (
                                <div className="flex items-center gap-2 text-text-secondary">
                                    <span className="flex h-4 w-4 animate-spin rounded-full border-2 border-[#333333] border-t-primary"></span>
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
                                    <span className="text-sm text-critical">
                                        {loadMoreError}
                                    </span>
                                    <button
                                        id="gta-alerts-feed-reload-btn"
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
                        <div className="mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-[#333333]/30">
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
                            id="gta-alerts-feed-reset-all-btn"
                            onClick={() =>
                                router.get(
                                    home({
                                        query: {
                                            status: null,
                                            sort: null,
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
                            className="rounded-lg bg-primary px-6 py-2 text-sm font-bold text-black shadow-lg shadow-primary/20 transition-colors hover:bg-primary/90"
                        >
                            Reset All Filters
                        </button>
                    </div>
                )}
            </div>
        </section>
    );
};
