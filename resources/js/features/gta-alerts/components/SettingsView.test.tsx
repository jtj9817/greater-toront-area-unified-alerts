import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { SettingsView } from './SettingsView';

function mockJsonResponse(payload: unknown, ok = true, status = 200): Response {
    return {
        ok,
        status,
        json: async () => payload,
    } as unknown as Response;
}

describe('SettingsView', () => {
    beforeEach(() => {
        vi.stubGlobal('fetch', vi.fn());
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    it('shows sign-in UX and does not call preferences API for guests', () => {
        const fetchMock = globalThis.fetch as unknown as ReturnType<typeof vi.fn>;

        render(<SettingsView authUserId={null} availableRoutes={['1']} />);

        expect(
            screen.getByText('Sign in to configure notification preferences'),
        ).toBeInTheDocument();
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('loads preferences for authenticated users and saves updates', async () => {
        const fetchMock = globalThis.fetch as unknown as ReturnType<typeof vi.fn>;

        fetchMock.mockResolvedValueOnce(
            mockJsonResponse({
                data: {
                    alert_type: 'transit',
                    severity_threshold: 'major',
                    geofences: [],
                    subscribed_routes: ['1'],
                    digest_mode: false,
                    push_enabled: true,
                },
            }),
        );

        fetchMock.mockResolvedValueOnce(
            mockJsonResponse({
                data: {
                    alert_type: 'transit',
                    severity_threshold: 'major',
                    geofences: [],
                    subscribed_routes: ['1', 'GO-LW'],
                    digest_mode: false,
                    push_enabled: true,
                },
            }),
        );

        render(<SettingsView authUserId={99} availableRoutes={['1', 'GO-LW']} />);

        await waitFor(() => {
            expect(fetchMock).toHaveBeenCalledTimes(1);
        });

        const goRouteToggle = screen.getByLabelText(
            'Route GO-LW',
        ) as HTMLInputElement;
        fireEvent.click(goRouteToggle);
        expect(goRouteToggle.checked).toBe(true);

        fireEvent.click(
            screen.getByRole('button', { name: 'Save notification settings' }),
        );

        await waitFor(() => {
            expect(fetchMock).toHaveBeenCalledTimes(2);
        });

        const secondCall = fetchMock.mock.calls[1] as [string, RequestInit];
        expect(secondCall[0]).toBe('/settings/notifications');
        expect(secondCall[1].method).toBe('PATCH');

        const body = secondCall[1].body;
        expect(typeof body).toBe('string');

        if (typeof body !== 'string') {
            throw new Error('Expected request body to be stringified JSON');
        }

        const payload = JSON.parse(body) as {
            subscribed_routes: string[];
            severity_threshold: string;
        };

        expect(payload.subscribed_routes).toContain('GO-LW');
        expect(payload.severity_threshold).toBe('major');
    });
});
