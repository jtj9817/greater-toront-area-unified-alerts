import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { UnifiedAlertResource } from '../domain/alerts';
import { useInfiniteScroll } from './useInfiniteScroll';

function fireResource(
    externalId: string,
    timestamp: string,
): UnifiedAlertResource {
    return {
        id: `fire:${externalId}`,
        source: 'fire',
        external_id: externalId,
        is_active: true,
        timestamp,
        title: `ALARM ${externalId}`,
        location: {
            name: 'Test St',
            lat: null,
            lng: null,
        },
        meta: {
            alarm_level: 2,
            event_num: externalId,
            units_dispatched: null,
            beat: null,
        },
    };
}

describe('useInfiniteScroll', () => {
    beforeEach(() => {
        global.fetch = vi.fn();
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('appends next batch deterministically and deduplicates alert ids', async () => {
        vi.mocked(global.fetch).mockResolvedValueOnce({
            ok: true,
            status: 200,
            statusText: 'OK',
            json: async () => ({
                data: [
                    fireResource('F1', '2026-02-03T11:59:00Z'),
                    fireResource('F2', '2026-02-03T11:58:00Z'),
                ],
                next_cursor: 'cursor-2',
            }),
        } as Response);

        const initialProps = {
            initialAlerts: [fireResource('F1', '2026-02-03T11:59:00Z')],
            initialNextCursor: 'cursor-1',
            filters: {
                status: 'all' as const,
                source: null,
                q: null,
                since: null,
            },
            apiUrl: '/api/feed',
        };

        const { result } = renderHook((props) => useInfiniteScroll(props), {
            initialProps,
        });

        await act(async () => {
            await result.current.loadMore();
        });

        expect(global.fetch).toHaveBeenCalledWith(
            '/api/feed?cursor=cursor-1',
            expect.objectContaining({
                method: 'GET',
                headers: expect.objectContaining({
                    Accept: 'application/json',
                }),
            }),
        );
        expect(result.current.alerts.map((alert) => alert.id)).toEqual([
            'fire:F1',
            'fire:F2',
        ]);
        expect(result.current.nextCursor).toBe('cursor-2');
        expect(result.current.hasMore).toBe(true);
    });

    it('sets no-more-results state when next_cursor is null', async () => {
        vi.mocked(global.fetch).mockResolvedValueOnce({
            ok: true,
            status: 200,
            statusText: 'OK',
            json: async () => ({
                data: [fireResource('F2', '2026-02-03T11:58:00Z')],
                next_cursor: null,
            }),
        } as Response);

        const initialProps = {
            initialAlerts: [fireResource('F1', '2026-02-03T11:59:00Z')],
            initialNextCursor: 'cursor-1',
            filters: {
                status: 'all' as const,
                source: null,
                q: null,
                since: null,
            },
            apiUrl: '/api/feed',
        };

        const { result } = renderHook((props) => useInfiniteScroll(props), {
            initialProps,
        });

        await act(async () => {
            await result.current.loadMore();
        });

        expect(result.current.alerts.map((alert) => alert.id)).toEqual([
            'fire:F1',
            'fire:F2',
        ]);
        expect(result.current.nextCursor).toBeNull();
        expect(result.current.hasMore).toBe(false);

        await act(async () => {
            await result.current.loadMore();
        });

        expect(global.fetch).toHaveBeenCalledTimes(1);
    });

    it('resets alerts and cursor when filters trigger a new initial payload', () => {
        const { result, rerender } = renderHook(
            (props: {
                initialAlerts: UnifiedAlertResource[];
                initialNextCursor: string | null;
                filters: {
                    status: 'all' | 'active' | 'cleared';
                    source: string | null;
                    q: string | null;
                    since: string | null;
                };
            }) =>
                useInfiniteScroll({
                    ...props,
                    apiUrl: '/api/feed',
                }),
            {
                initialProps: {
                    initialAlerts: [
                        fireResource('F1', '2026-02-03T11:59:00Z'),
                        fireResource('F2', '2026-02-03T11:58:00Z'),
                    ],
                    initialNextCursor: 'cursor-old',
                    filters: {
                        status: 'all',
                        source: null,
                        q: null,
                        since: null,
                    },
                },
            },
        );

        expect(result.current.alerts.map((alert) => alert.id)).toEqual([
            'fire:F1',
            'fire:F2',
        ]);
        expect(result.current.nextCursor).toBe('cursor-old');
        expect(result.current.hasMore).toBe(true);

        rerender({
            initialAlerts: [fireResource('F9', '2026-02-03T11:57:00Z')],
            initialNextCursor: null,
            filters: {
                status: 'active',
                source: 'fire',
                q: 'alarm',
                since: '1h',
            },
        });

        expect(result.current.alerts.map((alert) => alert.id)).toEqual([
            'fire:F9',
        ]);
        expect(result.current.nextCursor).toBeNull();
        expect(result.current.hasMore).toBe(false);
    });
});
