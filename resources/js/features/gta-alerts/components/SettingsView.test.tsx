import {
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
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

    describe('guest user behavior', () => {
        it('shows sign-in UX and does not call preferences API for guests', () => {
            const fetchMock = globalThis.fetch as FetchMock;

            render(<SettingsView authUserId={null} availableRoutes={['1']} />);

            expect(
                screen.getByText(
                    'Sign in to configure notification preferences',
                ),
            ).toBeInTheDocument();
            expect(fetchMock).not.toHaveBeenCalled();
        });
    });

    describe('authenticated user preferences', () => {
        it('loads preferences for authenticated users and saves updates', async () => {
            const fetchMock = globalThis.fetch as FetchMock;

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

            render(
                <SettingsView
                    authUserId={99}
                    availableRoutes={['1', 'GO-LW']}
                />,
            );

            await waitFor(() => {
                expect(fetchMock).toHaveBeenCalledTimes(1);
            });

            const goRouteToggle = screen.getByLabelText(
                'Route GO-LW',
            ) as HTMLInputElement;
            fireEvent.click(goRouteToggle);
            expect(goRouteToggle.checked).toBe(true);

            fireEvent.click(
                screen.getByRole('button', {
                    name: 'Save notification settings',
                }),
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

    describe('route options merge, deduplication, and sorting', () => {
        it('merges routes from availableRoutes, subscribed_routes, and KNOWN_TRANSIT_ROUTES', async () => {
            const fetchMock = globalThis.fetch as FetchMock;

            fetchMock.mockResolvedValueOnce(
                mockJsonResponse({
                    data: {
                        alert_type: 'transit',
                        severity_threshold: 'all',
                        geofences: [],
                        subscribed_routes: ['99', '501'],
                        digest_mode: false,
                        push_enabled: true,
                    },
                }),
            );

            render(
                <SettingsView
                    authUserId={42}
                    availableRoutes={['1', '2', '501']}
                />,
            );

            await waitFor(() => {
                expect(fetchMock).toHaveBeenCalledTimes(1);
            });

            const routeCheckboxes = screen
                .getAllByRole('checkbox')
                .filter((el) => {
                    const label = el.getAttribute('aria-label');
                    return label?.startsWith('Route ');
                });

            const routeIds = routeCheckboxes.map((el) => {
                const label = el.getAttribute('aria-label') || '';
                return label.replace('Route ', '');
            });

            const uniqueRouteIds = [...new Set(routeIds)];
            expect(routeIds.length).toBe(uniqueRouteIds.length);

            expect(routeIds).toContain('1');
            expect(routeIds).toContain('2');
            expect(routeIds).toContain('501');
            expect(routeIds).toContain('99');

            const sortedRouteIds = [...routeIds].sort((a, b) =>
                a.localeCompare(b, undefined, { numeric: true }),
            );
            expect(routeIds).toEqual(sortedRouteIds);
        });

        it('deduplicates routes case-insensitively and trims whitespace', async () => {
            const fetchMock = globalThis.fetch as FetchMock;

            fetchMock.mockResolvedValueOnce(
                mockJsonResponse({
                    data: {
                        alert_type: 'transit',
                        severity_threshold: 'all',
                        geofences: [],
                        subscribed_routes: ['  1  '],
                        digest_mode: false,
                        push_enabled: true,
                    },
                }),
            );

            render(<SettingsView authUserId={42} availableRoutes={['1']} />);

            await waitFor(() => {
                expect(fetchMock).toHaveBeenCalledTimes(1);
            });

            const routeCheckboxes = screen
                .getAllByRole('checkbox')
                .filter((el) => {
                    const label = el.getAttribute('aria-label');
                    return label?.startsWith('Route ');
                });

            const routeOneCount = routeCheckboxes.filter((el) => {
                const label = el.getAttribute('aria-label');
                return label === 'Route 1';
            }).length;

            expect(routeOneCount).toBe(1);
        });

        it('filters out empty route strings', async () => {
            const fetchMock = globalThis.fetch as FetchMock;

            fetchMock.mockResolvedValueOnce(
                mockJsonResponse({
                    data: {
                        alert_type: 'transit',
                        severity_threshold: 'all',
                        geofences: [],
                        subscribed_routes: ['', '1', '  '],
                        digest_mode: false,
                        push_enabled: true,
                    },
                }),
            );

            render(
                <SettingsView authUserId={42} availableRoutes={['', '1']} />,
            );

            await waitFor(() => {
                expect(fetchMock).toHaveBeenCalledTimes(1);
            });

            const routeCheckboxes = screen
                .getAllByRole('checkbox')
                .filter((el) => {
                    const label = el.getAttribute('aria-label');
                    return label?.startsWith('Route ');
                });

            const emptyRouteLabels = routeCheckboxes.filter((el) => {
                const label = el.getAttribute('aria-label');
                return label === 'Route ';
            });

            expect(emptyRouteLabels.length).toBe(0);
        });
    });

    describe('alert-type dependent route disabling', () => {
        it('disables route filters when alert type is emergency', async () => {
            const fetchMock = globalThis.fetch as FetchMock;

            fetchMock.mockResolvedValueOnce(
                mockJsonResponse({
                    data: {
                        alert_type: 'transit',
                        severity_threshold: 'all',
                        geofences: [],
                        subscribed_routes: ['1'],
                        digest_mode: false,
                        push_enabled: true,
                    },
                }),
            );

            render(
                <SettingsView authUserId={42} availableRoutes={['1', '2']} />,
            );

            await waitFor(() => {
                expect(fetchMock).toHaveBeenCalledTimes(1);
            });

            const routeCheckbox = screen.getByLabelText(
                'Route 1',
            ) as HTMLInputElement;
            expect(routeCheckbox.disabled).toBe(false);

            const alertTypeSelect = screen.getByDisplayValue('Transit only');
            fireEvent.change(alertTypeSelect, {
                target: { value: 'emergency' },
            });

            await waitFor(() => {
                const disabledRouteCheckbox = screen.getByLabelText(
                    'Route 1',
                ) as HTMLInputElement;
                expect(disabledRouteCheckbox.disabled).toBe(true);
            });

            expect(
                screen.getByText('Disabled when source is non-transit'),
            ).toBeInTheDocument();
        });

        it('disables route filters when alert type is accessibility', async () => {
            const fetchMock = globalThis.fetch as FetchMock;

            fetchMock.mockResolvedValueOnce(
                mockJsonResponse({
                    data: {
                        alert_type: 'transit',
                        severity_threshold: 'all',
                        geofences: [],
                        subscribed_routes: ['1'],
                        digest_mode: false,
                        push_enabled: true,
                    },
                }),
            );

            render(
                <SettingsView authUserId={42} availableRoutes={['1', '2']} />,
            );

            await waitFor(() => {
                expect(fetchMock).toHaveBeenCalledTimes(1);
            });

            const alertTypeSelect = screen.getByDisplayValue('Transit only');
            fireEvent.change(alertTypeSelect, {
                target: { value: 'accessibility' },
            });

            await waitFor(() => {
                const disabledRouteCheckbox = screen.getByLabelText(
                    'Route 1',
                ) as HTMLInputElement;
                expect(disabledRouteCheckbox.disabled).toBe(true);
            });
        });

        it('re-enables route filters when switching back to transit alert type', async () => {
            const fetchMock = globalThis.fetch as FetchMock;

            fetchMock.mockResolvedValueOnce(
                mockJsonResponse({
                    data: {
                        alert_type: 'emergency',
                        severity_threshold: 'all',
                        geofences: [],
                        subscribed_routes: ['1'],
                        digest_mode: false,
                        push_enabled: true,
                    },
                }),
            );

            render(
                <SettingsView authUserId={42} availableRoutes={['1', '2']} />,
            );

            await waitFor(() => {
                expect(fetchMock).toHaveBeenCalledTimes(1);
            });

            const routeCheckbox = screen.getByLabelText(
                'Route 1',
            ) as HTMLInputElement;
            expect(routeCheckbox.disabled).toBe(true);

            const alertTypeSelect = screen.getByDisplayValue('Emergency only');
            fireEvent.change(alertTypeSelect, { target: { value: 'transit' } });

            await waitFor(() => {
                const enabledRouteCheckbox = screen.getByLabelText(
                    'Route 1',
                ) as HTMLInputElement;
                expect(enabledRouteCheckbox.disabled).toBe(false);
            });
        });
    });

    describe('geofence management', () => {
        it('adds geofence zones correctly', async () => {
            const fetchMock = globalThis.fetch as FetchMock;

            fetchMock.mockResolvedValueOnce(
                mockJsonResponse({
                    data: {
                        alert_type: 'all',
                        severity_threshold: 'all',
                        geofences: [],
                        subscribed_routes: [],
                        digest_mode: false,
                        push_enabled: true,
                    },
                }),
            );

            render(<SettingsView authUserId={42} availableRoutes={[]} />);

            await waitFor(() => {
                expect(fetchMock).toHaveBeenCalledTimes(1);
            });

            expect(
                screen.getByText('No zones configured yet.'),
            ).toBeInTheDocument();

            const addButton = screen.getByRole('button', { name: 'Add zone' });
            fireEvent.click(addButton);

            await waitFor(() => {
                expect(
                    screen.getByText('Downtown Core (2 km)'),
                ).toBeInTheDocument();
            });

            expect(
                screen.queryByText('No zones configured yet.'),
            ).not.toBeInTheDocument();
        });

        it('prevents duplicate geofence additions', async () => {
            const fetchMock = globalThis.fetch as FetchMock;

            fetchMock.mockResolvedValueOnce(
                mockJsonResponse({
                    data: {
                        alert_type: 'all',
                        severity_threshold: 'all',
                        geofences: [
                            {
                                name: 'Downtown Core',
                                lat: 43.6535,
                                lng: -79.3839,
                                radius_km: 2,
                            },
                        ],
                        subscribed_routes: [],
                        digest_mode: false,
                        push_enabled: true,
                    },
                }),
            );

            render(<SettingsView authUserId={42} availableRoutes={[]} />);

            await waitFor(() => {
                expect(fetchMock).toHaveBeenCalledTimes(1);
            });

            const addButton = screen.getByRole('button', { name: 'Add zone' });
            fireEvent.click(addButton);

            await waitFor(() => {
                expect(
                    screen.getByText(
                        'That zone and radius combination is already added.',
                    ),
                ).toBeInTheDocument();
            });
        });

        it('allows same zone with different radius', async () => {
            const fetchMock = globalThis.fetch as FetchMock;

            fetchMock.mockResolvedValueOnce(
                mockJsonResponse({
                    data: {
                        alert_type: 'all',
                        severity_threshold: 'all',
                        geofences: [
                            {
                                name: 'Downtown Core',
                                lat: 43.6535,
                                lng: -79.3839,
                                radius_km: 2,
                            },
                        ],
                        subscribed_routes: [],
                        digest_mode: false,
                        push_enabled: true,
                    },
                }),
            );

            render(<SettingsView authUserId={42} availableRoutes={[]} />);

            await waitFor(() => {
                expect(fetchMock).toHaveBeenCalledTimes(1);
            });

            const radiusSelect = screen.getByDisplayValue('2 km radius');
            fireEvent.change(radiusSelect, { target: { value: '5' } });

            const addButton = screen.getByRole('button', { name: 'Add zone' });
            fireEvent.click(addButton);

            await waitFor(() => {
                expect(
                    screen.getByText('Downtown Core (2 km)'),
                ).toBeInTheDocument();
                expect(
                    screen.getByText('Downtown Core (5 km)'),
                ).toBeInTheDocument();
            });
        });

        it('removes geofence zones correctly', async () => {
            const fetchMock = globalThis.fetch as FetchMock;

            fetchMock.mockResolvedValueOnce(
                mockJsonResponse({
                    data: {
                        alert_type: 'all',
                        severity_threshold: 'all',
                        geofences: [
                            {
                                name: 'Downtown Core',
                                lat: 43.6535,
                                lng: -79.3839,
                                radius_km: 2,
                            },
                            {
                                name: 'North York Centre',
                                lat: 43.7615,
                                lng: -79.4111,
                                radius_km: 5,
                            },
                        ],
                        subscribed_routes: [],
                        digest_mode: false,
                        push_enabled: true,
                    },
                }),
            );

            render(<SettingsView authUserId={42} availableRoutes={[]} />);

            await waitFor(() => {
                expect(fetchMock).toHaveBeenCalledTimes(1);
            });

            expect(
                screen.getByText('Downtown Core (2 km)'),
            ).toBeInTheDocument();
            expect(
                screen.getByText('North York Centre (5 km)'),
            ).toBeInTheDocument();

            // Get all zone containers and find the one with Downtown Core
            const zoneContainers = screen
                .getAllByText(/Downtown Core|North York Centre/)
                .map((el) => el.closest('div.flex'));

            const downtownContainer = zoneContainers.find((el) =>
                el?.textContent?.includes('Downtown Core'),
            );

            const removeButtons = within(
                downtownContainer!.parentElement!,
            ).getAllByRole('button');
            // Find the Remove button among all buttons in this container
            const removeButton = removeButtons.find(
                (btn) => btn.textContent === 'Remove',
            );

            fireEvent.click(removeButton!);

            await waitFor(() => {
                expect(
                    screen.queryByText('Downtown Core (2 km)'),
                ).not.toBeInTheDocument();
            });

            expect(
                screen.getByText('North York Centre (5 km)'),
            ).toBeInTheDocument();
        });

        it('shows correct coordinates for geofence zones', async () => {
            const fetchMock = globalThis.fetch as FetchMock;

            fetchMock.mockResolvedValueOnce(
                mockJsonResponse({
                    data: {
                        alert_type: 'all',
                        severity_threshold: 'all',
                        geofences: [
                            {
                                name: 'Downtown Core',
                                lat: 43.6535,
                                lng: -79.3839,
                                radius_km: 2,
                            },
                        ],
                        subscribed_routes: [],
                        digest_mode: false,
                        push_enabled: true,
                    },
                }),
            );

            render(<SettingsView authUserId={42} availableRoutes={[]} />);

            await waitFor(() => {
                expect(fetchMock).toHaveBeenCalledTimes(1);
            });

            expect(screen.getByText('43.6535, -79.3839')).toBeInTheDocument();
        });
    });

    describe('API error handling', () => {
        it('shows authentication error message on 401 during load', async () => {
            const fetchMock = globalThis.fetch as FetchMock;

            fetchMock.mockResolvedValueOnce(
                mockJsonResponse({ message: 'Unauthorized' }, false, 401),
            );

            render(<SettingsView authUserId={42} availableRoutes={[]} />);

            await waitFor(() => {
                expect(
                    screen.getByText(
                        'Please sign in again to manage settings.',
                    ),
                ).toBeInTheDocument();
            });
        });

        it('shows generic error message on non-401 errors during load', async () => {
            const fetchMock = globalThis.fetch as FetchMock;

            fetchMock.mockResolvedValueOnce(
                mockJsonResponse({ message: 'Server error' }, false, 500),
            );

            render(<SettingsView authUserId={42} availableRoutes={[]} />);

            await waitFor(() => {
                expect(
                    screen.getByText(
                        'Unable to load notification preferences right now.',
                    ),
                ).toBeInTheDocument();
            });
        });

        it('shows validation error message on 422 during save', async () => {
            const fetchMock = globalThis.fetch as FetchMock;

            fetchMock.mockResolvedValueOnce(
                mockJsonResponse({
                    data: {
                        alert_type: 'transit',
                        severity_threshold: 'all',
                        geofences: [],
                        subscribed_routes: [],
                        digest_mode: false,
                        push_enabled: true,
                    },
                }),
            );

            fetchMock.mockResolvedValueOnce(
                mockJsonResponse({ message: 'Validation failed' }, false, 422),
            );

            render(<SettingsView authUserId={42} availableRoutes={[]} />);

            await waitFor(() => {
                expect(fetchMock).toHaveBeenCalledTimes(1);
            });

            fireEvent.click(
                screen.getByRole('button', {
                    name: 'Save notification settings',
                }),
            );

            await waitFor(() => {
                expect(
                    screen.getByText(
                        'One or more settings are invalid. Please review your selections.',
                    ),
                ).toBeInTheDocument();
            });
        });

        it('shows generic error message on non-422 errors during save', async () => {
            const fetchMock = globalThis.fetch as FetchMock;

            fetchMock.mockResolvedValueOnce(
                mockJsonResponse({
                    data: {
                        alert_type: 'transit',
                        severity_threshold: 'all',
                        geofences: [],
                        subscribed_routes: [],
                        digest_mode: false,
                        push_enabled: true,
                    },
                }),
            );

            fetchMock.mockResolvedValueOnce(
                mockJsonResponse({ message: 'Network error' }, false, 503),
            );

            render(<SettingsView authUserId={42} availableRoutes={[]} />);

            await waitFor(() => {
                expect(fetchMock).toHaveBeenCalledTimes(1);
            });

            fireEvent.click(
                screen.getByRole('button', {
                    name: 'Save notification settings',
                }),
            );

            await waitFor(() => {
                expect(
                    screen.getByText(
                        'Unable to save changes right now. Please try again shortly.',
                    ),
                ).toBeInTheDocument();
            });
        });
    });

    describe('preference update behavior', () => {
        it('updates push enabled setting correctly', async () => {
            const fetchMock = globalThis.fetch as FetchMock;

            fetchMock.mockResolvedValueOnce(
                mockJsonResponse({
                    data: {
                        alert_type: 'all',
                        severity_threshold: 'all',
                        geofences: [],
                        subscribed_routes: [],
                        digest_mode: false,
                        push_enabled: true,
                    },
                }),
            );

            render(<SettingsView authUserId={42} availableRoutes={[]} />);

            await waitFor(() => {
                expect(fetchMock).toHaveBeenCalledTimes(1);
            });

            const checkboxes = screen.getAllByRole('checkbox');
            const pushEnabledCheckbox = checkboxes.find((el) => {
                const label = el.closest('label');
                return label?.textContent?.includes(
                    'Enable real-time push toasts',
                );
            }) as HTMLInputElement;

            expect(pushEnabledCheckbox).toBeDefined();
            expect(pushEnabledCheckbox.checked).toBe(true);

            fireEvent.click(pushEnabledCheckbox);
            expect(pushEnabledCheckbox.checked).toBe(false);
        });

        it('updates digest mode setting correctly', async () => {
            const fetchMock = globalThis.fetch as FetchMock;

            fetchMock.mockResolvedValueOnce(
                mockJsonResponse({
                    data: {
                        alert_type: 'all',
                        severity_threshold: 'all',
                        geofences: [],
                        subscribed_routes: [],
                        digest_mode: false,
                        push_enabled: true,
                    },
                }),
            );

            render(<SettingsView authUserId={42} availableRoutes={[]} />);

            await waitFor(() => {
                expect(fetchMock).toHaveBeenCalledTimes(1);
            });

            const checkboxes = screen.getAllByRole('checkbox');
            const digestModeCheckbox = checkboxes.find((el) => {
                const label = el.closest('label');
                return label?.textContent?.includes('Enable daily digest mode');
            }) as HTMLInputElement;

            expect(digestModeCheckbox).toBeDefined();
            expect(digestModeCheckbox.checked).toBe(false);

            fireEvent.click(digestModeCheckbox);
            expect(digestModeCheckbox.checked).toBe(true);
        });

        it('clears success message when user makes changes after save', async () => {
            const fetchMock = globalThis.fetch as FetchMock;

            fetchMock.mockResolvedValueOnce(
                mockJsonResponse({
                    data: {
                        alert_type: 'transit',
                        severity_threshold: 'all',
                        geofences: [],
                        subscribed_routes: [],
                        digest_mode: false,
                        push_enabled: true,
                    },
                }),
            );

            fetchMock.mockResolvedValueOnce(
                mockJsonResponse({
                    data: {
                        alert_type: 'transit',
                        severity_threshold: 'all',
                        geofences: [],
                        subscribed_routes: [],
                        digest_mode: false,
                        push_enabled: true,
                    },
                }),
            );

            render(<SettingsView authUserId={42} availableRoutes={[]} />);

            await waitFor(() => {
                expect(fetchMock).toHaveBeenCalledTimes(1);
            });

            fireEvent.click(
                screen.getByRole('button', {
                    name: 'Save notification settings',
                }),
            );

            await waitFor(() => {
                expect(
                    screen.getByText('Notification settings saved.'),
                ).toBeInTheDocument();
            });

            const severitySelect = screen.getByLabelText('Severity threshold');
            fireEvent.change(severitySelect, { target: { value: 'major' } });

            await waitFor(() => {
                expect(
                    screen.queryByText('Notification settings saved.'),
                ).not.toBeInTheDocument();
            });
        });
    });
});
