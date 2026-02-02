import { describe, it, expect } from 'vitest';
import { AlertService } from './AlertService';
import { IncidentResource } from '../types';

describe('AlertService', () => {
  const mockIncident: IncidentResource = {
    id: 1,
    event_num: 'FA230001',
    event_type: 'STRUCTURE FIRE',
    prime_street: 'MAIN ST',
    cross_streets: 'CROSS RD',
    dispatch_time: new Date().toISOString(),
    alarm_level: 2,
    beat: 'B1',
    units_dispatched: 'P1, P2, A1',
    is_active: true,
    feed_updated_at: new Date().toISOString(),
  };

  it('maps a backend incident to an AlertItem correctly', () => {
    const alertItem = AlertService.mapIncidentToAlertItem(mockIncident);
    
    expect(alertItem.id).toBe('1');
    expect(alertItem.title).toBe('STRUCTURE FIRE');
    expect(alertItem.location).toBe('MAIN ST / CROSS RD');
    expect(alertItem.type).toBe('fire');
    expect(alertItem.severity).toBe('high');
    expect(alertItem.accentColor).toBe('bg-red-500');
    expect(alertItem.iconName).toBe('local_fire_department');
  });

  it('normalizes police types correctly', () => {
    const policeIncident = { ...mockIncident, event_type: 'POLICE INVESTIGATION', alarm_level: 0 };
    const alertItem = AlertService.mapIncidentToAlertItem(policeIncident);
    
    expect(alertItem.type).toBe('police');
    expect(alertItem.severity).toBe('low');
    expect(alertItem.accentColor).toBe('bg-blue-500');
  });

  it('filters items by search query', () => {
    const items = [
      AlertService.mapIncidentToAlertItem(mockIncident),
      AlertService.mapIncidentToAlertItem({ ...mockIncident, id: 2, event_type: 'GAS LEAK', prime_street: 'GAS ST' })
    ];
    
    const results = AlertService.search(items, {
      query: 'GAS',
      category: 'all',
      dateScope: 'all'
    });
    
    expect(results).toHaveLength(1);
    expect(results[0].title).toBe('GAS LEAK');
  });

  it('filters items by category', () => {
    const items = [
      AlertService.mapIncidentToAlertItem(mockIncident),
      AlertService.mapIncidentToAlertItem({ ...mockIncident, id: 2, event_type: 'GAS LEAK', prime_street: 'GAS ST' })
    ];
    
    const results = AlertService.search(items, {
      query: '',
      category: 'hazard',
      dateScope: 'all'
    });
    
    expect(results).toHaveLength(1);
    expect(results[0].title).toBe('GAS LEAK');
  });
});
