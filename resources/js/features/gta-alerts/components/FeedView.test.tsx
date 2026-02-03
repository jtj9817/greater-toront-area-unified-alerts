import { render, screen } from '@testing-library/react';
import { describe, it, expect } from 'vitest';
import type { UnifiedAlertResource } from '../types';
import { AlertService } from '../services/AlertService';
import { FeedView } from './FeedView';

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
      meta: { alarm_level: 2, units_dispatched: null, beat: null, event_num: 'E1' },
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

  const mockAlerts = mockUnified.map((a) => AlertService.mapUnifiedAlertToAlertItem(a));

  it('renders a list of alerts', () => {
    render(
      <FeedView 
        searchQuery="" 
        onSelectAlert={() => {}} 
        allAlerts={mockAlerts} 
        latestFeedUpdatedAt={new Date().toISOString()}
        status="all"
        pagination={{ prevUrl: null, nextUrl: null, currentPage: 1, lastPage: 1, total: 2 }}
      />
    );
    
    expect(screen.getByText('STRUCTURE FIRE')).toBeInTheDocument();
    expect(screen.getByText('THEFT')).toBeInTheDocument();
  });

  it('shows data freshness indicator when latestFeedUpdatedAt is provided', () => {
    render(
      <FeedView 
        searchQuery="" 
        onSelectAlert={() => {}} 
        allAlerts={mockAlerts} 
        latestFeedUpdatedAt={new Date().toISOString()}
        status="all"
        pagination={{ prevUrl: null, nextUrl: null, currentPage: 1, lastPage: 1, total: 2 }}
      />
    );
    
    expect(screen.getByText('Live Feed Active')).toBeInTheDocument();
    expect(screen.getByText(/Updated:/)).toBeInTheDocument();
  });

  it('shows empty state when no alerts match', () => {
    render(
      <FeedView 
        searchQuery="NoMatch" 
        onSelectAlert={() => {}} 
        allAlerts={mockAlerts} 
        latestFeedUpdatedAt={null}
        status="all"
        pagination={{ prevUrl: null, nextUrl: null, currentPage: 1, lastPage: 1, total: 2 }}
      />
    );
    
    expect(screen.getByText('No alerts match your filters')).toBeInTheDocument();
  });
});
