import { act, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import AlertsApp from './App';
import type { UnifiedAlertResource } from './domain/alerts';

const inertiaRouterMocks = vi.hoisted(() => {
    const listeners = new Map<string, (event: unknown) => void>();

    return {
        on: vi.fn((event: string, callback: (event: unknown) => void) => {
            listeners.set(event, callback);
            return () => {
                listeners.delete(event);
            };
        }),
        get: vi.fn(),
        emit: (event: string, payload: unknown) => {
            const listener = listeners.get(event);
            if (listener) {
                listener(payload);
            }
        },
        reset: () => {
            listeners.clear();
        },
    };
});

vi.mock('@inertiajs/react', () => ({
    router: {
        on: inertiaRouterMocks.on,
        get: inertiaRouterMocks.get,
    },
}));

vi.mock('@/routes', () => ({
    home: () => ({ url: '/' }),
}));

vi.mock('./components/FeedView', () => ({
    FeedView: ({ searchQuery }: { searchQuery: string }) => (
        <div data-testid="feed-view">{searchQuery}</div>
    ),
}));

vi.mock('./components/Sidebar', () => ({
    Sidebar: () => <div data-testid="sidebar" />,
}));

vi.mock('./components/BottomNav', () => ({
    BottomNav: () => <div data-testid="bottom-nav" />,
}));

vi.mock('./components/NotificationToastLayer', () => ({
    NotificationToastLayer: () => null,
}));

vi.mock('./components/SavedView', () => ({
    SavedView: () => null,
}));

vi.mock('./components/ZonesView', () => ({
    ZonesView: () => null,
}));

vi.mock('./components/SettingsView', () => ({
    SettingsView: () => null,
}));

vi.mock('./components/NotificationInboxView', () => ({
    NotificationInboxView: () => null,
}));

vi.mock('./components/AlertDetailsView', () => ({
    AlertDetailsView: () => null,
}));

vi.mock('./components/Icon', () => ({
    Icon: () => null,
}));

function buildProps(query: string | null) {
    const alerts: UnifiedAlertResource[] = [
        {
            id: 'fire:E1',
            source: 'fire',
            external_id: 'E1',
            is_active: true,
            timestamp: '2026-02-03T12:00:00Z',
            title: 'STRUCTURE FIRE',
            location: { name: 'Main St', lat: null, lng: null },
            meta: {
                alarm_level: 2,
                event_num: 'E1',
                units_dispatched: null,
                beat: null,
            },
        },
    ];

    return {
        alerts: {
            data: alerts,
            next_cursor: null,
        },
        filters: {
            status: 'all' as const,
            sort: 'desc' as const,
            q: query,
            source: null,
            since: null,
        },
        latestFeedUpdatedAt: null,
        authUserId: null,
        subscriptionRouteOptions: [],
    };
}

describe('App URL search state synchronization', () => {
    beforeEach(() => {
        inertiaRouterMocks.reset();
        inertiaRouterMocks.on.mockClear();
        inertiaRouterMocks.get.mockClear();
    });

    it('syncs search input from URL after gta-alerts router success events', async () => {
        window.history.replaceState({}, '', '/?q=initial');

        render(<AlertsApp {...buildProps('initial')} />);

        const input = screen.getByPlaceholderText(
            'Search alerts, streets, or categories...',
        );
        expect(input).toHaveValue('initial');

        window.history.replaceState({}, '', '/?q=assault');
        vi.stubGlobal('location', { ...window.location, search: '?q=assault' });

        act(() => {
            inertiaRouterMocks.emit('success', {
                detail: {
                    page: {
                        component: 'gta-alerts',
                    },
                },
            });
        });

        await waitFor(() => {
            expect(input).toHaveValue('assault');
        });
    });

    it('ignores router success events from other pages', () => {
        window.history.replaceState({}, '', '/?q=active');

        render(<AlertsApp {...buildProps('active')} />);

        const input = screen.getByPlaceholderText(
            'Search alerts, streets, or categories...',
        );
        expect(input).toHaveValue('active');

        window.history.replaceState({}, '', '/?q=fire');
        vi.stubGlobal('location', { ...window.location, search: '?q=fire' });

        act(() => {
            inertiaRouterMocks.emit('success', {
                detail: {
                    page: {
                        component: 'settings',
                    },
                },
            });
        });

        expect(input).toHaveValue('active');
    });
});
