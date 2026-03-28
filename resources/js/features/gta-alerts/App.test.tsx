import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

import AlertsApp from './App';
import type { UnifiedAlertResource } from './domain/alerts';

vi.mock('./components/SceneIntelTimeline', () => ({
    SceneIntelTimeline: () => <div data-testid="scene-intel-timeline" />,
}));

const LOCATION_STORAGE_KEY = 'gta_weather_location_v1';
const LOCATION_PROMPT_STORAGE_KEY = 'gta_weather_location_prompt_v1';

type ToastHandler = (payload: Record<string, unknown>) => void;

function mockJsonResponse(payload: unknown, ok = true, status = 200): Response {
    return {
        ok,
        status,
        json: async () => payload,
    } as unknown as Response;
}

function setupEchoMock() {
    let handler: ToastHandler | null = null;
    let currentChannel: string | null = null;

    const channel = {
        listen: vi.fn((event: string, callback: ToastHandler) => {
            handler = callback;
            return channel;
        }),
        stopListening: vi.fn(() => channel),
    };

    const echo = {
        private: vi.fn((channelName: string) => {
            currentChannel = channelName;
            return channel;
        }),
        leave: vi.fn((channelName: string) => {
            if (currentChannel === channelName) {
                currentChannel = null;
            }
        }),
    };

    window.Echo = echo as unknown as Window['Echo'];

    return {
        echo,
        channel,
        emit: (payload: Record<string, unknown>) => {
            if (handler) {
                handler(payload);
            }
        },
    };
}

function buildBasePropsWithAuth(
    alerts: UnifiedAlertResource[],
    authUserId: number | null = null,
) {
    return {
        alerts: {
            data: alerts,
            next_cursor: null,
        },
        filters: { status: 'all' as const, sort: 'desc' as const },
        latestFeedUpdatedAt: null,
        authUserId,
    };
}

function buildBaseProps(alerts: UnifiedAlertResource[]) {
    return {
        alerts: {
            data: alerts,
            next_cursor: null,
        },
        filters: { status: 'all' as const, sort: 'desc' as const },
        latestFeedUpdatedAt: null,
        authUserId: null,
    };
}

function setCurrentUrl(path: string) {
    window.history.replaceState({}, '', path);
}

function openAlertDetailsFromFeed(title: string) {
    const alertHeading = screen.getByText(title);
    const alertCard = alertHeading.closest('article');
    expect(alertCard).not.toBeNull();
    fireEvent.click(alertCard as HTMLElement);
}

function domainWarnMessages(warnSpy: ReturnType<typeof vi.spyOn>): string[] {
    return warnSpy.mock.calls
        .map((args: unknown[]) => args[0])
        .filter(
            (firstArg: unknown): firstArg is string =>
                typeof firstArg === 'string' &&
                firstArg.startsWith('[DomainAlert]'),
        );
}

function fireResource(overrides: Partial<UnifiedAlertResource> = {}) {
    const timestamp = new Date('2026-02-03T12:00:00Z').toISOString();
    const base: UnifiedAlertResource = {
        id: 'fire:E1',
        source: 'fire',
        external_id: 'E1',
        is_active: true,
        timestamp,
        title: 'STRUCTURE FIRE',
        location: { name: 'MAIN ST / CROSS RD', lat: null, lng: null },
        meta: {
            alarm_level: 2,
            event_num: 'E1',
            units_dispatched: null,
            beat: null,
        },
    };
    return { ...base, ...overrides };
}

function policeResource(overrides: Partial<UnifiedAlertResource> = {}) {
    const timestamp = new Date('2026-02-03T12:01:00Z').toISOString();
    const base: UnifiedAlertResource = {
        id: 'police:123',
        source: 'police',
        external_id: '123',
        is_active: true,
        timestamp,
        title: 'ASSAULT IN PROGRESS',
        location: { name: '456 POLICE RD', lat: 43.7, lng: -79.4 },
        meta: {
            division: 'D31',
            call_type_code: 'ASLTPR',
            object_id: 123,
        },
    };
    return { ...base, ...overrides };
}

function goTransitResource(overrides: Partial<UnifiedAlertResource> = {}) {
    const timestamp = new Date('2026-02-03T12:03:00Z').toISOString();
    const base: UnifiedAlertResource = {
        id: 'go_transit:12345',
        source: 'go_transit',
        external_id: '12345',
        is_active: true,
        timestamp,
        title: 'Lakeshore East delay',
        location: { name: 'Union Station', lat: 43.7, lng: -79.4 },
        meta: {
            alert_type: 'saag',
            direction: 'Eastbound',
            service_mode: 'Train',
            sub_category: 'TDELAY',
            corridor_code: 'LE',
            trip_number: null,
            delay_duration: '00:15:00',
            line_colour: null,
            message_body: null,
        },
    };
    return { ...base, ...overrides };
}

describe('GTA Alerts App (typed domain enforcement boundary)', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
        vi.useRealTimers();
        localStorage.clear();
        setCurrentUrl('/');
    });

    it('renders valid alerts and discards invalid meta (warns instead of crashing)', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

        const valid = fireResource({ title: 'VALID FIRE' });
        const invalid = fireResource({
            id: 'fire:INVALID',
            external_id: 'INVALID',
            title: 'INVALID FIRE',
            meta: {
                alarm_level: '2',
                event_num: 'INVALID',
                units_dispatched: null,
                beat: null,
            },
        }) as unknown as UnifiedAlertResource;

        expect(() =>
            render(<AlertsApp {...buildBaseProps([valid, invalid])} />),
        ).not.toThrow();

        expect(screen.getByText('VALID FIRE')).toBeInTheDocument();
        expect(screen.queryByText('INVALID FIRE')).not.toBeInTheDocument();

        const messages = domainWarnMessages(warn);
        expect(messages.length).toBeGreaterThanOrEqual(1);
        expect(messages.some((msg) => msg.includes('Invalid fire alert'))).toBe(
            true,
        );

        warn.mockRestore();
    });

    it('discards invalid envelope resources but keeps valid ones', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

        const valid = policeResource({ title: 'VALID POLICE' });
        const invalidEnvelope = policeResource({
            id: 'police:BAD_ENV',
            external_id: 'BAD_ENV',
            title: 'BAD ENVELOPE',
            location: { name: 'X', lat: 'not-a-number', lng: null },
        } as unknown as Partial<UnifiedAlertResource>) as unknown as UnifiedAlertResource;

        expect(() =>
            render(<AlertsApp {...buildBaseProps([valid, invalidEnvelope])} />),
        ).not.toThrow();

        expect(screen.getByText('VALID POLICE')).toBeInTheDocument();
        expect(screen.queryByText('BAD ENVELOPE')).not.toBeInTheDocument();

        const messages = domainWarnMessages(warn);
        expect(messages.length).toBeGreaterThanOrEqual(1);
        expect(
            messages.some((msg) => msg.includes('Invalid resource envelope')),
        ).toBe(true);

        warn.mockRestore();
    });

    it('handles a fully invalid alert list by rendering empty state (no crash)', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

        const invalid1 = fireResource({
            id: 'fire:BAD1',
            external_id: 'BAD1',
            title: 'BAD1',
            meta: {
                alarm_level: 'oops',
                event_num: 'BAD1',
                units_dispatched: null,
                beat: null,
            },
        }) as unknown as UnifiedAlertResource;

        const invalid2 = goTransitResource({
            id: 'go_transit:BAD2',
            external_id: 'BAD2',
            title: 'BAD2',
            meta: {
                alert_type: 'saag',
                direction: 'Eastbound',
                service_mode: 'Train',
                sub_category: 'TDELAY',
                corridor_code: 'LE',
                trip_number: null,
                delay_duration: 123,
                line_colour: null,
                message_body: null,
            },
        }) as unknown as UnifiedAlertResource;

        expect(() =>
            render(<AlertsApp {...buildBaseProps([invalid1, invalid2])} />),
        ).not.toThrow();

        expect(
            screen.getByText('No alerts match your filters'),
        ).toBeInTheDocument();

        const messages = domainWarnMessages(warn);
        expect(messages.length).toBeGreaterThanOrEqual(1);

        warn.mockRestore();
    });

    it('shows saved state on alerts when initialSavedAlertIds are provided', () => {
        const alerts = [fireResource({ id: 'fire:E1', title: 'SAVED FIRE' })];
        render(
            <AlertsApp
                {...buildBasePropsWithAuth(alerts, 42)}
                initialSavedAlertIds={['fire:E1']}
            />,
        );

        const saveBtn = screen.getByLabelText(/Remove from saved/i);
        expect(saveBtn).toHaveClass('bg-primary');
    });

    it('opens notification center from the header notification button', () => {
        render(<AlertsApp {...buildBaseProps([fireResource()])} />);

        fireEvent.click(
            screen.getAllByRole('button', {
                name: 'Open notification center',
            })[0],
        );

        expect(
            screen.getByText('Sign in to view your notification inbox'),
        ).toBeInTheDocument();
    });

    it('shows a first-visit weather location prompt when no weather location is stored', () => {
        render(<AlertsApp {...buildBaseProps([fireResource()])} />);

        expect(
            screen.getByText('Enable local weather for your area?'),
        ).toBeInTheDocument();
    });

    it('does not show first-visit weather prompt when onboarding was already handled', () => {
        localStorage.setItem(
            LOCATION_PROMPT_STORAGE_KEY,
            JSON.stringify({
                handled: true,
                result: 'deferred',
            }),
        );

        render(<AlertsApp {...buildBaseProps([fireResource()])} />);

        expect(
            screen.queryByText('Enable local weather for your area?'),
        ).not.toBeInTheDocument();
    });

    it('hides the first-visit weather prompt when Not now is clicked and persists deferred state', () => {
        render(<AlertsApp {...buildBaseProps([fireResource()])} />);

        fireEvent.click(screen.getByRole('button', { name: 'Not now' }));

        expect(
            screen.queryByText('Enable local weather for your area?'),
        ).not.toBeInTheDocument();
        expect(
            JSON.parse(
                localStorage.getItem(LOCATION_PROMPT_STORAGE_KEY) ?? 'null',
            ),
        ).toEqual({
            handled: true,
            result: 'deferred',
        });
    });

    it('triggers geolocation flow when first-visit prompt Use my location is clicked', () => {
        const mockGetCurrentPosition = vi.fn();
        Object.defineProperty(global.navigator, 'geolocation', {
            value: { getCurrentPosition: mockGetCurrentPosition },
            writable: true,
            configurable: true,
        });

        render(<AlertsApp {...buildBaseProps([fireResource()])} />);

        fireEvent.click(
            screen.getByRole('button', { name: 'Use my location' }),
        );

        expect(mockGetCurrentPosition).toHaveBeenCalledTimes(1);
    });

    it('does not show first-visit prompt when a weather location already exists', () => {
        localStorage.setItem(
            LOCATION_STORAGE_KEY,
            JSON.stringify({
                fsa: 'M5V',
                label: 'M5V — Waterfront Communities, Toronto',
                lat: 43.6406,
                lng: -79.3961,
            }),
        );
        vi.spyOn(global, 'fetch').mockReturnValue(
            new Promise<Response>(() => {
                // Keep request pending to avoid async setState warnings in this
                // visibility-only assertion test.
            }),
        );

        render(<AlertsApp {...buildBaseProps([fireResource()])} />);

        expect(
            screen.queryByText('Enable local weather for your area?'),
        ).not.toBeInTheDocument();
    });

    it('shows weather data in footer when location is selected', async () => {
        localStorage.setItem(
            LOCATION_STORAGE_KEY,
            JSON.stringify({
                fsa: 'M5V',
                label: 'M5V — Waterfront Communities, Toronto',
                lat: 43.6406,
                lng: -79.3961,
            }),
        );

        vi.spyOn(global, 'fetch').mockResolvedValue(
            mockJsonResponse({
                data: {
                    fsa: 'M5V',
                    provider: 'environment_canada',
                    temperature: 12,
                    humidity: 55,
                    wind_speed: '15 km/h',
                    wind_direction: 'W',
                    condition: 'Partly Cloudy',
                    alert_level: null,
                    alert_text: null,
                    fetched_at: '2026-03-28T12:00:00+00:00',
                    feels_like: null,
                    dewpoint: null,
                    pressure: null,
                    visibility: null,
                    wind_gust: null,
                    tendency: null,
                    station_name: null,
                },
            }),
        );

        render(<AlertsApp {...buildBaseProps([fireResource()])} />);

        await waitFor(() => {
            const weatherText = document.getElementById(
                'gta-alerts-footer-weather-text',
            );
            expect(weatherText).toBeInTheDocument();
            expect(weatherText?.textContent).toMatch(/12/);
        });
        expect(
            document.getElementById('gta-alerts-footer-weather-text')
                ?.textContent,
        ).toMatch(/55/);
    });

    it('keeps the mobile drawer closed by default and toggles open/closed from menu controls', () => {
        render(<AlertsApp {...buildBaseProps([fireResource()])} />);

        const openMenuButton = screen.getByRole('button', {
            name: 'Open menu',
        });
        const closeMenuButton = screen.getByRole('button', {
            name: 'Close menu',
        });
        const sidebar = closeMenuButton.closest('aside');

        expect(sidebar).not.toBeNull();
        expect(sidebar).toHaveClass('-translate-x-full');
        expect(sidebar).toHaveClass('pointer-events-none');
        expect(openMenuButton).toHaveAttribute('aria-expanded', 'false');

        fireEvent.click(openMenuButton);

        expect(sidebar).toHaveClass('translate-x-0');
        expect(sidebar).toHaveClass('pointer-events-auto');
        expect(openMenuButton).toHaveAttribute('aria-expanded', 'true');

        fireEvent.click(closeMenuButton);

        expect(sidebar).toHaveClass('-translate-x-full');
        expect(sidebar).toHaveClass('pointer-events-none');
        expect(openMenuButton).toHaveAttribute('aria-expanded', 'false');
    });

    it('closes the mobile drawer when Escape is pressed', () => {
        render(<AlertsApp {...buildBaseProps([fireResource()])} />);

        const openMenuButton = screen.getByRole('button', {
            name: 'Open menu',
        });
        const closeMenuButton = screen.getByRole('button', {
            name: 'Close menu',
        });
        const sidebar = closeMenuButton.closest('aside');

        expect(sidebar).not.toBeNull();

        fireEvent.click(openMenuButton);
        expect(sidebar).toHaveClass('translate-x-0');

        fireEvent.keyDown(window, { key: 'Escape' });

        expect(sidebar).toHaveClass('-translate-x-full');
    });

    it('opens alert details when selecting an inbox alert summary', async () => {
        const fetchMock = vi.fn();

        vi.stubGlobal('fetch', fetchMock);

        try {
            fetchMock
                .mockResolvedValueOnce(
                    mockJsonResponse({
                        data: [
                            {
                                id: 41,
                                alert_id: 'fire:E1',
                                type: 'alert',
                                delivery_method: 'in_app',
                                status: 'delivered',
                                sent_at: '2026-02-10T15:00:00+00:00',
                                read_at: null,
                                dismissed_at: null,
                                metadata: {
                                    source: 'fire',
                                    summary:
                                        'Structure fire reported on Main St',
                                },
                            },
                        ],
                        meta: {
                            current_page: 1,
                            last_page: 1,
                            per_page: 50,
                            total: 1,
                            unread_count: 1,
                        },
                        links: {
                            next: null,
                            prev: null,
                        },
                    }),
                )
                .mockResolvedValue(
                    mockJsonResponse({
                        data: [],
                        meta: { event_num: 'E1', count: 0 },
                    }),
                );

            render(
                <AlertsApp {...buildBasePropsWithAuth([fireResource()], 42)} />,
            );

            fireEvent.click(
                screen.getAllByRole('button', {
                    name: 'Open notification center',
                })[0],
            );

            fireEvent.click(
                await screen.findByRole('button', {
                    name: 'Structure fire reported on Main St',
                }),
            );

            expect(screen.getByText('Incident Details')).toBeInTheDocument();
            expect(screen.getByText(/FIRE:E1/)).toBeInTheDocument();
        } finally {
            vi.unstubAllGlobals();
        }
    });

    it('opens alert details from URL query when alert ID exists', () => {
        setCurrentUrl('/?alert=fire%3AE1');

        render(<AlertsApp {...buildBaseProps([fireResource()])} />);

        expect(screen.getByText('Incident Details')).toBeInTheDocument();
        expect(screen.getByText(/FIRE:E1/)).toBeInTheDocument();
    });

    it('clears invalid alert query parameter when alert ID is missing', async () => {
        setCurrentUrl('/?alert=fire%3AMISSING');

        render(<AlertsApp {...buildBaseProps([fireResource()])} />);

        expect(screen.queryByText('Incident Details')).not.toBeInTheDocument();

        await waitFor(() => {
            expect(
                new URL(window.location.href).searchParams.has('alert'),
            ).toBe(false);
        });
    });

    it('updates URL when opening details and removes alert param when leaving details', () => {
        render(<AlertsApp {...buildBaseProps([fireResource()])} />);

        openAlertDetailsFromFeed('STRUCTURE FIRE');

        expect(screen.getByText('Incident Details')).toBeInTheDocument();
        expect(new URL(window.location.href).searchParams.get('alert')).toBe(
            'fire:E1',
        );

        fireEvent.click(screen.getByRole('button', { name: /arrow_back/i }));

        expect(new URL(window.location.href).searchParams.has('alert')).toBe(
            false,
        );
    });

    it('uses native share when available from Share Alert action', async () => {
        const nativeShare = vi.fn().mockResolvedValue(undefined);
        Object.defineProperty(window.navigator, 'share', {
            configurable: true,
            value: nativeShare,
        });

        render(<AlertsApp {...buildBaseProps([fireResource()])} />);

        openAlertDetailsFromFeed('STRUCTURE FIRE');
        fireEvent.click(screen.getByRole('button', { name: /Share Alert/i }));

        await waitFor(() => {
            expect(nativeShare).toHaveBeenCalledTimes(1);
        });

        expect(nativeShare).toHaveBeenCalledWith(
            expect.objectContaining({
                title: 'GTA Alert',
                text: 'STRUCTURE FIRE',
                url: expect.stringContaining('alert=fire%3AE1'),
            }),
        );
        expect(
            await screen.findByText('Alert link shared.'),
        ).toBeInTheDocument();
    });

    it('falls back to clipboard copy when native share is unavailable', async () => {
        const clipboardWriteText = vi.fn().mockResolvedValue(undefined);
        Object.defineProperty(window.navigator, 'share', {
            configurable: true,
            value: undefined,
        });
        Object.defineProperty(window.navigator, 'clipboard', {
            configurable: true,
            value: {
                writeText: clipboardWriteText,
            },
        });

        render(<AlertsApp {...buildBaseProps([fireResource()])} />);

        openAlertDetailsFromFeed('STRUCTURE FIRE');
        fireEvent.click(screen.getByRole('button', { name: /Share Alert/i }));

        await waitFor(() => {
            expect(clipboardWriteText).toHaveBeenCalledTimes(1);
        });
        expect(clipboardWriteText).toHaveBeenCalledWith(
            expect.stringContaining('alert=fire%3AE1'),
        );
        expect(
            await screen.findByText('Alert link copied.'),
        ).toBeInTheDocument();
    });

    it('shows a saved-alert action toast for guest saves', async () => {
        render(<AlertsApp {...buildBaseProps([fireResource()])} />);

        fireEvent.click(screen.getByLabelText('Save alert'));

        expect(await screen.findByText('Alert saved.')).toBeInTheDocument();
        expect(screen.getByText('Saved Alert')).toBeInTheDocument();
    });

    it('shows a saved-alert action toast for authenticated saves', async () => {
        const fetchMock = vi.fn().mockResolvedValue(
            mockJsonResponse(
                {
                    data: {
                        id: 1,
                        alert_id: 'fire:E1',
                        saved_at: '2026-03-18T12:00:00Z',
                    },
                },
                true,
                201,
            ),
        );

        vi.stubGlobal('fetch', fetchMock);

        try {
            render(
                <AlertsApp {...buildBasePropsWithAuth([fireResource()], 42)} />,
            );

            fireEvent.click(screen.getByLabelText('Save alert'));

            expect(await screen.findByText('Alert saved.')).toBeInTheDocument();
            expect(fetchMock).toHaveBeenCalledWith(
                '/api/saved-alerts',
                expect.objectContaining({ method: 'POST' }),
            );
        } finally {
            vi.unstubAllGlobals();
        }
    });

    it('auto-dismisses the saved-alert action toast', async () => {
        vi.useFakeTimers();

        render(<AlertsApp {...buildBaseProps([fireResource()])} />);

        fireEvent.click(screen.getByLabelText('Save alert'));

        expect(screen.getByText('Alert saved.')).toBeInTheDocument();

        act(() => {
            vi.advanceTimersByTime(4500);
        });

        expect(screen.queryByText('Alert saved.')).not.toBeInTheDocument();
    });
});

describe('GTA Alerts App - Notification Toast Layer Integration', () => {
    beforeEach(() => {
        vi.stubGlobal('Echo', undefined);
    });

    afterEach(() => {
        delete window.Echo;
        vi.restoreAllMocks();
    });

    describe('toast layer mounting for authenticated users', () => {
        it('mounts NotificationToastLayer and subscribes to private channel for authenticated users', () => {
            const { echo, channel } = setupEchoMock();

            render(
                <AlertsApp
                    {...buildBasePropsWithAuth(
                        [fireResource({ title: 'TEST' })],
                        42,
                    )}
                />,
            );

            // Should subscribe to user's private notification channel
            expect(echo.private).toHaveBeenCalledWith('users.42.notifications');
            expect(channel.listen).toHaveBeenCalledWith(
                '.alert.notification.sent',
                expect.any(Function),
            );
        });

        it('does not subscribe to channel for guests', () => {
            const { echo } = setupEchoMock();

            render(
                <AlertsApp
                    {...buildBasePropsWithAuth(
                        [fireResource({ title: 'TEST' })],
                        null,
                    )}
                />,
            );

            // Should not subscribe to any channel
            expect(echo.private).not.toHaveBeenCalled();
        });
    });

    describe('channel cleanup on unmount', () => {
        it('unsubscribes and leaves channel when app unmounts', () => {
            const { echo, channel } = setupEchoMock();

            const { unmount } = render(
                <AlertsApp
                    {...buildBasePropsWithAuth(
                        [fireResource({ title: 'UNMOUNT TEST' })],
                        42,
                    )}
                />,
            );

            // Verify subscription was made
            expect(echo.private).toHaveBeenCalledWith('users.42.notifications');

            unmount();

            // Should clean up subscriptions
            expect(channel.stopListening).toHaveBeenCalledWith(
                '.alert.notification.sent',
            );
            expect(echo.leave).toHaveBeenCalledWith('users.42.notifications');
        });
    });

    describe('auth user transitions', () => {
        it('transitions channel subscription when authUserId changes', () => {
            const { echo } = setupEchoMock();

            const { rerender } = render(
                <AlertsApp
                    {...buildBasePropsWithAuth(
                        [fireResource({ title: 'TEST' })],
                        42,
                    )}
                />,
            );

            expect(echo.private).toHaveBeenCalledWith('users.42.notifications');
            expect(echo.private).toHaveBeenCalledTimes(1);

            // Change to different user
            rerender(
                <AlertsApp
                    {...buildBasePropsWithAuth(
                        [fireResource({ title: 'TEST' })],
                        99,
                    )}
                />,
            );

            // Should leave old channel and join new
            expect(echo.leave).toHaveBeenCalledWith('users.42.notifications');
            expect(echo.private).toHaveBeenCalledWith('users.99.notifications');
            expect(echo.private).toHaveBeenCalledTimes(2);
        });

        it('cleans up subscription when user logs out', () => {
            const { echo } = setupEchoMock();

            const { rerender } = render(
                <AlertsApp
                    {...buildBasePropsWithAuth(
                        [fireResource({ title: 'TEST' })],
                        42,
                    )}
                />,
            );

            expect(echo.private).toHaveBeenCalledTimes(1);

            // Log out user (authUserId becomes null)
            rerender(
                <AlertsApp
                    {...buildBasePropsWithAuth(
                        [fireResource({ title: 'TEST' })],
                        null,
                    )}
                />,
            );

            expect(echo.leave).toHaveBeenCalledWith('users.42.notifications');
            // Should not create new private subscription
            expect(echo.private).toHaveBeenCalledTimes(1);
        });
    });
});
