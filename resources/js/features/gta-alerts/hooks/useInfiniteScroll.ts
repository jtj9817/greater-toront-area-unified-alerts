import { useCallback, useEffect, useRef, useState } from 'react';
import type { DomainAlert, UnifiedAlertResource } from '../domain/alerts';
import { AlertService } from '../services/AlertService';

export interface InfiniteScrollState {
    /** All loaded alerts (accumulated from initial + subsequent batches) */
    alerts: DomainAlert[];
    /** Whether a batch is currently being fetched */
    isLoading: boolean;
    /** Error message if the last fetch failed */
    error: string | null;
    /** Cursor for the next batch (null if no more results) */
    nextCursor: string | null;
    /** Whether there are more results to load */
    hasMore: boolean;
}

export interface InfiniteScrollActions {
    /** Manually trigger a load more (used by IntersectionObserver) */
    loadMore: () => Promise<void>;
    /** Reset the infinite scroll state (used when filters change) */
    reset: (
        initialAlerts: UnifiedAlertResource[],
        nextCursor: string | null,
    ) => void;
}

export interface InfiniteScrollRefs {
    /** Ref to attach to the sentinel element for intersection detection */
    sentinelRef: React.RefObject<HTMLDivElement | null>;
}

export interface UseInfiniteScrollOptions {
    /** Initial alerts from Inertia props */
    initialAlerts: UnifiedAlertResource[];
    /** Initial next_cursor from Inertia props */
    initialNextCursor: string | null;
    /** Current filter values for constructing API requests */
    filters: {
        status: 'all' | 'active' | 'cleared';
        sort: 'asc' | 'desc';
        source: string | null;
        q: string | null;
        since: string | null;
    };
    /** API endpoint URL */
    apiUrl: string;
    /** Number of pixels from bottom to trigger load (default: 200) */
    rootMargin?: string;
}

/**
 * Hook for cursor-based infinite scroll.
 *
 * Features:
 * - Accumulates alerts from multiple batches
 * - Deduplicates by alert ID
 * - Prevents concurrent fetch requests
 * - Resets when filters change
 * - Uses IntersectionObserver for scroll detection
 */
export function useInfiniteScroll(
    options: UseInfiniteScrollOptions,
): InfiniteScrollState & InfiniteScrollActions & InfiniteScrollRefs {
    const {
        initialAlerts,
        initialNextCursor,
        filters,
        apiUrl,
        rootMargin = '200px',
    } = options;

    // Track loaded alerts and pagination state
    const [alerts, setAlerts] = useState<DomainAlert[]>(() =>
        AlertService.mapUnifiedAlertsToDomainAlerts(initialAlerts),
    );
    const [nextCursor, setNextCursor] = useState<string | null>(
        initialNextCursor,
    );
    const [isLoading, setIsLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    // Refs for preventing race conditions and stale data
    const isFetchingRef = useRef(false);
    const abortControllerRef = useRef<AbortController | null>(null);
    const filtersRef = useRef(filters);

    // Keep filters ref in sync for comparison
    useEffect(() => {
        filtersRef.current = filters;
    }, [filters]);

    // Reset state when initial props change (filter change triggers page reload)
    useEffect(() => {
        setAlerts(AlertService.mapUnifiedAlertsToDomainAlerts(initialAlerts));
        setNextCursor(initialNextCursor);
        setError(null);
        isFetchingRef.current = false;

        // Cancel any in-flight requests
        if (abortControllerRef.current) {
            abortControllerRef.current.abort();
            abortControllerRef.current = null;
        }
    }, [initialAlerts, initialNextCursor]);

    /**
     * Load the next batch of alerts.
     * Guards against concurrent requests and stale filter state.
     */
    const loadMore = useCallback(async () => {
        // Prevent concurrent requests or loading when there's no more data
        if (isFetchingRef.current || nextCursor === null) {
            return;
        }

        // Capture current filters to detect stale responses
        const requestFilters = { ...filtersRef.current };

        isFetchingRef.current = true;
        setIsLoading(true);
        setError(null);

        // Create abort controller for this request
        abortControllerRef.current = new AbortController();

        try {
            // Build query parameters
            const params = new URLSearchParams();
            if (requestFilters.status !== 'all') {
                params.set('status', requestFilters.status);
            }
            if (requestFilters.sort === 'asc') {
                params.set('sort', requestFilters.sort);
            }
            if (requestFilters.source) {
                params.set('source', requestFilters.source);
            }
            if (requestFilters.q) {
                params.set('q', requestFilters.q);
            }
            if (requestFilters.since) {
                params.set('since', requestFilters.since);
            }
            params.set('cursor', nextCursor);

            const response = await fetch(`${apiUrl}?${params.toString()}`, {
                method: 'GET',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                signal: abortControllerRef.current.signal,
            });

            if (!response.ok) {
                throw new Error(
                    `Failed to load alerts: ${response.status} ${response.statusText}`,
                );
            }

            const data = (await response.json()) as {
                data: UnifiedAlertResource[];
                next_cursor: string | null;
            };

            // Check if filters changed while this request was in-flight
            // If so, discard this stale response
            const currentFilters = filtersRef.current;
            const filtersChanged =
                currentFilters.status !== requestFilters.status ||
                currentFilters.sort !== requestFilters.sort ||
                currentFilters.source !== requestFilters.source ||
                currentFilters.q !== requestFilters.q ||
                currentFilters.since !== requestFilters.since;

            if (filtersChanged) {
                return; // Discard stale response
            }

            // Deduplicate against the latest state to avoid stale closures.
            const newAlerts = AlertService.mapUnifiedAlertsToDomainAlerts(
                data.data,
            );
            setAlerts((prev) => {
                const existingIds = new Set(prev.map((alert) => alert.id));
                const uniqueNewAlerts = newAlerts.filter(
                    (alert) => !existingIds.has(alert.id),
                );

                return [...prev, ...uniqueNewAlerts];
            });
            setNextCursor(data.next_cursor);
        } catch (err) {
            // Don't report errors from aborted requests
            if (err instanceof Error && err.name === 'AbortError') {
                return;
            }

            setError(
                err instanceof Error
                    ? err.message
                    : 'Failed to load more alerts',
            );
        } finally {
            isFetchingRef.current = false;
            setIsLoading(false);
            abortControllerRef.current = null;
        }
    }, [nextCursor, apiUrl]);

    /**
     * Reset the infinite scroll state with new initial data.
     * Used when filters change and we get new initial props.
     */
    const reset = useCallback(
        (
            newInitialAlerts: UnifiedAlertResource[],
            newNextCursor: string | null,
        ) => {
            // Cancel any in-flight requests
            if (abortControllerRef.current) {
                abortControllerRef.current.abort();
                abortControllerRef.current = null;
            }

            setAlerts(
                AlertService.mapUnifiedAlertsToDomainAlerts(newInitialAlerts),
            );
            setNextCursor(newNextCursor);
            setError(null);
            setIsLoading(false);
            isFetchingRef.current = false;
        },
        [],
    );

    // Set up IntersectionObserver for infinite scroll
    const sentinelRef = useRef<HTMLDivElement | null>(null);

    useEffect(() => {
        const sentinel = sentinelRef.current;
        if (!sentinel) return;

        const observer = new IntersectionObserver(
            (entries) => {
                const [entry] = entries;
                if (
                    entry?.isIntersecting &&
                    nextCursor !== null &&
                    !isFetchingRef.current
                ) {
                    void loadMore();
                }
            },
            {
                root: null, // viewport
                rootMargin,
                threshold: 0,
            },
        );

        observer.observe(sentinel);

        return () => {
            observer.disconnect();
        };
    }, [loadMore, nextCursor, rootMargin]);

    return {
        alerts,
        isLoading,
        error,
        nextCursor,
        hasMore: nextCursor !== null,
        loadMore,
        reset,
        // Expose sentinel ref for the component to attach
        sentinelRef,
    };
}
