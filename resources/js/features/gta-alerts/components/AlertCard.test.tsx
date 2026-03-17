import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';
import type { UnifiedAlertResource } from '../domain/alerts';
import { AlertService } from '../services/AlertService';
import { AlertCard } from './AlertCard';

describe('AlertCard', () => {
    const timestamp = new Date('2026-02-03T12:00:00Z').toISOString();

    const mockUnified: UnifiedAlertResource = {
        id: 'fire:E1',
        source: 'fire',
        external_id: 'E1',
        is_active: true,
        timestamp,
        title: 'STRUCTURE FIRE',
        location: { name: 'Main St', lat: null, lng: null },
        meta: {
            alarm_level: 2,
            units_dispatched: 'P1',
            beat: 'B1',
            event_num: 'E1',
        },
    };

    const mockAlert = AlertService.mapUnifiedAlertToDomainAlert(mockUnified);
    if (!mockAlert) throw new Error('Expected DomainAlert');

    it('renders correctly with given item data', () => {
        render(<AlertCard alert={mockAlert} />);

        expect(screen.getByText('STRUCTURE FIRE')).toBeInTheDocument();
        expect(screen.getByText(/Main St/)).toBeInTheDocument();
        expect(screen.getByText(/Just now|ago/)).toBeInTheDocument();
        expect(screen.getAllByText(/Event #E1/).length).toBeGreaterThan(0);
    });

    it('calls onViewDetails when clicked', () => {
        const onViewDetails = vi.fn();
        render(<AlertCard alert={mockAlert} onViewDetails={onViewDetails} />);

        const card = screen.getByText('STRUCTURE FIRE').closest('article');
        if (!card) throw new Error('Alert card not found');
        fireEvent.click(card);
        expect(onViewDetails).toHaveBeenCalledTimes(1);
    });

    it('calls onToggleSave and stops propagation when save button is clicked', () => {
        const onToggleSave = vi.fn();
        const onViewDetails = vi.fn();
        render(
            <AlertCard
                alert={mockAlert}
                onToggleSave={onToggleSave}
                onViewDetails={onViewDetails}
            />,
        );

        const saveBtn = screen.getByLabelText('Save alert');
        fireEvent.click(saveBtn);

        expect(onToggleSave).toHaveBeenCalledTimes(1);
        expect(onViewDetails).not.toHaveBeenCalled();
    });

    it('shows saved state correctly', () => {
        render(<AlertCard alert={mockAlert} isSaved={true} />);
        const saveBtn = screen.getByLabelText('Remove alert');
        expect(saveBtn).toHaveClass('bg-primary');
    });

    it('shows loading state when isPending is true', () => {
        render(<AlertCard alert={mockAlert} isPending={true} />);
        const saveBtn = screen.getByLabelText('Save alert');
        expect(saveBtn).toBeDisabled();
        expect(saveBtn.querySelector('.animate-spin')).toBeInTheDocument();
    });
});
