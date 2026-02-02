import { formatTimeAgo } from '@/lib/utils';
import type { AlertItem, IncidentResource } from '../types';

export interface AlertFilterOptions {
  query: string;
  category: string;
  timeLimit?: number | null; // minutes, null means no limit
  dateScope?: 'today' | 'yesterday' | 'all';
}

/**
 * AlertService
 * Encapsulates all data operations for the Alerts dashboard.
 * Refactored to be data-driven and support live backend resources.
 */
export class AlertService {
  /**
   * Helper to parse relative time strings (e.g. "4m ago", "1h ago") into minutes for numeric sorting
   */
  private static parseTimeAgo(timeStr: string): number {
    const match = timeStr.match(/(\d+)([mhd])/);
    if (!match) return 0;
    
    const val = parseInt(match[1], 10);
    const unit = match[2];
    
    if (unit === 'h') return val * 60;
    if (unit === 'd') return val * 1440;
    return val;
  }

  /**
   * Maps backend Incident resource to frontend AlertItem interface
   */
  static mapIncidentToAlertItem(incident: IncidentResource): AlertItem {
    const type = this.normalizeType(incident.event_type);
    const severity: AlertItem['severity'] = incident.alarm_level > 1 ? 'high' : (incident.alarm_level === 1 ? 'medium' : 'low');
    
    return {
      id: String(incident.id),
      title: incident.event_type,
      location: incident.prime_street + (incident.cross_streets ? ` / ${incident.cross_streets}` : ''),
      timeAgo: formatTimeAgo(incident.dispatch_time),
      description: `Event #${incident.event_num}. Units: ${incident.units_dispatched || 'None'}. Beat: ${incident.beat || 'N/A'}.`,
      type: type,
      severity: severity,
      iconName: this.getIconForType(type, incident.event_type),
      accentColor: this.getAccentColorForType(type, severity),
      metadata: {
        eventNum: incident.event_num,
        alarmLevel: incident.alarm_level,
        unitsDispatched: incident.units_dispatched,
        beat: incident.beat,
      },
    };
  }

  /**
   * Normalizes disparate event types into core categories
   */
  private static normalizeType(eventType: string): AlertItem['type'] {
    const et = eventType.toUpperCase();
    if (et.includes('FIRE') || et.includes('STRUCTURE')) return 'fire';
    if (et.includes('POLICE') || et.includes('COLLISION')) return 'police';
    if (et.includes('GAS') || et.includes('HAZARD') || et.includes('CHEMICAL')) return 'hazard';
    if (et.includes('TRANSIT') || et.includes('SUBWAY') || et.includes('BUS')) return 'transit';
    if (et.includes('MEDICAL') || et.includes('AMBULANCE')) return 'medical';
    return 'fire'; // Default to fire category for unknown CAD alerts
  }

  /**
   * Returns appropriate icon based on type and specific event metadata
   */
  private static getIconForType(type: AlertItem['type'], eventType: string): string {
    const et = eventType.toUpperCase();
    if (et.includes('GAS')) return 'warning';
    if (et.includes('COLLISION')) return 'car_crash';
    
    switch (type) {
      case 'fire': return 'local_fire_department';
      case 'police': return 'shield';
      case 'hazard': return 'warning';
      case 'transit': return 'train';
      case 'medical': return 'medical_services';
      default: return 'info';
    }
  }

  /**
   * Returns Tailwind accent color class based on type and severity
   */
  private static getAccentColorForType(type: AlertItem['type'], severity: string): string {
    if (severity === 'high') return 'bg-[#d8464f]';

    switch (type) {
      case 'fire': return 'bg-[#e2751f]';
      case 'police': return 'bg-blue-500';
      case 'hazard': return 'bg-[#feb457]';
      case 'transit': return 'bg-[#3d584b]';
      case 'medical': return 'bg-[#d8464f]';
      default: return 'bg-gray-500';
    }
  }

  /**
   * Core Search and Filter Engine
   * Filters a provided list of items based on user options.
   */
  static search(items: AlertItem[], options: AlertFilterOptions): AlertItem[] {
    let filtered = [...items];
    const { query, category, timeLimit, dateScope } = options;

    // 1. Sort by recency
    filtered = filtered.sort((a, b) => this.parseTimeAgo(a.timeAgo) - this.parseTimeAgo(b.timeAgo));

    // 2. Category Filter
    if (category !== 'all') {
      filtered = filtered.filter(item => item.type === category);
    }

    // 3. Time Limit Filter (Last X minutes)
    if (timeLimit) {
      filtered = filtered.filter(item => this.parseTimeAgo(item.timeAgo) <= timeLimit);
    }

    // 4. Date Scope Filter
    if (dateScope === 'today') {
      filtered = filtered.filter(item => this.parseTimeAgo(item.timeAgo) < 1440);
    } else if (dateScope === 'yesterday') {
      filtered = filtered.filter(item => {
        const mins = this.parseTimeAgo(item.timeAgo);
        return mins >= 1440 && mins < 2880;
      });
    }

    // 5. Multi-field Search Query
    const normalizedQuery = query.trim().toLowerCase();
    if (normalizedQuery) {
      filtered = filtered.filter(item => {
        return (
          item.title.toLowerCase().includes(normalizedQuery) ||
          item.description.toLowerCase().includes(normalizedQuery) ||
          item.location.toLowerCase().includes(normalizedQuery) ||
          item.id.toLowerCase().includes(normalizedQuery) ||
          item.type.toLowerCase().includes(normalizedQuery)
        );
      });
    }

    return filtered;
  }
}