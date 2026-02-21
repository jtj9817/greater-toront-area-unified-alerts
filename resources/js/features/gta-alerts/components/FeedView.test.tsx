import { render, screen } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';
import type { UnifiedAlertResource } from '../domain/alerts';
import { FeedView } from './FeedView';

// Mock Inertia's usePage hook
vi.mock('@inertiajs/react', async () => {
    const actual = (await vi.importActual('@inertiajs/react')) as {
        usePage: () => { props: Record<string, unknown> };
        router: { on: (...args: unknown[]) => unknown };
    };
    return {
        ...actual,
        usePage: () => ({
            props: {
                processing: false,
            },
        }),
        router: {
            ...actual.router,
            on: vi.fn(() => vi.fn()),
        },
    };
});

describe('FeedView', () => {
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

    it('renders a list of alerts', () => {
        render(
            <FeedView
                searchQuery=""
                onSelectAlert={() => {}}
                initialAlerts={mockUnified}
                initialNextCursor={null}
                latestFeedUpdatedAt={new Date().toISOString()}
                status="all"
            />,
        );

        expect(screen.getByText('STRUCTURE FIRE')).toBeInTheDocument();
        expect(screen.getByText('THEFT')).toBeInTheDocument();
    });

    it('shows data freshness indicator when latestFeedUpdatedAt is provided', () => {
        render(
            <FeedView
                searchQuery=""
                onSelectAlert={() => {}}
                initialAlerts={mockUnified}
                initialNextCursor={null}
                latestFeedUpdatedAt={new Date().toISOString()}
                status="all"
            />,
        );

        expect(screen.getByText('Live Feed Active')).toBeInTheDocument();
        expect(screen.getByText(/Updated:/)).toBeInTheDocument();
    });

    it('shows empty state when no alerts match', () => {
        render(
            <FeedView
                searchQuery="NoMatch"
                onSelectAlert={() => {}}
                initialAlerts={[]}
                initialNextCursor={null}
                latestFeedUpdatedAt={null}
                status="all"
            />,
        );

        expect(
            screen.getByText('No alerts match your filters'),
        ).toBeInTheDocument();
    });

    it('renders status filter options (all, active, cleared)', () => {
        render(
            <FeedView
                searchQuery=""
                onSelectAlert={() => {}}
                initialAlerts={mockUnified}
                initialNextCursor={null}
                latestFeedUpdatedAt={new Date().toISOString()}
                status="all"
            />,
        );

        expect(screen.getByText('All')).toBeInTheDocument();
        expect(screen.getByText('Active')).toBeInTheDocument();
        expect(screen.getByText('Cleared')).toBeInTheDocument();
    });

    it('renders source category filters', () => {
        render(
            <FeedView
                searchQuery=""
                onSelectAlert={() => {}}
                initialAlerts={mockUnified}
                initialNextCursor={null}
                latestFeedUpdatedAt={new Date().toISOString()}
                status="all"
                source={null}
            />,
        );

        expect(screen.getByText('All Alerts')).toBeInTheDocument();
        expect(screen.getByText('Fire')).toBeInTheDocument();
        expect(screen.getByText('Police')).toBeInTheDocument();
        expect(screen.getByText('TTC')).toBeInTheDocument();
        expect(screen.getByText('GO Transit')).toBeInTheDocument();
    });

    it('renders time window selector with options', () => {
        render(
            <FeedView
                searchQuery=""
                onSelectAlert={() => {}}
                initialAlerts={mockUnified}
                initialNextCursor={null}
                latestFeedUpdatedAt={new Date().toISOString()}
                status="all"
                since={null}
            />,
        );

        const select = screen.getByRole('combobox');
        expect(select).toBeInTheDocument();
        expect(select).toHaveValue('all');
    });

    it('renders loaded count when alerts are provided', () => {
        render(
            <FeedView
                searchQuery=""
                onSelectAlert={() => {}}
                initialAlerts={mockUnified}
                initialNextCursor={null}
                latestFeedUpdatedAt={new Date().toISOString()}
                status="all"
            />,
        );

        expect(screen.getByText('2 loaded')).toBeInTheDocument();
    });

    it('renders loaded count when no alerts are present', () => {
        render(
            <FeedView
                searchQuery=""
                onSelectAlert={() => {}}
                initialAlerts={[]}
                initialNextCursor={null}
                latestFeedUpdatedAt={new Date().toISOString()}
                status="all"
            />,
        );

        expect(screen.getByText('0 loaded')).toBeInTheDocument();
    });

    it('shows Reset button when filters are active', () => {
        render(
            <FeedView
                searchQuery=""
                onSelectAlert={() => {}}
                initialAlerts={mockUnified}
                initialNextCursor={null}
                latestFeedUpdatedAt={new Date().toISOString()}
                status="all"
                source="fire"
                since="1h"
            />,
        );

        expect(screen.getByText('Reset')).toBeInTheDocument();
    });

    it('does not show Reset button when no filters are active', () => {
        render(
            <FeedView
                searchQuery=""
                onSelectAlert={() => {}}
                initialAlerts={mockUnified}
                initialNextCursor={null}
                latestFeedUpdatedAt={new Date().toISOString()}
                status="all"
                source={null}
                since={null}
            />,
        );

        expect(screen.queryByText('Reset')).not.toBeInTheDocument();
    });

    it('renders view mode toggle (Cards/Table)', () => {
        render(
            <FeedView
                searchQuery=""
                onSelectAlert={() => {}}
                initialAlerts={mockUnified}
                initialNextCursor={null}
                latestFeedUpdatedAt={new Date().toISOString()}
                status="all"
            />,
        );

        expect(screen.getByText('Cards')).toBeInTheDocument();
        expect(screen.getByText('Table')).toBeInTheDocument();
    });
});
