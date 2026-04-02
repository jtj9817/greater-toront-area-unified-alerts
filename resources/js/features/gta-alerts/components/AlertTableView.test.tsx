import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { UnifiedAlertResource } from '../domain/alerts';
import { AlertService } from '../services/AlertService';
import { AlertTableView } from './AlertTableView';

describe('AlertTableView', () => {
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
            title: 'THEFT',
            location: { name: '456 Police Rd', lat: 43.7, lng: -79.4 },
            meta: {
                division: 'D31',
                call_type_code: 'THEFT',
                object_id: 123,
            },
        },
    ];

    const rows = AlertService.mapUnifiedAlertsToDomainAlerts(mockUnified);

    const defaultProps = {
        items: rows,
        onSelectAlert: vi.fn(),
        savedIds: new Set<string>(),
        isPending: vi.fn(() => false),
        onToggleSave: vi.fn(),
    };

    it('renders table headers and row statuses', () => {
        render(<AlertTableView {...defaultProps} />);

        expect(screen.getByText('Timestamp')).toBeInTheDocument();
        expect(screen.getByText('Incident Type')).toBeInTheDocument();
        expect(screen.getByText('Location')).toBeInTheDocument();
        expect(screen.getByText('Status')).toBeInTheDocument();
        expect(screen.getByText('Severity')).toBeInTheDocument();
        expect(screen.getByText('Source')).toBeInTheDocument();
        expect(screen.getByText('Active')).toBeInTheDocument();
        expect(screen.getByText('Cleared')).toBeInTheDocument();
    });

    it('expands and collapses summary rows from the expand affordance', () => {
        render(<AlertTableView {...defaultProps} />);

        const expandButton = screen.getByRole('button', {
            name: /Expand summary for STRUCTURE FIRE/i,
        });

        fireEvent.click(expandButton);

        expect(screen.getByText('Incident Summary')).toBeInTheDocument();
        expect(screen.getAllByText(/Event #E1/).length).toBeGreaterThan(0);
        expect(defaultProps.onSelectAlert).not.toHaveBeenCalled();

        fireEvent.click(
            screen.getByRole('button', {
                name: /Collapse summary for STRUCTURE FIRE/i,
            }),
        );

        expect(screen.queryByText('Incident Summary')).not.toBeInTheDocument();
    });

    it('expands summary on row click without selecting details', () => {
        render(<AlertTableView {...defaultProps} />);

        fireEvent.click(screen.getByText('STRUCTURE FIRE'));

        expect(screen.getByText('Incident Summary')).toBeInTheDocument();
        expect(defaultProps.onSelectAlert).not.toHaveBeenCalled();
    });

    it('allows selecting details from the expanded summary action', () => {
        render(<AlertTableView {...defaultProps} />);

        fireEvent.click(
            screen.getByRole('button', {
                name: /Expand summary for STRUCTURE FIRE/i,
            }),
        );

        fireEvent.click(screen.getByRole('button', { name: /View Details/i }));

        expect(defaultProps.onSelectAlert).toHaveBeenCalledWith('fire:E1');
    });

    it('calls onToggleSave and stops propagation when row save button is clicked', () => {
        render(<AlertTableView {...defaultProps} />);

        const saveBtn = screen.getByLabelText(/Save STRUCTURE FIRE/i);
        fireEvent.click(saveBtn);

        expect(defaultProps.onToggleSave).toHaveBeenCalledWith('fire:E1');
        // Ensure the row didn't expand (which would happen if propagation wasn't stopped)
        expect(screen.queryByText('Incident Summary')).not.toBeInTheDocument();
    });

    it('shows saved state and handles toggle in expanded view', () => {
        const props = {
            ...defaultProps,
            savedIds: new Set(['fire:E1']),
        };
        render(<AlertTableView {...props} />);

        // Check row save button state
        const rowSaveBtn = screen.getByLabelText(
            /Remove STRUCTURE FIRE from saved/i,
        );
        expect(rowSaveBtn).toHaveClass('text-primary');

        // Expand and check expanded save button
        fireEvent.click(rowSaveBtn.closest('tr')!); // Row click to expand
        const expandedSaveBtn = screen.getByText('Saved').closest('button');
        if (!expandedSaveBtn) throw new Error('Expanded save button not found');
        expect(expandedSaveBtn).toHaveClass('bg-primary');

        fireEvent.click(expandedSaveBtn);
        expect(defaultProps.onToggleSave).toHaveBeenCalledWith('fire:E1');
    });

    it('shows loading state when isPending is true', () => {
        const props = {
            ...defaultProps,
            isPending: vi.fn((id) => id === 'fire:E1'),
        };
        render(<AlertTableView {...props} />);

        const saveBtn = screen.getByLabelText(/Save STRUCTURE FIRE/i);
        expect(saveBtn).toBeDisabled();
        expect(saveBtn.querySelector('.animate-spin')).toBeInTheDocument();
    });

    it('renders YRT source label for yrt table rows', () => {
        const yrtRow = AlertService.mapUnifiedAlertToDomainAlert({
            id: 'yrt:a1234',
            source: 'yrt',
            external_id: 'a1234',
            is_active: true,
            timestamp,
            title: '52 - Holland Landing detour',
            location: { name: 'Route 52', lat: null, lng: null },
            meta: {
                details_url: 'https://www.yrt.ca/en/news/52-detour.aspx',
                description_excerpt: 'Detour in effect near Green Lane.',
                body_text: 'Routes affected: 52 and 58.',
                posted_at: '2026-04-01 10:20:00',
                feed_updated_at: '2026-04-01 10:25:00',
            },
        });
        if (!yrtRow) {
            throw new Error('Expected YRT DomainAlert row');
        }

        render(<AlertTableView {...defaultProps} items={[yrtRow]} />);

        expect(screen.getByText('YRT')).toBeInTheDocument();
    });
});
