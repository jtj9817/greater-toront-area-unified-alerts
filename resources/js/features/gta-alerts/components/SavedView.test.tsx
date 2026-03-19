import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi, beforeEach } from 'vitest';
import type { DomainAlert } from '../domain/alerts';
import type { SavedAlertsResponse } from '../services/SavedAlertService';
import { fetchSavedAlerts } from '../services/SavedAlertService';
import { SavedView } from './SavedView';

vi.mock('../services/SavedAlertService', () => ({
    fetchSavedAlerts: vi.fn(),
}));

describe('SavedView', () => {
    const mockAlerts: DomainAlert[] = [
        {
            id: 'fire:E1',
            kind: 'fire',
            externalId: 'E1',
            isActive: true,
            timestamp: new Date('2026-02-03T12:00:00Z').toISOString(),
            title: 'STRUCTURE FIRE',
            location: { name: '123 Fire St', lat: null, lng: null },
            meta: {
                alarm_level: 1,
                event_num: 'E1',
                units_dispatched: 'P1',
                beat: 'B1',
                intel_summary: [],
                intel_last_updated: null,
            },
        },
    ];

    const defaultProps = {
        authUserId: null,
        onSelectAlert: vi.fn(),
        allAlerts: mockAlerts,
        savedIds: ['fire:E1'],
        isSaved: vi.fn((id: string) => id === 'fire:E1'),
        isPending: vi.fn(() => false),
        onToggleSave: vi.fn(async () => {}),
    };

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders empty state when no alerts are saved', () => {
        render(<SavedView {...defaultProps} savedIds={[]} />);
        expect(screen.getByText('No saved alerts')).toBeInTheDocument();
    });

    it('renders saved alerts for guest user', () => {
        render(<SavedView {...defaultProps} />);
        expect(screen.getByText('STRUCTURE FIRE')).toBeInTheDocument();
        expect(
            screen.getByText(/Your alerts are saved locally/),
        ).toBeInTheDocument();
    });

    it('renders saved alerts for authenticated user', async () => {
        const mockResponse: SavedAlertsResponse = {
            data: [
                {
                    id: 'fire:E1',
                    source: 'fire',
                    external_id: 'E1',
                    is_active: true,
                    timestamp: new Date().toISOString(),
                    title: 'API FIRE',
                    location: { name: 'API St', lat: null, lng: null },
                    meta: {
                        event_num: 'E1',
                        alarm_level: 1,
                        units_dispatched: 'P1',
                        beat: 'B1',
                    },
                },
            ],
            meta: {
                saved_ids: ['fire:E1'],
                missing_alert_ids: [],
            },
        };
        const mockedFetchSavedAlerts = vi.mocked(fetchSavedAlerts);
        mockedFetchSavedAlerts.mockResolvedValue(mockResponse);

        render(<SavedView {...defaultProps} authUserId={42} />);

        expect(
            screen.getByText(/Loading your saved alerts/),
        ).toBeInTheDocument();

        await waitFor(() => {
            expect(screen.getByText('API FIRE')).toBeInTheDocument();
        });
        expect(
            screen.getByText(/Review incidents you've flagged/),
        ).toBeInTheDocument();
    });

    it('shows missing alerts as unavailable', () => {
        // Saved ID exists but not in allAlerts
        render(<SavedView {...defaultProps} savedIds={['police:GHOST']} />);
        expect(screen.getByText('Alert Unavailable')).toBeInTheDocument();
        expect(screen.getByText(/police:GHOST/)).toBeInTheDocument();
    });

    it('shows guest cap warning and handles eviction', () => {
        const onEvictOldest = vi.fn();
        render(
            <SavedView
                {...defaultProps}
                guestCapReached={true}
                onEvictOldest={onEvictOldest}
            />,
        );

        expect(screen.getByText(/Guest limit reached/)).toBeInTheDocument();
        fireEvent.click(screen.getByText('Clear Oldest 3'));
        expect(onEvictOldest).toHaveBeenCalledTimes(1);
    });

    it('calls onToggleSave when removing an alert', () => {
        render(<SavedView {...defaultProps} />);
        const saveBtn = screen.getByLabelText(/Remove from saved/i);
        fireEvent.click(saveBtn);
        expect(defaultProps.onToggleSave).toHaveBeenCalledWith('fire:E1');
    });
});
