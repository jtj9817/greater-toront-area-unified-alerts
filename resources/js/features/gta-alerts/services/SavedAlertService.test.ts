import { describe, it, expect, vi, beforeEach } from 'vitest';
import {
    SavedAlertServiceError,
    fetchSavedAlerts,
    removeAlert,
    saveAlert,
} from './SavedAlertService';

const mockResponse = (ok: boolean, status: number, body: unknown): Response =>
    ({
        ok,
        status,
        json: async () => body,
    }) as Response;

describe('SavedAlertService', () => {
    beforeEach(() => {
        global.fetch = vi.fn();
        vi.restoreAllMocks();
    });

    describe('saveAlert', () => {
        it('calls POST /api/saved-alerts with the alert_id body', async () => {
            vi.mocked(global.fetch).mockResolvedValue(
                mockResponse(true, 201, { data: { id: 1 } }),
            );

            await saveAlert('fire:F1');

            expect(global.fetch).toHaveBeenCalledWith(
                '/api/saved-alerts',
                expect.objectContaining({
                    method: 'POST',
                    body: JSON.stringify({ alert_id: 'fire:F1' }),
                }),
            );
        });

        it('resolves without throwing on success', async () => {
            vi.mocked(global.fetch).mockResolvedValue(
                mockResponse(true, 201, { data: { id: 1 } }),
            );

            await expect(saveAlert('fire:F1')).resolves.toBeUndefined();
        });

        it('throws SavedAlertServiceError with kind "duplicate" on 409', async () => {
            vi.mocked(global.fetch).mockResolvedValue(
                mockResponse(false, 409, {
                    message: 'This alert has already been saved.',
                }),
            );

            await expect(saveAlert('fire:F1')).rejects.toThrow(
                SavedAlertServiceError,
            );

            try {
                await saveAlert('fire:F1');
            } catch (err) {
                expect(err).toBeInstanceOf(SavedAlertServiceError);
                expect((err as SavedAlertServiceError).kind).toBe('duplicate');
                expect((err as SavedAlertServiceError).status).toBe(409);
                expect((err as SavedAlertServiceError).message).toBe(
                    'This alert has already been saved.',
                );
            }
        });

        it('throws SavedAlertServiceError with kind "auth" on 401', async () => {
            vi.mocked(global.fetch).mockResolvedValue(
                mockResponse(false, 401, { message: 'Unauthenticated.' }),
            );

            await expect(saveAlert('fire:F1')).rejects.toMatchObject({
                kind: 'auth',
                status: 401,
            });
        });

        it('throws SavedAlertServiceError with kind "auth" on 403', async () => {
            vi.mocked(global.fetch).mockResolvedValue(
                mockResponse(false, 403, { message: 'Forbidden.' }),
            );

            await expect(saveAlert('fire:F1')).rejects.toMatchObject({
                kind: 'auth',
                status: 403,
            });
        });

        it('throws SavedAlertServiceError with kind "validation" on 422', async () => {
            vi.mocked(global.fetch).mockResolvedValue(
                mockResponse(false, 422, {
                    message: 'The alert_id field is required.',
                }),
            );

            await expect(saveAlert('fire:F1')).rejects.toMatchObject({
                kind: 'validation',
                status: 422,
            });
        });

        it('throws SavedAlertServiceError with kind "unknown" on 500', async () => {
            vi.mocked(global.fetch).mockResolvedValue(
                mockResponse(false, 500, { message: 'Server error.' }),
            );

            await expect(saveAlert('fire:F1')).rejects.toMatchObject({
                kind: 'unknown',
                status: 500,
            });
        });

        it('uses a fallback message when the error body has no message field', async () => {
            vi.mocked(global.fetch).mockResolvedValue(
                mockResponse(false, 500, {}),
            );

            await expect(saveAlert('fire:F1')).rejects.toMatchObject({
                message: 'Request failed (500)',
            });
        });
    });

    describe('removeAlert', () => {
        it('calls DELETE /api/saved-alerts/{encodedId}', async () => {
            vi.mocked(global.fetch).mockResolvedValue(
                mockResponse(true, 200, { meta: { deleted: true } }),
            );

            await removeAlert('fire:F1');

            expect(global.fetch).toHaveBeenCalledWith(
                `/api/saved-alerts/${encodeURIComponent('fire:F1')}`,
                expect.objectContaining({ method: 'DELETE' }),
            );
        });

        it('resolves without throwing on success', async () => {
            vi.mocked(global.fetch).mockResolvedValue(
                mockResponse(true, 200, { meta: { deleted: true } }),
            );

            await expect(removeAlert('fire:F1')).resolves.toBeUndefined();
        });

        it('URL-encodes the alert ID (colon is encoded)', async () => {
            vi.mocked(global.fetch).mockResolvedValue(
                mockResponse(true, 200, { meta: { deleted: true } }),
            );

            await removeAlert('police:P12345');

            const calledUrl = (global.fetch as ReturnType<typeof vi.fn>).mock
                .calls[0][0] as string;
            expect(calledUrl).toBe(
                `/api/saved-alerts/${encodeURIComponent('police:P12345')}`,
            );
            expect(calledUrl).not.toContain('police:P12345');
        });

        it('throws SavedAlertServiceError on 404', async () => {
            vi.mocked(global.fetch).mockResolvedValue(
                mockResponse(false, 404, { message: 'Not found.' }),
            );

            await expect(removeAlert('fire:F1')).rejects.toBeInstanceOf(
                SavedAlertServiceError,
            );
            await expect(removeAlert('fire:F1')).rejects.toMatchObject({
                kind: 'unknown',
                status: 404,
            });
        });

        it('throws SavedAlertServiceError with kind "auth" on 401', async () => {
            vi.mocked(global.fetch).mockResolvedValue(
                mockResponse(false, 401, { message: 'Unauthenticated.' }),
            );

            await expect(removeAlert('fire:F1')).rejects.toMatchObject({
                kind: 'auth',
            });
        });
    });

    describe('fetchSavedAlerts', () => {
        const validResponseBody = {
            data: [
                {
                    id: 'fire:F1',
                    source: 'fire',
                    external_id: 'F1',
                    is_active: true,
                    timestamp: '2026-01-01T00:00:00Z',
                    title: 'Structure Fire',
                    location: null,
                    meta: { event_num: 'F1' },
                },
            ],
            meta: {
                saved_ids: ['fire:F1'],
                missing_alert_ids: [],
            },
        };

        it('calls GET /api/saved-alerts', async () => {
            vi.mocked(global.fetch).mockResolvedValue(
                mockResponse(true, 200, validResponseBody),
            );

            await fetchSavedAlerts();

            expect(global.fetch).toHaveBeenCalledWith(
                '/api/saved-alerts',
                expect.objectContaining({ method: 'GET' }),
            );
        });

        it('returns normalized data and meta on success', async () => {
            vi.mocked(global.fetch).mockResolvedValue(
                mockResponse(true, 200, validResponseBody),
            );

            const result = await fetchSavedAlerts();

            expect(result.data).toHaveLength(1);
            expect(result.data[0].id).toBe('fire:F1');
            expect(result.meta.saved_ids).toEqual(['fire:F1']);
            expect(result.meta.missing_alert_ids).toEqual([]);
        });

        it('returns missing_alert_ids from meta', async () => {
            const bodyWithMissing = {
                data: [],
                meta: {
                    saved_ids: ['fire:GHOST'],
                    missing_alert_ids: ['fire:GHOST'],
                },
            };
            vi.mocked(global.fetch).mockResolvedValue(
                mockResponse(true, 200, bodyWithMissing),
            );

            const result = await fetchSavedAlerts();

            expect(result.meta.missing_alert_ids).toEqual(['fire:GHOST']);
            expect(result.data).toHaveLength(0);
        });

        it('throws SavedAlertServiceError on invalid response shape (missing data)', async () => {
            vi.mocked(global.fetch).mockResolvedValue(
                mockResponse(true, 200, { invalid: true }),
            );

            await expect(fetchSavedAlerts()).rejects.toBeInstanceOf(
                SavedAlertServiceError,
            );
        });

        it('throws SavedAlertServiceError when meta is missing', async () => {
            vi.mocked(global.fetch).mockResolvedValue(
                mockResponse(true, 200, { data: [] }),
            );

            await expect(fetchSavedAlerts()).rejects.toBeInstanceOf(
                SavedAlertServiceError,
            );
        });

        it('throws SavedAlertServiceError with kind "auth" on 401', async () => {
            vi.mocked(global.fetch).mockResolvedValue(
                mockResponse(false, 401, { message: 'Unauthenticated.' }),
            );

            await expect(fetchSavedAlerts()).rejects.toMatchObject({
                kind: 'auth',
            });
        });
    });

    describe('SavedAlertServiceError', () => {
        it('is an instance of Error', () => {
            const err = new SavedAlertServiceError('test', 500, 'unknown');
            expect(err).toBeInstanceOf(Error);
        });

        it('exposes status and kind properties', () => {
            const err = new SavedAlertServiceError('test', 409, 'duplicate');
            expect(err.status).toBe(409);
            expect(err.kind).toBe('duplicate');
            expect(err.message).toBe('test');
        });
    });
});
