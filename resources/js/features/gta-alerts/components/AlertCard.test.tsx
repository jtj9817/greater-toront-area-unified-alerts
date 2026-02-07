import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';
import { AlertService } from '../services/AlertService';
import type { UnifiedAlertResource } from '../types';
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

    const mockItem = AlertService.mapUnifiedAlertToAlertItem(mockUnified);
    if (!mockItem) throw new Error('Expected AlertItem');

    it('renders correctly with given item data', () => {
        render(<AlertCard item={mockItem} />);

        expect(screen.getByText('STRUCTURE FIRE')).toBeInTheDocument();
        expect(screen.getByText(/Main St/)).toBeInTheDocument();
        expect(screen.getByText(/Just now|ago/)).toBeInTheDocument();
        expect(screen.getByText(/Event #E1/)).toBeInTheDocument();
    });

    it('calls onViewDetails when clicked', () => {
        const onViewDetails = vi.fn();
        render(<AlertCard item={mockItem} onViewDetails={onViewDetails} />);

        const card = screen.getByText('STRUCTURE FIRE').closest('article');
        if (!card) throw new Error('Alert card not found');
        fireEvent.click(card);
        expect(onViewDetails).toHaveBeenCalledTimes(1);
    });

    it('shows saved badge when isSaved is true', () => {
        render(<AlertCard item={mockItem} isSaved={true} />);
        expect(screen.getByText('SAVED')).toBeInTheDocument();
    });
});
