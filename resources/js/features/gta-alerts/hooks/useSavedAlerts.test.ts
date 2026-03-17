import { act, renderHook, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { SavedAlertServiceError } from '../services/SavedAlertService';
import { useSavedAlerts } from './useSavedAlerts';

const GUEST_STORAGE_KEY = 'gta_saved_alerts_v1';
const GUEST_CAP = 10;

// ------------------------------------------------------------------
// Helpers
// ------------------------------------------------------------------

const mockFetchSuccess = (body: unknown = {}): Response =>
    ({
        ok: true,
        status: 200,
        json: async () => body,
    }) as Response;

const mockFetchError = (status: number, message: string): Response =>
    ({
        ok: false,
        status,
        json: async () => ({ message }),
    }) as Response;

// ------------------------------------------------------------------
// Tests
// ------------------------------------------------------------------

describe('useSavedAlerts', () => {
    beforeEach(() => {
        localStorage.clear();
        vi.restoreAllMocks();
    });

    afterEach(() => {
        localStorage.clear();
    });

    // ----------------------------------------------------------------
    // Guest mode
    // ----------------------------------------------------------------

    describe('guest mode (authUserId = null)', () => {
        it('initializes with empty state when localStorage is empty', () => {
            const { result } = renderHook(() =>
                useSavedAlerts({ authUserId: null, initialSavedIds: [] }),
            );

            expect(result.current.savedIds).toEqual([]);
            expect(result.current.guestCapReached).toBe(false);
            expect(result.current.feedback).toBeNull();
        });

        it('bootstraps savedIds from localStorage on init', () => {
            localStorage.setItem(
                GUEST_STORAGE_KEY,
                JSON.stringify(['fire:F1', 'police:P1']),
            );

            const { result } = renderHook(() =>
                useSavedAlerts({ authUserId: null, initialSavedIds: [] }),
            );

            expect(result.current.savedIds).toEqual(['fire:F1', 'police:P1']);
        });

        it('ignores initialSavedIds prop in guest mode', () => {
            const { result } = renderHook(() =>
                useSavedAlerts({
                    authUserId: null,
                    initialSavedIds: ['fire:PROP'],
                }),
            );

            expect(result.current.isSaved('fire:PROP')).toBe(false);
        });

        it('saves an alert and persists it to localStorage', async () => {
            const { result } = renderHook(() =>
                useSavedAlerts({ authUserId: null, initialSavedIds: [] }),
            );

            await act(async () => {
                await result.current.saveAlert('fire:F1');
            });

            expect(result.current.isSaved('fire:F1')).toBe(true);
            expect(
                JSON.parse(
                    localStorage.getItem(GUEST_STORAGE_KEY) ?? '[]',
                ) as string[],
            ).toContain('fire:F1');
        });

        it('sets "saved" feedback after a guest save', async () => {
            const { result } = renderHook(() =>
                useSavedAlerts({ authUserId: null, initialSavedIds: [] }),
            );

            await act(async () => {
                await result.current.saveAlert('fire:F1');
            });

            expect(result.current.feedback?.kind).toBe('saved');
            expect(result.current.feedback?.alertId).toBe('fire:F1');
        });

        it('removes a saved alert and persists the removal to localStorage', async () => {
            localStorage.setItem(
                GUEST_STORAGE_KEY,
                JSON.stringify(['fire:F1', 'police:P1']),
            );

            const { result } = renderHook(() =>
                useSavedAlerts({ authUserId: null, initialSavedIds: [] }),
            );

            await act(async () => {
                await result.current.removeAlert('fire:F1');
            });

            expect(result.current.isSaved('fire:F1')).toBe(false);
            expect(result.current.isSaved('police:P1')).toBe(true);
            expect(result.current.feedback?.kind).toBe('removed');
        });

        it('removeAlert is a no-op when alert is not saved', async () => {
            const { result } = renderHook(() =>
                useSavedAlerts({ authUserId: null, initialSavedIds: [] }),
            );

            await act(async () => {
                await result.current.removeAlert('fire:NOT_SAVED');
            });

            expect(result.current.savedIds).toHaveLength(0);
            expect(result.current.feedback).toBeNull();
        });

        it('toggleAlert saves an unsaved alert', async () => {
            const { result } = renderHook(() =>
                useSavedAlerts({ authUserId: null, initialSavedIds: [] }),
            );

            await act(async () => {
                await result.current.toggleAlert('fire:F1');
            });

            expect(result.current.isSaved('fire:F1')).toBe(true);
        });

        it('toggleAlert removes a saved alert', async () => {
            localStorage.setItem(
                GUEST_STORAGE_KEY,
                JSON.stringify(['fire:F1']),
            );

            const { result } = renderHook(() =>
                useSavedAlerts({ authUserId: null, initialSavedIds: [] }),
            );

            await act(async () => {
                await result.current.toggleAlert('fire:F1');
            });

            expect(result.current.isSaved('fire:F1')).toBe(false);
        });

        it('sets "duplicate" feedback when saving an already-saved alert', async () => {
            localStorage.setItem(
                GUEST_STORAGE_KEY,
                JSON.stringify(['fire:F1']),
            );

            const { result } = renderHook(() =>
                useSavedAlerts({ authUserId: null, initialSavedIds: [] }),
            );

            await act(async () => {
                await result.current.saveAlert('fire:F1');
            });

            // Should not duplicate the entry
            expect(
                result.current.savedIds.filter((id) => id === 'fire:F1'),
            ).toHaveLength(1);
            expect(result.current.feedback?.kind).toBe('duplicate');
        });

        it('guestCapReached is true when 10 alerts are saved', () => {
            const ids = Array.from(
                { length: GUEST_CAP },
                (_, i) => `fire:F${i}`,
            );
            localStorage.setItem(GUEST_STORAGE_KEY, JSON.stringify(ids));

            const { result } = renderHook(() =>
                useSavedAlerts({ authUserId: null, initialSavedIds: [] }),
            );

            expect(result.current.guestCapReached).toBe(true);
        });

        it('sets "limit" feedback and blocks save when guest cap is reached', async () => {
            const ids = Array.from(
                { length: GUEST_CAP },
                (_, i) => `fire:F${i}`,
            );
            localStorage.setItem(GUEST_STORAGE_KEY, JSON.stringify(ids));

            const { result } = renderHook(() =>
                useSavedAlerts({ authUserId: null, initialSavedIds: [] }),
            );

            await act(async () => {
                await result.current.saveAlert('fire:NEW');
            });

            expect(result.current.savedIds).not.toContain('fire:NEW');
            expect(result.current.savedIds).toHaveLength(GUEST_CAP);
            expect(result.current.feedback?.kind).toBe('limit');
        });

        it('evictOldestThree removes the first 3 IDs (insertion-order oldest)', () => {
            const ids = [
                'fire:F0',
                'fire:F1',
                'fire:F2',
                'fire:F3',
                'fire:F4',
            ];
            localStorage.setItem(GUEST_STORAGE_KEY, JSON.stringify(ids));

            const { result } = renderHook(() =>
                useSavedAlerts({ authUserId: null, initialSavedIds: [] }),
            );

            act(() => {
                result.current.evictOldestThree();
            });

            expect(result.current.savedIds).toEqual(['fire:F3', 'fire:F4']);
        });

        it('evictOldestThree sets "removed" feedback', () => {
            localStorage.setItem(
                GUEST_STORAGE_KEY,
                JSON.stringify(['fire:F0', 'fire:F1', 'fire:F2', 'fire:F3']),
            );

            const { result } = renderHook(() =>
                useSavedAlerts({ authUserId: null, initialSavedIds: [] }),
            );

            act(() => {
                result.current.evictOldestThree();
            });

            expect(result.current.feedback?.kind).toBe('removed');
        });

        it('guestCapReached becomes false after evictOldestThree', () => {
            const ids = Array.from(
                { length: GUEST_CAP },
                (_, i) => `fire:F${i}`,
            );
            localStorage.setItem(GUEST_STORAGE_KEY, JSON.stringify(ids));

            const { result } = renderHook(() =>
                useSavedAlerts({ authUserId: null, initialSavedIds: [] }),
            );

            expect(result.current.guestCapReached).toBe(true);

            act(() => {
                result.current.evictOldestThree();
            });

            expect(result.current.guestCapReached).toBe(false);
        });

        it('can save after evictOldestThree frees space', async () => {
            const ids = Array.from(
                { length: GUEST_CAP },
                (_, i) => `fire:F${i}`,
            );
            localStorage.setItem(GUEST_STORAGE_KEY, JSON.stringify(ids));

            const { result } = renderHook(() =>
                useSavedAlerts({ authUserId: null, initialSavedIds: [] }),
            );

            act(() => {
                result.current.evictOldestThree();
            });

            await act(async () => {
                await result.current.saveAlert('fire:NEW');
            });

            expect(result.current.isSaved('fire:NEW')).toBe(true);
        });

        it('clearFeedback resets feedback to null', async () => {
            const { result } = renderHook(() =>
                useSavedAlerts({ authUserId: null, initialSavedIds: [] }),
            );

            await act(async () => {
                await result.current.saveAlert('fire:F1');
            });

            expect(result.current.feedback).not.toBeNull();

            act(() => {
                result.current.clearFeedback();
            });

            expect(result.current.feedback).toBeNull();
        });

        it('isSaved returns false for unsaved alerts', () => {
            const { result } = renderHook(() =>
                useSavedAlerts({ authUserId: null, initialSavedIds: [] }),
            );

            expect(result.current.isSaved('fire:UNKNOWN')).toBe(false);
        });

        it('isPending is always false in guest mode', async () => {
            const { result } = renderHook(() =>
                useSavedAlerts({ authUserId: null, initialSavedIds: [] }),
            );

            expect(result.current.isPending('fire:F1')).toBe(false);

            await act(async () => {
                await result.current.saveAlert('fire:F1');
            });

            expect(result.current.isPending('fire:F1')).toBe(false);
        });

        it('preserves insertion order in localStorage (oldest first)', async () => {
            const { result } = renderHook(() =>
                useSavedAlerts({ authUserId: null, initialSavedIds: [] }),
            );

            await act(async () => {
                await result.current.saveAlert('fire:A');
                await result.current.saveAlert('police:B');
                await result.current.saveAlert('transit:C');
            });

            const stored = JSON.parse(
                localStorage.getItem(GUEST_STORAGE_KEY) ?? '[]',
            ) as string[];
            expect(stored).toEqual(['fire:A', 'police:B', 'transit:C']);
        });
    });

    // ----------------------------------------------------------------
    // Authenticated mode
    // ----------------------------------------------------------------

    describe('authenticated mode (authUserId = 1)', () => {
        beforeEach(() => {
            global.fetch = vi.fn();
        });

        it('initializes savedIds from initialSavedIds prop', () => {
            const { result } = renderHook(() =>
                useSavedAlerts({
                    authUserId: 1,
                    initialSavedIds: ['fire:F1', 'police:P1'],
                }),
            );

            expect(result.current.savedIds).toEqual(['fire:F1', 'police:P1']);
            expect(result.current.isSaved('fire:F1')).toBe(true);
            expect(result.current.isSaved('police:P1')).toBe(true);
        });

        it('does not read from localStorage in auth mode', () => {
            localStorage.setItem(
                GUEST_STORAGE_KEY,
                JSON.stringify(['fire:GUEST']),
            );

            const { result } = renderHook(() =>
                useSavedAlerts({
                    authUserId: 1,
                    initialSavedIds: ['fire:F1'],
                }),
            );

            expect(result.current.isSaved('fire:GUEST')).toBe(false);
            expect(result.current.isSaved('fire:F1')).toBe(true);
        });

        it('saves an alert via API and sets "saved" feedback', async () => {
            vi.mocked(global.fetch).mockResolvedValue(
                mockFetchSuccess({ data: { id: 1 } }),
            );

            const { result } = renderHook(() =>
                useSavedAlerts({ authUserId: 1, initialSavedIds: [] }),
            );

            await act(async () => {
                await result.current.saveAlert('fire:F1');
            });

            expect(result.current.isSaved('fire:F1')).toBe(true);
            expect(result.current.feedback?.kind).toBe('saved');
            expect(global.fetch).toHaveBeenCalledWith(
                '/api/saved-alerts',
                expect.objectContaining({ method: 'POST' }),
            );
        });

        it('removes an alert via API and sets "removed" feedback', async () => {
            vi.mocked(global.fetch).mockResolvedValue(
                mockFetchSuccess({ meta: { deleted: true } }),
            );

            const { result } = renderHook(() =>
                useSavedAlerts({
                    authUserId: 1,
                    initialSavedIds: ['fire:F1'],
                }),
            );

            await act(async () => {
                await result.current.removeAlert('fire:F1');
            });

            expect(result.current.isSaved('fire:F1')).toBe(false);
            expect(result.current.feedback?.kind).toBe('removed');
        });

        it('performs optimistic save — item is added before API resolves', async () => {
            let resolveRequest: ((r: Response) => void) | undefined;
            vi.mocked(global.fetch).mockImplementation(
                () =>
                    new Promise<Response>((resolve) => {
                        resolveRequest = resolve;
                    }),
            );

            const { result } = renderHook(() =>
                useSavedAlerts({ authUserId: 1, initialSavedIds: [] }),
            );

            act(() => {
                void result.current.saveAlert('fire:F1');
            });

            // Optimistically added before the request completes
            expect(result.current.isSaved('fire:F1')).toBe(true);
            expect(result.current.isPending('fire:F1')).toBe(true);

            await act(async () => {
                resolveRequest?.({
                    ok: true,
                    json: async () => ({}),
                } as Response);
                await Promise.resolve();
            });

            await waitFor(() => {
                expect(result.current.isPending('fire:F1')).toBe(false);
            });
        });

        it('rolls back optimistic save and sets "unknown" feedback on 500 API failure', async () => {
            vi.mocked(global.fetch).mockResolvedValue(
                mockFetchError(500, 'Internal Server Error'),
            );

            const { result } = renderHook(() =>
                useSavedAlerts({ authUserId: 1, initialSavedIds: [] }),
            );

            await act(async () => {
                await result.current.saveAlert('fire:F1');
            });

            expect(result.current.isSaved('fire:F1')).toBe(false);
            // 500 maps to kind 'unknown' via SavedAlertServiceError.errorKindFromStatus
            expect(result.current.feedback?.kind).toBe('unknown');
        });

        it('rolls back optimistic save and sets "error" feedback on non-HTTP failure', async () => {
            vi.mocked(global.fetch).mockRejectedValue(
                new TypeError('Network error'),
            );

            const { result } = renderHook(() =>
                useSavedAlerts({ authUserId: 1, initialSavedIds: [] }),
            );

            await act(async () => {
                await result.current.saveAlert('fire:F1');
            });

            expect(result.current.isSaved('fire:F1')).toBe(false);
            expect(result.current.feedback?.kind).toBe('error');
        });

        it('keeps item saved and sets "duplicate" feedback on 409 response', async () => {
            vi.mocked(global.fetch).mockResolvedValue(
                mockFetchError(409, 'This alert has already been saved.'),
            );

            const { result } = renderHook(() =>
                useSavedAlerts({ authUserId: 1, initialSavedIds: [] }),
            );

            await act(async () => {
                await result.current.saveAlert('fire:F1');
            });

            // Server says it's already there — keep optimistic state
            expect(result.current.isSaved('fire:F1')).toBe(true);
            expect(result.current.feedback?.kind).toBe('duplicate');
        });

        it('sets "auth" feedback on 401 response', async () => {
            vi.mocked(global.fetch).mockResolvedValue(
                mockFetchError(401, 'Unauthenticated.'),
            );

            const { result } = renderHook(() =>
                useSavedAlerts({ authUserId: 1, initialSavedIds: [] }),
            );

            await act(async () => {
                await result.current.saveAlert('fire:F1');
            });

            expect(result.current.isSaved('fire:F1')).toBe(false);
            expect(result.current.feedback?.kind).toBe('auth');
        });

        it('sets "validation" feedback on 422 response', async () => {
            vi.mocked(global.fetch).mockResolvedValue(
                mockFetchError(422, 'The alert_id field is required.'),
            );

            const { result } = renderHook(() =>
                useSavedAlerts({ authUserId: 1, initialSavedIds: [] }),
            );

            await act(async () => {
                await result.current.saveAlert('fire:F1');
            });

            expect(result.current.isSaved('fire:F1')).toBe(false);
            expect(result.current.feedback?.kind).toBe('validation');
        });

        it('rolls back optimistic remove and sets "unknown" feedback on 500 API failure', async () => {
            vi.mocked(global.fetch).mockResolvedValue(
                mockFetchError(500, 'Server error.'),
            );

            const { result } = renderHook(() =>
                useSavedAlerts({
                    authUserId: 1,
                    initialSavedIds: ['fire:F1'],
                }),
            );

            await act(async () => {
                await result.current.removeAlert('fire:F1');
            });

            expect(result.current.isSaved('fire:F1')).toBe(true);
            // 500 maps to kind 'unknown' via SavedAlertServiceError.errorKindFromStatus
            expect(result.current.feedback?.kind).toBe('unknown');
        });

        it('rolls back optimistic remove and sets "error" feedback on non-HTTP failure', async () => {
            vi.mocked(global.fetch).mockRejectedValue(
                new TypeError('Network error'),
            );

            const { result } = renderHook(() =>
                useSavedAlerts({
                    authUserId: 1,
                    initialSavedIds: ['fire:F1'],
                }),
            );

            await act(async () => {
                await result.current.removeAlert('fire:F1');
            });

            expect(result.current.isSaved('fire:F1')).toBe(true);
            expect(result.current.feedback?.kind).toBe('error');
        });

        it('guestCapReached is always false in auth mode regardless of count', () => {
            const many = Array.from(
                { length: 20 },
                (_, i) => `fire:F${i}`,
            );

            const { result } = renderHook(() =>
                useSavedAlerts({ authUserId: 1, initialSavedIds: many }),
            );

            expect(result.current.guestCapReached).toBe(false);
        });

        it('does not write to the guest localStorage key in auth mode', async () => {
            vi.mocked(global.fetch).mockResolvedValue(
                mockFetchSuccess({ data: { id: 1 } }),
            );
            const setItem = vi.spyOn(Storage.prototype, 'setItem');

            const { result } = renderHook(() =>
                useSavedAlerts({ authUserId: 1, initialSavedIds: [] }),
            );

            await act(async () => {
                await result.current.saveAlert('fire:F1');
            });

            expect(setItem).not.toHaveBeenCalledWith(
                GUEST_STORAGE_KEY,
                expect.anything(),
            );
        });

        it('isPending becomes false after the API request completes', async () => {
            vi.mocked(global.fetch).mockResolvedValue(
                mockFetchSuccess({ data: { id: 1 } }),
            );

            const { result } = renderHook(() =>
                useSavedAlerts({ authUserId: 1, initialSavedIds: [] }),
            );

            await act(async () => {
                await result.current.saveAlert('fire:F1');
            });

            expect(result.current.isPending('fire:F1')).toBe(false);
        });

        it('evictOldestThree is a no-op in auth mode', () => {
            const { result } = renderHook(() =>
                useSavedAlerts({
                    authUserId: 1,
                    initialSavedIds: ['fire:F1', 'police:P1'],
                }),
            );

            act(() => {
                result.current.evictOldestThree();
            });

            // State should be unchanged
            expect(result.current.savedIds).toEqual(['fire:F1', 'police:P1']);
        });

        it('sets "duplicate" feedback without calling API when saveAlert is called for already-saved ID', async () => {
            const { result } = renderHook(() =>
                useSavedAlerts({
                    authUserId: 1,
                    initialSavedIds: ['fire:F1'],
                }),
            );

            await act(async () => {
                await result.current.saveAlert('fire:F1');
            });

            expect(global.fetch).not.toHaveBeenCalled();
            expect(result.current.feedback?.kind).toBe('duplicate');
        });
    });
});
