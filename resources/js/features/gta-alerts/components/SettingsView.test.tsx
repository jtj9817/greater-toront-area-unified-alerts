import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { SettingsView } from './SettingsView';

type FetchMock = ReturnType<typeof vi.fn>;

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

    it('shows sign-in UX for guests without loading preferences', () => {
        const fetchMock = globalThis.fetch as FetchMock;

        render(<SettingsView authUserId={null} availableRoutes={['1']} />);

        expect(
            screen.getByText('Sign in to configure notification preferences'),
        ).toBeInTheDocument();
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('loads preferences and subscription options then saves selected subscriptions', async () => {
        const fetchMock = globalThis.fetch as FetchMock;

        fetchMock.mockResolvedValueOnce(
            mockJsonResponse({
                data: {
                    alert_type: 'transit',
                    severity_threshold: 'major',
                    geofences: [],
                    subscriptions: [],
                    digest_mode: false,
                    push_enabled: true,
                },
            }),
        );

        fetchMock.mockResolvedValueOnce(
            mockJsonResponse({
                data: {
                    agency: {
                        urn: 'agency:ttc',
                        name: 'Toronto Transit Commission',
                    },
                    routes: [
                        { urn: 'route:501', id: '501', name: '501 Queen' },
                    ],
                    stations: [
                        { urn: 'station:union', slug: 'union', name: 'Union' },
                    ],
                    lines: [
                        {
                            urn: 'line:1',
                            id: '1',
                            name: 'Line 1 Yonge-University',
                        },
                    ],
                },
            }),
        );

        fetchMock.mockResolvedValueOnce(
            mockJsonResponse({
                data: {
                    alert_type: 'transit',
                    severity_threshold: 'major',
                    geofences: [],
                    subscriptions: ['route:501'],
                    digest_mode: false,
                    push_enabled: true,
                },
            }),
        );

        render(<SettingsView authUserId={42} availableRoutes={[]} />);

        await waitFor(() => {
            expect(fetchMock).toHaveBeenCalledTimes(2);
        });

        fireEvent.click(screen.getByLabelText('Subscription route:501'));
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Save notification settings',
            }),
        );

        await waitFor(() => {
            expect(fetchMock).toHaveBeenCalledTimes(3);
        });

        const thirdCall = fetchMock.mock.calls[2] as [string, RequestInit];
        expect(thirdCall[0]).toBe('/settings/notifications');
        expect(thirdCall[1].method).toBe('PATCH');

        const payload = JSON.parse(String(thirdCall[1].body)) as {
            subscriptions: string[];
            alert_type: string;
        };

        expect(payload.subscriptions).toContain('route:501');
        expect(payload.alert_type).toBe('transit');
    });

    it('toggles accessibility alerts and persists alert type as accessibility', async () => {
        const fetchMock = globalThis.fetch as FetchMock;

        fetchMock.mockResolvedValueOnce(
            mockJsonResponse({
                data: {
                    alert_type: 'transit',
                    severity_threshold: 'all',
                    geofences: [],
                    subscriptions: [],
                    digest_mode: false,
                    push_enabled: true,
                },
            }),
        );

        fetchMock.mockResolvedValueOnce(
            mockJsonResponse({
                data: {
                    agency: {
                        urn: 'agency:ttc',
                        name: 'Toronto Transit Commission',
                    },
                    routes: [],
                    stations: [],
                    lines: [],
                },
            }),
        );

        fetchMock.mockResolvedValueOnce(
            mockJsonResponse({
                data: {
                    alert_type: 'accessibility',
                    severity_threshold: 'all',
                    geofences: [],
                    subscriptions: [],
                    digest_mode: false,
                    push_enabled: true,
                },
            }),
        );

        render(<SettingsView authUserId={42} availableRoutes={[]} />);

        await waitFor(() => {
            expect(fetchMock).toHaveBeenCalledTimes(2);
        });

        fireEvent.click(screen.getByLabelText('Accessibility alerts only'));
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Save notification settings',
            }),
        );

        await waitFor(() => {
            expect(fetchMock).toHaveBeenCalledTimes(3);
        });

        const payload = JSON.parse(
            String((fetchMock.mock.calls[2] as [string, RequestInit])[1].body),
        ) as {
            alert_type: string;
        };

        expect(payload.alert_type).toBe('accessibility');
    });

    it('supports station and line tabs in subscription manager', async () => {
        const fetchMock = globalThis.fetch as FetchMock;

        fetchMock.mockResolvedValueOnce(
            mockJsonResponse({
                data: {
                    alert_type: 'transit',
                    severity_threshold: 'all',
                    geofences: [],
                    subscriptions: [],
                    digest_mode: false,
                    push_enabled: true,
                },
            }),
        );

        fetchMock.mockResolvedValueOnce(
            mockJsonResponse({
                data: {
                    agency: {
                        urn: 'agency:ttc',
                        name: 'Toronto Transit Commission',
                    },
                    routes: [{ urn: 'route:504', id: '504', name: '504 King' }],
                    stations: [
                        { urn: 'station:union', slug: 'union', name: 'Union' },
                    ],
                    lines: [
                        {
                            urn: 'line:1',
                            id: '1',
                            name: 'Line 1 Yonge-University',
                        },
                    ],
                },
            }),
        );

        render(<SettingsView authUserId={42} availableRoutes={[]} />);

        await waitFor(() => {
            expect(fetchMock).toHaveBeenCalledTimes(2);
        });

        fireEvent.click(screen.getByRole('button', { name: 'Stations' }));
        expect(screen.getByText('Union')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Lines' }));
        expect(screen.getByText('Line 1 Yonge-University')).toBeInTheDocument();
    });
});
