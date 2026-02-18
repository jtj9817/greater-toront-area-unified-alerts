import { act, renderHook, waitFor } from '@testing-library/react';
import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest';
import type { SceneIntelItem } from '../domain/alerts/fire/scene-intel';
import { useSceneIntel } from './useSceneIntel';

describe('useSceneIntel', () => {
    const mockItems: SceneIntelItem[] = [
        {
            id: 1,
            type: 'milestone',
            type_label: 'Milestone',
            icon: 'flag',
            content: 'Command established',
            timestamp: '2026-02-14T09:28:21+00:00',
        },
    ];

    const mockResponse = {
        data: {
            data: mockItems,
            meta: {
                event_num: '12345',
                count: 1,
            },
        },
    };

    beforeEach(() => {
        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => mockResponse.data,
        });
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    it('returns initial items correctly without flickering loading state', async () => {
        // We want to test that initial items are returned and loading is false because we already have data
        const { result } = renderHook(() => useSceneIntel('12345', mockItems));
        expect(result.current.items).toEqual(mockItems);
        expect(result.current.loading).toBe(false);

        // Wait for the fetch to complete to avoid act warnings
        await waitFor(() => {
            expect(result.current.loading).toBe(false);
        });
    });

    it('fetches data successfully', async () => {
        const { result } = renderHook(() => useSceneIntel('12345'));

        expect(result.current.loading).toBe(true);

        await waitFor(() => {
            expect(result.current.loading).toBe(false);
        });

        expect(result.current.items).toEqual(mockItems);
        expect(result.current.error).toBeNull();
        expect(global.fetch).toHaveBeenCalledWith(
            '/api/incidents/12345/intel',
            expect.objectContaining({
                headers: { Accept: 'application/json' },
                signal: expect.any(AbortSignal),
            }),
        );
    });

    it('handles fetch error', async () => {
        vi.mocked(global.fetch).mockResolvedValue({
            ok: false,
            statusText: 'Not Found',
        } as Response);

        const { result } = renderHook(() => useSceneIntel('12345'));

        await waitFor(() => {
            expect(result.current.loading).toBe(false);
        });

        expect(result.current.error).toBeTruthy();
        expect(result.current.items).toEqual([]);
    });

    it('handles malformed data', async () => {
        vi.mocked(global.fetch).mockResolvedValue({
            ok: true,
            json: async () => ({ invalid: 'data' }),
        } as Response);

        const { result } = renderHook(() => useSceneIntel('12345'));

        await waitFor(() => {
            expect(result.current.loading).toBe(false);
        });

        expect(result.current.error).toBeTruthy();
        expect(result.current.error?.message).toBe(
            'Invalid data received from server',
        );
    });

    it('does not overlap polling requests when the current request is still in flight', async () => {
        vi.useFakeTimers();

        let resolveFirstRequest: ((response: Response) => void) | undefined;

        vi.mocked(global.fetch)
            .mockReset()
            .mockImplementationOnce(
                () =>
                    new Promise<Response>((resolve) => {
                        resolveFirstRequest = resolve;
                    }),
            )
            .mockResolvedValue({
                ok: true,
                json: async () => mockResponse.data,
            } as Response);

        renderHook(() => useSceneIntel('12345'));

        expect(global.fetch).toHaveBeenCalledTimes(1);

        await act(async () => {
            vi.advanceTimersByTime(3 * 30000);
        });

        expect(global.fetch).toHaveBeenCalledTimes(1);

        await act(async () => {
            resolveFirstRequest?.({
                ok: true,
                json: async () => mockResponse.data,
            } as Response);
            await Promise.resolve();
        });

        await act(async () => {
            vi.advanceTimersByTime(30000);
        });

        expect(global.fetch).toHaveBeenCalledTimes(2);
    });
});
