import { Link } from '@inertiajs/react';
import React, { useState, useMemo } from 'react';
import { formatTimeAgo } from '@/lib/utils';
import { home } from '@/routes';
import type { AlertFilterOptions } from '../services/AlertService';
import { AlertService } from '../services/AlertService';
import type { AlertItem } from '../types';
import { AlertCard } from './AlertCard';
import { AlertTableView } from './AlertTableView';
import { Icon } from './Icon';

type FeedPagination = {
    prevUrl: string | null;
    nextUrl: string | null;
    currentPage: number | null;
    lastPage: number | null;
    total: number | null;
};

interface FeedViewProps {
    searchQuery: string;
    onSelectAlert: (id: string) => void;
    allAlerts: AlertItem[];
    latestFeedUpdatedAt: string | null;
    status?: 'all' | 'active' | 'cleared';
    pagination?: FeedPagination;
}

export const FeedView: React.FC<FeedViewProps> = ({
    searchQuery,
    onSelectAlert,
    allAlerts,
    latestFeedUpdatedAt,
    status = 'all',
    pagination,
}) => {
    // State for Filters
    const [activeCategory, setActiveCategory] = useState<string>('all');
    const [timeFilter, setTimeFilter] = useState<number | null>(null);
    const [dateFilter, setDateFilter] = useState<'today' | 'yesterday' | 'all'>(
        'all',
    ); // Default to 'all' for live data initially
    const [viewMode, setViewMode] = useState<'cards' | 'table'>('cards');

    // Compute filtered items using the Service
    const filteredItems = useMemo(() => {
        const options: AlertFilterOptions = {
            query: searchQuery,
            category: activeCategory,
            timeLimit: timeFilter,
            dateScope: dateFilter,
        };
        return AlertService.search(allAlerts, options);
    }, [searchQuery, activeCategory, timeFilter, dateFilter, allAlerts]);

    // Get Set of Saved IDs for efficient lookup
    const savedIds = useMemo(() => new Set<string>([]), []); // Temporary empty until saved logic implemented

    // Handler for Reset
    const handleReset = () => {
        setActiveCategory('all');
        setTimeFilter(null);
        setDateFilter('today');
    };

    const categories = [
        { id: 'all', label: 'All Alerts', icon: 'grid_view' },
        { id: 'fire', label: 'Fire', icon: 'local_fire_department' },
        { id: 'police', label: 'Police', icon: 'local_police' },
        { id: 'hazard', label: 'Hazard', icon: 'warning' },
        { id: 'transit', label: 'Transit', icon: 'train' },
    ];

    const timeOptions = [
        { label: 'Any time', value: null },
        { label: 'Last 30m', value: 30 },
        { label: 'Last 1h', value: 60 },
        { label: 'Last 3h', value: 180 },
        { label: 'Last 6h', value: 360 },
        { label: 'Last 12h', value: 720 },
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

    const paginationTotal = pagination?.total;

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
                                            },
                                        }).url
                                    }
                                    preserveScroll
                                    preserveState
                                    className={`flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-[11px] font-bold whitespace-nowrap transition-all ${
                                        status === opt.id
                                            ? 'border-white/20 bg-white/10 text-white'
                                            : 'border-white/10 bg-transparent text-text-secondary hover:border-white/20 hover:bg-white/5 hover:text-white'
                                    }`}
                                >
                                    <Icon name={opt.icon} className="text-sm" />
                                    {opt.label}
                                </Link>
                            ))}
                        </div>
                        {typeof paginationTotal === 'number' && (
                            <span className="ml-auto rounded-full border border-white/5 bg-white/5 px-2.5 py-1 text-[10px] text-text-secondary">
                                {paginationTotal} total
                            </span>
                        )}
                    </div>
                </div>

                {/* Row 1: Categories */}
                <div className="border-b border-white/5 px-4 py-3 md:px-6">
                    <div className="no-scrollbar mask-linear-fade flex w-full justify-start gap-2 overflow-x-auto pb-1">
                        {categories.map((cat) => (
                            <button
                                key={cat.id}
                                onClick={() =>
                                    setActiveCategory(
                                        cat.id === activeCategory &&
                                            activeCategory !== 'all'
                                            ? 'all'
                                            : cat.id,
                                    )
                                }
                                className={`flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-medium whitespace-nowrap transition-all md:px-4 md:py-2 ${
                                    activeCategory === cat.id
                                        ? 'border-primary bg-primary text-white shadow-lg shadow-primary/20'
                                        : 'border-white/5 bg-surface-dark text-text-secondary hover:border-white/20 hover:bg-white/5 hover:text-white'
                                }`}
                            >
                                <Icon name={cat.icon} className="text-lg" />
                                {cat.label}
                            </button>
                        ))}
                    </div>
                </div>

                {/* Row 2: Date & Time Selectors + View Toggle */}
                <div className="flex flex-wrap items-center gap-3 bg-surface-dark/30 px-4 py-2 md:px-6">
                    <div className="flex items-center gap-3">
                        {/* Date Selector */}
                        <div className="group relative">
                            <div className="pointer-events-none absolute inset-y-0 left-2 flex items-center text-text-secondary">
                                <Icon
                                    name="calendar_today"
                                    className="text-sm"
                                />
                            </div>
                            <select
                                value={dateFilter}
                                onChange={(e) =>
                                    setDateFilter(
                                        e.target.value as
                                            | 'today'
                                            | 'yesterday'
                                            | 'all',
                                    )
                                }
                                className="w-32 cursor-pointer appearance-none rounded-lg border border-white/10 bg-surface-dark py-1.5 pr-8 pl-8 text-xs text-white transition-colors outline-none hover:border-white/20 focus:border-primary focus:ring-1 focus:ring-primary"
                            >
                                <option value="today">Today</option>
                                <option value="yesterday">Yesterday</option>
                                <option value="all">All Dates</option>
                            </select>
                            <div className="pointer-events-none absolute inset-y-0 right-2 flex items-center text-text-secondary">
                                <Icon name="expand_more" className="text-sm" />
                            </div>
                        </div>

                        {/* Time Selector */}
                        <div className="group relative">
                            <div className="pointer-events-none absolute inset-y-0 left-2 flex items-center text-text-secondary">
                                <Icon name="schedule" className="text-sm" />
                            </div>
                            <select
                                value={
                                    timeFilter === null ? 'null' : timeFilter
                                }
                                onChange={(e) =>
                                    setTimeFilter(
                                        e.target.value === 'null'
                                            ? null
                                            : Number(e.target.value),
                                    )
                                }
                                className="w-36 cursor-pointer appearance-none rounded-lg border border-white/10 bg-surface-dark py-1.5 pr-8 pl-8 text-xs text-white transition-colors outline-none hover:border-white/20 focus:border-primary focus:ring-1 focus:ring-primary"
                            >
                                {timeOptions.map((opt) => (
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
                        {(timeFilter !== null ||
                            activeCategory !== 'all' ||
                            dateFilter !== 'today') && (
                            <button
                                onClick={handleReset}
                                className="flex items-center gap-1 rounded px-2 py-1 text-xs font-medium text-coral transition-colors hover:bg-coral/10 hover:text-amber"
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
                                        ? 'bg-primary text-white'
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
                                        ? 'bg-primary text-white'
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
            <div className="flex-1 overflow-y-auto p-4 md:p-6">
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
                        filteredItems.map((item) => (
                            <AlertCard
                                key={item.id}
                                item={item}
                                onViewDetails={() => onSelectAlert(item.id)}
                                isSaved={savedIds.has(item.id)}
                            />
                        ))
                    ) : (
                        <AlertTableView
                            items={filteredItems}
                            onSelectAlert={onSelectAlert}
                            savedIds={savedIds}
                        />
                    )}

                    {pagination &&
                        (pagination.prevUrl || pagination.nextUrl) && (
                            <div className="mt-2 flex items-center justify-between border-t border-white/5 pt-4">
                                <div className="text-xs text-text-secondary">
                                    {pagination.currentPage !== null &&
                                    pagination.lastPage !== null
                                        ? `Page ${pagination.currentPage} of ${pagination.lastPage}`
                                        : null}
                                </div>
                                <div className="flex gap-2">
                                    {pagination.prevUrl ? (
                                        <Link
                                            href={pagination.prevUrl}
                                            preserveScroll
                                            preserveState
                                            className="rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-xs font-bold text-white/80 transition-colors hover:border-white/20 hover:bg-white/10 hover:text-white"
                                        >
                                            Previous
                                        </Link>
                                    ) : (
                                        <span className="rounded-lg border border-white/5 bg-white/5 px-3 py-2 text-xs font-bold text-white/20 select-none">
                                            Previous
                                        </span>
                                    )}

                                    {pagination.nextUrl ? (
                                        <Link
                                            href={pagination.nextUrl}
                                            preserveScroll
                                            preserveState
                                            className="rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-xs font-bold text-white/80 transition-colors hover:border-white/20 hover:bg-white/10 hover:text-white"
                                        >
                                            Next
                                        </Link>
                                    ) : (
                                        <span className="rounded-lg border border-white/5 bg-white/5 px-3 py-2 text-xs font-bold text-white/20 select-none">
                                            Next
                                        </span>
                                    )}
                                </div>
                            </div>
                        )}
                </div>

                {/* Empty State */}
                {filteredItems.length === 0 && (
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
                            onClick={handleReset}
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
