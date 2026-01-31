
import { ALERT_DATA } from '../constants';
import { AlertItem } from '../types';

export interface AlertFilterOptions {
  query: string;
  category: string;
  timeLimit?: number | null; // minutes, null means no limit
  dateScope?: 'today' | 'yesterday' | 'all';
}

/**
 * AlertService (OOP Singleton Pattern)
 * Encapsulates all data operations for the Alerts dashboard.
 */
export class AlertService {
  /**
   * Helper to parse "4m ago", "1h ago" into minutes for numeric sorting (FP helper)
   */
  private static parseTimeAgo(timeStr: string): number {
    const match = timeStr.match(/(\d+)([mh])/);
    if (!match) {
        // Handle "d" for days if added later
        const dayMatch = timeStr.match(/(\d+)d/);
        if (dayMatch) return parseInt(dayMatch[1], 10) * 1440;
        return 0; // default to now if parse fails
    }
    const val = parseInt(match[1], 10);
    const unit = match[2];
    return unit === 'h' ? val * 60 : val;
  }

  /**
   * Flattens the nested sectioned data into a single array (FP: flatMap)
   */
  static getAllItems(): AlertItem[] {
    return ALERT_DATA.flatMap(section => section.items);
  }

  /**
   * Finds a specific alert by ID
   */
  static getAlertById(id: string): AlertItem | undefined {
    return this.getAllItems().find(item => item.id === id);
  }

  /**
   * Core Search and Filter Engine (Functional Pipeline)
   * Handles multi-field searching, category filtering, and time-based sorting.
   */
  static search(options: AlertFilterOptions): AlertItem[] {
    let items = this.getAllItems();
    const { query, category, timeLimit, dateScope } = options;

    // 1. Sort by recency (Functional: sort)
    items = items.sort((a, b) => this.parseTimeAgo(a.timeAgo) - this.parseTimeAgo(b.timeAgo));

    // 2. Category Filter (Functional: filter)
    if (category !== 'all') {
      items = items.filter(item => item.type === category);
    }

    // 3. Time Limit Filter (Functional: filter)
    if (timeLimit) {
      items = items.filter(item => this.parseTimeAgo(item.timeAgo) <= timeLimit);
    }

    // 4. Date Scope Filter (Functional: filter)
    // Note: Since mock data is relative, we assume "Today" is < 24h (1440 mins)
    // "Yesterday" would be > 24h and < 48h.
    if (dateScope === 'today') {
      items = items.filter(item => this.parseTimeAgo(item.timeAgo) < 1440);
    } else if (dateScope === 'yesterday') {
      items = items.filter(item => {
        const mins = this.parseTimeAgo(item.timeAgo);
        return mins >= 1440 && mins < 2880;
      });
    }

    // 5. Multi-field Search Query (Functional: filter)
    const normalizedQuery = query.trim().toLowerCase();
    if (normalizedQuery) {
      items = items.filter(item => {
        return (
          item.title.toLowerCase().includes(normalizedQuery) ||
          item.description.toLowerCase().includes(normalizedQuery) ||
          item.location.toLowerCase().includes(normalizedQuery) ||
          item.id.toLowerCase().includes(normalizedQuery) ||
          item.type.toLowerCase().includes(normalizedQuery)
        );
      });
    }

    return items;
  }

  /**
   * Retrieves items for the "Saved" view
   */
  static getSavedItems(): AlertItem[] {
    // For now, returning a subset as "saved"
    const all = this.getAllItems();
    return [all[0], all[3]].filter(Boolean);
  }
}
