import { render, screen } from '@testing-library/react';
import { describe, it, expect } from 'vitest';
import { FeedView } from './FeedView';
import { AlertItem } from '../types';

describe('FeedView', () => {
  const mockAlerts: AlertItem[] = [
    {
      id: '1',
      title: 'Fire Alert',
      location: '123 Fire St',
      timeAgo: '2m ago',
      description: 'Fire description',
      type: 'fire',
      severity: 'high',
      iconName: 'fire',
      accentColor: 'red',
    },
    {
      id: '2',
      title: 'Police Alert',
      location: '456 Police Rd',
      timeAgo: '10m ago',
      description: 'Police description',
      type: 'police',
      severity: 'medium',
      iconName: 'shield',
      accentColor: 'blue',
    }
  ];

  it('renders a list of alerts', () => {
    render(
      <FeedView 
        searchQuery="" 
        onSelectAlert={() => {}} 
        allAlerts={mockAlerts} 
        latestFeedUpdatedAt={new Date().toISOString()}
      />
    );
    
    expect(screen.getByText('Fire Alert')).toBeInTheDocument();
    expect(screen.getByText('Police Alert')).toBeInTheDocument();
  });

  it('shows data freshness indicator when latestFeedUpdatedAt is provided', () => {
    render(
      <FeedView 
        searchQuery="" 
        onSelectAlert={() => {}} 
        allAlerts={mockAlerts} 
        latestFeedUpdatedAt={new Date().toISOString()}
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
      />
    );
    
    expect(screen.getByText('No alerts match your filters')).toBeInTheDocument();
  });
});
