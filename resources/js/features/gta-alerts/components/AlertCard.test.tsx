import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';
import { AlertCard } from './AlertCard';
import { AlertItem } from '../types';

describe('AlertCard', () => {
  const mockItem: AlertItem = {
    id: '1',
    title: 'Structure Fire',
    location: 'Main St',
    timeAgo: '5m ago',
    description: 'A test fire description',
    type: 'fire',
    severity: 'high',
    iconName: 'local_fire_department',
    accentColor: 'bg-red-500',
  };

  it('renders correctly with given item data', () => {
    render(<AlertCard item={mockItem} />);
    
    expect(screen.getByText('Structure Fire')).toBeInTheDocument();
    expect(screen.getByText(/Main St/)).toBeInTheDocument();
    expect(screen.getByText(/5m ago/)).toBeInTheDocument();
    expect(screen.getByText(/A test fire description/)).toBeInTheDocument();
  });

  it('calls onViewDetails when clicked', () => {
    const onViewDetails = vi.fn();
    render(<AlertCard item={mockItem} onViewDetails={onViewDetails} />);
    
    fireEvent.click(screen.getByRole('article'));
    expect(onViewDetails).toHaveBeenCalledTimes(1);
  });

  it('shows saved badge when isSaved is true', () => {
    render(<AlertCard item={mockItem} isSaved={true} />);
    expect(screen.getByText('SAVED')).toBeInTheDocument();
  });
});
