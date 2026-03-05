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

    it('renders table headers and row statuses', () => {
        render(
            <AlertTableView
                items={rows}
                onSelectAlert={() => {}}
                savedIds={new Set()}
            />,
        );

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
        const onSelectAlert = vi.fn();

        render(
            <AlertTableView
                items={rows}
                onSelectAlert={onSelectAlert}
                savedIds={new Set()}
            />,
        );

        const expandButton = screen.getByRole('button', {
            name: /Expand summary for STRUCTURE FIRE/i,
        });

        fireEvent.click(expandButton);

        expect(screen.getByText('Incident Summary')).toBeInTheDocument();
        expect(screen.getAllByText(/Event #E1/).length).toBeGreaterThan(0);
        expect(onSelectAlert).not.toHaveBeenCalled();

        fireEvent.click(
            screen.getByRole('button', {
                name: /Collapse summary for STRUCTURE FIRE/i,
            }),
        );

        expect(screen.queryByText('Incident Summary')).not.toBeInTheDocument();
    });

    it('keeps incident selection for details on row click', () => {
        const onSelectAlert = vi.fn();

        render(
            <AlertTableView
                items={rows}
                onSelectAlert={onSelectAlert}
                savedIds={new Set()}
            />,
        );

        fireEvent.click(screen.getByText('STRUCTURE FIRE'));

        expect(onSelectAlert).toHaveBeenCalledWith('fire:E1');
    });

    it('allows selecting details from the expanded summary action', () => {
        const onSelectAlert = vi.fn();

        render(
            <AlertTableView
                items={rows}
                onSelectAlert={onSelectAlert}
                savedIds={new Set()}
            />,
        );

        fireEvent.click(
            screen.getByRole('button', {
                name: /Expand summary for STRUCTURE FIRE/i,
            }),
        );

        fireEvent.click(screen.getByRole('button', { name: /View Details/i }));

        expect(onSelectAlert).toHaveBeenCalledWith('fire:E1');
    });
});
