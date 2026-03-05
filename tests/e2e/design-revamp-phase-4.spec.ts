import { fireEvent, render, screen, within } from '@testing-library/react';
import React from 'react';
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import AlertsApp from '../../resources/js/features/gta-alerts/App';
import { FeedView } from '../../resources/js/features/gta-alerts/components/FeedView';
import type { UnifiedAlertResource } from '../../resources/js/features/gta-alerts/domain/alerts';

const inertiaRouterMocks = vi.hoisted(() => {
    const listeners = new Map<string, Set<(event: unknown) => void>>();

    return {
        on: vi.fn((event: string, callback: (event: unknown) => void) => {
            const eventListeners = listeners.get(event) ?? new Set();
            eventListeners.add(callback);
            listeners.set(event, eventListeners);

            return () => {
                listeners.get(event)?.delete(callback);
            };
        }),
        get: vi.fn(),
        reset: () => {
            listeners.clear();
        },
    };
});

vi.mock('@inertiajs/react', async () => {
    const actual = (await vi.importActual('@inertiajs/react')) as {
        router: { on: (...args: unknown[]) => unknown };
    };

    return {
        ...actual,
        router: {
            ...actual.router,
            on: inertiaRouterMocks.on,
            get: inertiaRouterMocks.get,
        },
    };
});

vi.mock('@/routes', () => ({
    home: () => ({ url: '/' }),
}));

/**
 * Phase 4 verification spec for the UI Design Revamp (Prototype Two).
 *
 * This file is executable Vitest coverage for the Phase 4 interaction
 * contracts that were previously tracked as checklist-only E2E notes.
 */
export const designRevampPhase4Spec = {
    runtimeAssumptions: {
        engine: 'Vitest + jsdom',
        authState: 'anonymous/local session (no explicit login bootstrap)',
        desktopViewport: 'jsdom desktop shell coverage',
        mobileViewport: 'jsdom mobile shell coverage',
    },
    scenarios: [
        {
            id: 'shell-desktop-parity',
            checks: [
                'Sidebar renders with GTA Alerts nav actions',
                'Header search affordance and icon actions render',
                'Footer links/stats render in the shell',
                'Refresh FAB is visible in the feed shell',
            ],
        },
        {
            id: 'feed-table-toggle-contract',
            checks: [
                'Feed/Table toggle is client-side and does not navigate URL',
                'Feed mode shows alert cards',
                'Table mode shows incident table rows with expand controls',
            ],
        },
        {
            id: 'table-interaction-contract',
            checks: [
                'Expand affordance toggles summary row without navigation side-effects',
                'Summary CTA opens AlertDetailsView state',
                'In-app back control returns to feed shell state',
            ],
        },
        {
            id: 'feed-card-status-parity',
            checks: [
                'Active alerts retain high-visibility styling',
                'Cleared alerts use muted/grayscale treatment while remaining legible',
            ],
        },
        {
            id: 'responsive-mobile-parity',
            checks: [
                'Drawer opens/closes with menu actions and Escape key',
                'Bottom nav coexists with feed shell on mobile',
                'Refresh FAB reserves clearance above bottom nav touch targets',
            ],
        },
    ],
} as const;

function buildAlerts(): UnifiedAlertResource[] {
    const timestamp = new Date('2026-02-03T12:00:00Z').toISOString();

    return [
        {
            id: 'fire:E1',
            source: 'fire',
            external_id: 'E1',
            is_active: true,
            timestamp,
            title: 'STRUCTURE FIRE',
            location: { name: '123 Fire St', lat: null, lng: null },
            meta: {
                alarm_level: 2,
                units_dispatched: 'P1, A1',
                beat: 'B1',
                event_num: 'E1',
            },
        },
        {
            id: 'police:123',
            source: 'police',
            external_id: '123',
            is_active: false,
            timestamp,
            title: 'CLEARED THEFT',
            location: { name: '456 Police Rd', lat: 43.7, lng: -79.4 },
            meta: {
                division: 'D31',
                call_type_code: 'THEFT',
                object_id: 123,
            },
        },
    ];
}

function buildAppProps(alerts: UnifiedAlertResource[] = buildAlerts()) {
    return {
        alerts: {
            data: alerts,
            next_cursor: null,
        },
        filters: {
            status: 'all' as const,
            source: null,
            q: null,
            since: null,
        },
        latestFeedUpdatedAt: new Date('2026-02-03T12:05:00Z').toISOString(),
        authUserId: null,
        subscriptionRouteOptions: [],
    };
}

beforeAll(() => {
    if (typeof window.matchMedia === 'function') {
        return;
    }

    Object.defineProperty(window, 'matchMedia', {
        writable: true,
        value: vi.fn().mockImplementation((query: string) => ({
            matches: false,
            media: query,
            onchange: null,
            addEventListener: vi.fn(),
            removeEventListener: vi.fn(),
            addListener: vi.fn(),
            removeListener: vi.fn(),
            dispatchEvent: vi.fn(),
        })),
    });
});

beforeEach(() => {
    inertiaRouterMocks.reset();
    inertiaRouterMocks.on.mockClear();
    inertiaRouterMocks.get.mockClear();

    vi.stubGlobal(
        'fetch',
        vi.fn(() =>
            Promise.resolve({
                ok: true,
                json: () =>
                    Promise.resolve({
                        data: [],
                        meta: { event_num: 'E1', count: 0 },
                    }),
            }),
        ),
    );
});

describe(designRevampPhase4Spec.scenarios[0].id, () => {
    it('renders the shell navigation, actions, footer, and refresh control', () => {
        render(React.createElement(AlertsApp, buildAppProps()));

        expect(
            document.getElementById('gta-alerts-sidebar-nav-feed-btn'),
        ).toBeInTheDocument();
        expect(
            document.getElementById('gta-alerts-sidebar-nav-inbox-btn'),
        ).toBeInTheDocument();
        expect(
            document.getElementById('gta-alerts-sidebar-nav-saved-btn'),
        ).toBeInTheDocument();
        expect(
            document.getElementById('gta-alerts-sidebar-nav-zones-btn'),
        ).toBeInTheDocument();
        expect(
            document.getElementById('gta-alerts-sidebar-nav-settings-btn'),
        ).toBeInTheDocument();
        expect(
            screen.getByPlaceholderText(
                'Search alerts, streets, or categories...',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getAllByRole('button', {
                name: 'Open notification center',
            }).length,
        ).toBeGreaterThan(0);
        expect(
            screen.getAllByRole('button', { name: 'Open settings' }).length,
        ).toBeGreaterThan(0);
        expect(screen.getByText('Incident Archives')).toBeInTheDocument();
        expect(screen.getByText('Privacy Policy')).toBeInTheDocument();
        expect(screen.getByText('System Status')).toBeInTheDocument();
        expect(
            screen.getByText('Temp: 24 C | Humidity: 65% | Wind: 15km/h W'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Refresh feed' }),
        ).toBeInTheDocument();
    });
});

describe(designRevampPhase4Spec.scenarios[1].id, () => {
    it('keeps the feed/table toggle client-side while switching rendered content', () => {
        render(
            React.createElement(FeedView, {
                searchQuery: '',
                onSelectAlert: vi.fn(),
                initialAlerts: buildAlerts(),
                initialNextCursor: null,
                latestFeedUpdatedAt: new Date(
                    '2026-02-03T12:05:00Z',
                ).toISOString(),
                status: 'all',
            }),
        );

        expect(screen.getByText('STRUCTURE FIRE')).toBeInTheDocument();

        const callsBeforeToggle = inertiaRouterMocks.get.mock.calls.length;
        fireEvent.click(screen.getByRole('button', { name: 'Table view' }));

        expect(screen.getByRole('table')).toBeInTheDocument();
        expect(screen.getByText('CLEARED THEFT')).toBeInTheDocument();
        expect(
            screen.getByRole('button', {
                name: /Expand summary for STRUCTURE FIRE/i,
            }),
        ).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Feed view' }));

        expect(screen.getByText('STRUCTURE FIRE')).toBeInTheDocument();
        expect(inertiaRouterMocks.get).toHaveBeenCalledTimes(callsBeforeToggle);
    });
});

describe(designRevampPhase4Spec.scenarios[2].id, () => {
    it('opens details from table summary and returns via the in-app back control', () => {
        window.history.replaceState({}, '', '/?source=fire');

        render(React.createElement(AlertsApp, buildAppProps()));

        fireEvent.click(screen.getByRole('button', { name: 'Table view' }));

        const urlBeforeExpand = window.location.pathname + window.location.search;
        fireEvent.click(
            screen.getByRole('button', {
                name: /Expand summary for STRUCTURE FIRE/i,
            }),
        );

        expect(screen.getByText('Incident Summary')).toBeInTheDocument();
        expect(window.location.pathname + window.location.search).toBe(
            urlBeforeExpand,
        );

        fireEvent.click(screen.getByRole('button', { name: 'View Details' }));

        const detailsSection = screen
            .getByText('Incident Details')
            .closest('section');

        expect(detailsSection).not.toBeNull();
        expect(screen.getByText(/FIRE:E1/)).toBeInTheDocument();

        fireEvent.click(within(detailsSection as HTMLElement).getAllByRole('button')[0]);

        expect(screen.queryByText('Incident Details')).not.toBeInTheDocument();
        expect(screen.getByText('STRUCTURE FIRE')).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Refresh feed' }),
        ).toBeInTheDocument();
        expect(window.location.pathname + window.location.search).toBe(
            urlBeforeExpand,
        );
    });
});

describe(designRevampPhase4Spec.scenarios[3].id, () => {
    it('keeps active cards prominent and cleared cards muted', () => {
        render(
            React.createElement(FeedView, {
                searchQuery: '',
                onSelectAlert: vi.fn(),
                initialAlerts: buildAlerts(),
                initialNextCursor: null,
                latestFeedUpdatedAt: null,
                status: 'all',
            }),
        );

        const activeCard = screen.getByText('STRUCTURE FIRE').closest('article');
        const clearedCard = screen.getByText('CLEARED THEFT').closest('article');

        expect(activeCard).not.toBeNull();
        expect(clearedCard).not.toBeNull();
        expect(activeCard).not.toHaveClass('grayscale');
        expect(activeCard).not.toHaveClass('opacity-80');
        expect(clearedCard).toHaveClass('grayscale');
        expect(clearedCard).toHaveClass('opacity-80');
    });
});

describe(designRevampPhase4Spec.scenarios[4].id, () => {
    it('keeps the mobile drawer controls, bottom nav, and feed refresh affordance aligned', () => {
        render(React.createElement(AlertsApp, buildAppProps()));

        const openMenuButton = screen.getByRole('button', { name: 'Open menu' });
        const closeMenuButton = screen.getByRole('button', {
            name: 'Close menu',
        });
        const sidebar = closeMenuButton.closest('aside');
        const refreshButton = screen.getByRole('button', {
            name: 'Refresh feed',
        });

        expect(sidebar).not.toBeNull();
        expect(
            document.getElementById('gta-alerts-bottom-nav'),
        ).toBeInTheDocument();
        expect(
            document.getElementById('gta-alerts-bottom-nav-btn-feed'),
        ).toBeInTheDocument();
        expect(
            document.getElementById('gta-alerts-bottom-nav-btn-inbox'),
        ).toBeInTheDocument();
        expect(refreshButton).toHaveClass('bottom-24');

        fireEvent.click(openMenuButton);
        expect(sidebar).toHaveClass('translate-x-0');
        expect(openMenuButton).toHaveAttribute('aria-expanded', 'true');

        fireEvent.click(closeMenuButton);
        expect(sidebar).toHaveClass('-translate-x-full');

        fireEvent.click(openMenuButton);
        fireEvent.keyDown(window, { key: 'Escape' });

        expect(sidebar).toHaveClass('-translate-x-full');
        expect(openMenuButton).toHaveAttribute('aria-expanded', 'false');
    });
});
