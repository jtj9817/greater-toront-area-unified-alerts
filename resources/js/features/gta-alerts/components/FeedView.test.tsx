import { fireEvent, render, screen } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import type { UnifiedAlertResource } from '../domain/alerts';
import { FeedView } from './FeedView';

const inertiaRouterMocks = vi.hoisted(() => ({
    on: vi.fn(() => vi.fn()),
    get: vi.fn(),
}));

// Mock Inertia's usePage hook
vi.mock('@inertiajs/react', async () => {
    const actual = (await vi.importActual('@inertiajs/react')) as {
        usePage: () => { props: Record<string, unknown> };
        router: { on: (...args: unknown[]) => unknown };
    };
    return {
        ...actual,
        usePage: () => ({
            url: 'http://localhost/',
            props: {
                processing: false,
            },
        }),
        router: {
            ...actual.router,
            on: inertiaRouterMocks.on,
            get: inertiaRouterMocks.get,
        },
    };
});

vi.mock('laravel-vite-plugin/inertia-helpers', () => ({
    resolvePageComponent: vi.fn(),
}));

vi.mock('@/wayfinder', () => ({
    home: vi.fn((opts) => {
        let url = '/?';
        if (opts?.query?.sort) url += `sort=${opts.query.sort}&`;
        return { url };
    }),
}));

describe('FeedView', () => {
    beforeEach(() => {
        inertiaRouterMocks.on.mockClear();
        inertiaRouterMocks.get.mockClear();
    });

    const timestamp = new Date('2026-02-03T12:00:00Z').toISOString();

    const mockUnified: UnifiedAlertResource[] = [
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
                units_dispatched: null,
                beat: null,
                event_num: 'E1',
            },
        },
        {
            id: 'police:123',
            source: 'police',
            external_id: '123',
            is_active: true,
            timestamp,
            title: 'THEFT',
            location: { name: '456 Police Rd', lat: 43.7, lng: -79.4 },
            meta: { division: 'D31', call_type_code: 'THEFT', object_id: 123 },
        },
    ];

    const defaultProps = {
        searchQuery: '',
        onSelectAlert: vi.fn(),
        initialAlerts: mockUnified,
        initialNextCursor: null,
        latestFeedUpdatedAt: timestamp,
        status: 'all' as const,
        savedIds: new Set<string>(),
        isPending: vi.fn(() => false),
        onToggleSave: vi.fn(),
    };

    it('renders a list of alerts', () => {
        render(<FeedView {...defaultProps} />);

        expect(screen.getByText('STRUCTURE FIRE')).toBeInTheDocument();
        expect(screen.getByText('THEFT')).toBeInTheDocument();
    });

    it('shows data freshness indicator when latestFeedUpdatedAt is provided', () => {
        render(<FeedView {...defaultProps} />);

        expect(screen.getByText('Live Feed Active')).toBeInTheDocument();
        expect(screen.getByText(/Updated:/)).toBeInTheDocument();
    });

    it('shows empty state when no alerts match', () => {
        render(
            <FeedView
                {...defaultProps}
                searchQuery="NoMatch"
                initialAlerts={[]}
            />,
        );

        expect(
            screen.getByText('No alerts match your filters'),
        ).toBeInTheDocument();
    });

    it('renders status filter options (all, active, cleared)', () => {
        render(<FeedView {...defaultProps} />);

        expect(screen.getByText('All', { selector: 'a' })).toBeInTheDocument();
        expect(
            screen.getByText('Active', { selector: 'a' }),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Cleared', { selector: 'a' }),
        ).toBeInTheDocument();
    });

    it('renders source category filters', () => {
        render(<FeedView {...defaultProps} />);

        expect(screen.getByText('All Alerts')).toBeInTheDocument();
        expect(screen.getByText('Fire')).toBeInTheDocument();
        expect(screen.getByText('Police')).toBeInTheDocument();
        expect(screen.getByText('TTC')).toBeInTheDocument();
        expect(screen.getByText('GO Transit')).toBeInTheDocument();
    });

    it('renders time window selector with options', () => {
        render(<FeedView {...defaultProps} />);

        const select = screen.getByRole('combobox');
        expect(select).toBeInTheDocument();
        expect(select).toHaveValue('all');
    });

    it('renders loaded count when alerts are provided', () => {
        render(<FeedView {...defaultProps} />);

        expect(screen.getByText('2 loaded')).toBeInTheDocument();
    });

    it('renders loaded count when no alerts are present', () => {
        render(<FeedView {...defaultProps} initialAlerts={[]} />);

        expect(screen.getByText('0 loaded')).toBeInTheDocument();
    });

    it('shows Reset button when filters are active', () => {
        render(
            <FeedView
                {...defaultProps}
                source="fire"
                since="1h"
            />,
        );

        expect(screen.getByText('Reset')).toBeInTheDocument();
    });

    it('does not show Reset button when no filters are active', () => {
        render(
            <FeedView
                {...defaultProps}
                source={null}
                since={null}
            />,
        );

        expect(screen.queryByText('Reset')).not.toBeInTheDocument();
    });

    it('shows Reset button when oldest-first sort is active', () => {
        render(
            <FeedView
                {...defaultProps}
                sort="asc"
                source={null}
                since={null}
            />,
        );

        expect(screen.getByText('Reset')).toBeInTheDocument();
    });

    it('renders view mode toggle (Feed/Table)', () => {
        render(<FeedView {...defaultProps} />);

        expect(screen.getByText('Feed')).toBeInTheDocument();
        expect(screen.getByText('Table')).toBeInTheDocument();
    });

    it('keeps cards/table toggle client-side and does not trigger navigation', () => {
        render(<FeedView {...defaultProps} />);

        const callsBeforeToggle = inertiaRouterMocks.get.mock.calls.length;

        fireEvent.click(screen.getByRole('button', { name: 'Table view' }));
        fireEvent.click(screen.getByRole('button', { name: 'Feed view' }));

        expect(inertiaRouterMocks.get).toHaveBeenCalledTimes(callsBeforeToggle);
    });

    it('toggles sort direction and omits desc from the generated URL', () => {
        render(
            <FeedView
                {...defaultProps}
                sort="desc"
            />,
        );

        fireEvent.click(
            screen.getByRole('button', { name: 'Switch to oldest first' }),
        );

        expect(inertiaRouterMocks.get).toHaveBeenCalledTimes(1);
        const [url] = inertiaRouterMocks.get.mock.calls[0] as [string];
        expect(url).toContain('sort=asc');
        expect(url).not.toContain('sort=desc');
    });

    it('passes saved state to AlertCard', () => {
        const savedIds = new Set(['fire:E1']);
        render(<FeedView {...defaultProps} savedIds={savedIds} />);

        const saveBtn = screen.getByLabelText(/Remove alert/i);
        expect(saveBtn).toHaveClass('bg-primary');
    });
});
